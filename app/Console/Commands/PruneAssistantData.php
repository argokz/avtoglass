<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Models\AiCheckout;
use App\Ai\Runtime\Conversation;
use App\Domain\Assistant\ShoppingSession;
use App\Domain\Ordering\Models\OrderDraft;
use App\Models\AiExchangeLog;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PruneAssistantData extends Command
{
    protected $signature = 'assistant:prune {--dry-run}';

    protected $description = 'Удалить неактивные диалоги по сроку хранения, сохраняя заказы и незакрытые обращения';

    public function handle(): int
    {
        if (! Schema::hasTable('assistant_sessions')) {
            return self::SUCCESS;
        }
        $count = 0;
        Conversation::query()->whereNull('active_turn_id')->where(fn ($q) => $q
            ->where(fn ($g) => $g->whereNull('actor_id')->where('updated_at', '<', now()->subDays((int) config('ai.retention.guest_days', 30))))
            ->orWhere(fn ($a) => $a->whereNotNull('actor_id')->where('updated_at', '<', now()->subDays((int) config('ai.retention.account_days', 180)))))
            ->orderBy('id')->chunkById(100, function ($chats) use (&$count) {
                foreach ($chats as $chat) {
                    DB::transaction(function () use ($chat, &$count) {
                        $locked = Conversation::query()->whereKey($chat->id)->lockForUpdate()->first();
                        if (! $locked || $locked->active_turn_id || $locked->updated_at->ne($chat->updated_at)) {
                            return;
                        }
                        $state = ShoppingSession::query()->find($chat->id)?->state ?? [];
                        if (! empty($state['manager_task_id']) && Task::query()->whereKey($state['manager_task_id'])->open()->exists()) {
                            return;
                        }
                        $operations = DB::table('ai_turns')->where('conversation_id', $chat->id)->pluck('operation_id');
                        $orderIds = AiCheckout::query()->whereIn('turn_id', $operations)->where('status', 'submitted')->get()->map(fn ($checkout) => $checkout->result['order_id'] ?? null)->filter()->all();
                        if ($orderIds && Task::query()->whereIn('order_id', $orderIds)->open()->exists()) {
                            return;
                        }
                        $count++;
                        if (! $this->option('dry-run')) {
                            AiCheckout::query()->whereIn('turn_id', $operations)->delete();
                            AiExchangeLog::query()->where('conversation_id', $chat->id)->delete();
                            $locked->delete();
                        }
                    });
                }
            });
        $this->info('Диалогов: '.$count.($this->option('dry-run') ? ' (без удаления)' : ''));
        // Unsubmitted drafts left after history deletion are not accounting records.
        $drafts = OrderDraft::query()->where('status', 'active')
            ->where(fn ($q) => $q->where(fn ($guest) => $guest->whereNull('customer_id')->where('updated_at', '<', now()->subDays((int) config('ai.retention.guest_days', 30))))
                ->orWhere(fn ($account) => $account->whereNotNull('customer_id')->where('updated_at', '<', now()->subDays((int) config('ai.retention.account_days', 180)))))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('assistant_sessions')->whereColumn('assistant_sessions.draft_id', 'order_drafts.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('orders')->whereColumn('orders.purchase_draft_id', 'order_drafts.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_proposals')->whereColumn('order_proposals.draft_id', 'order_drafts.id'));
        if (! $this->option('dry-run')) {
            $drafts->chunkById(100, function ($rows) {
                foreach ($rows as $draft) {
                    DB::transaction(function () use ($draft) {
                        $locked = OrderDraft::query()->whereKey($draft->id)->lockForUpdate()->first();
                        if ($locked && $locked->status === 'active' && $locked->updated_at->eq($draft->updated_at)
                            && ! ShoppingSession::query()->where('draft_id', $draft->id)->exists()) {
                            $locked->delete();
                        }
                    });
                }
            });
        }

        return self::SUCCESS;
    }
}
