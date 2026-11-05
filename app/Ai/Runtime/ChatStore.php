<?php

namespace App\Ai\Runtime;

use App\Ai\Models\AiCheckout;
use App\Domain\Assistant\ShoppingService;
use App\Domain\Identity\BuyerContext;
use App\Models\AiExchangeLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ChatStore
{
    public function conversation(BuyerContext $buyer, ?string $id = null): Conversation
    {
        $owned = Conversation::query()->where('owner_key', ChatIdentity::ownerKey($buyer));
        $chat = $id ? (clone $owned)->find($id) : null;

        return $chat ?? $owned->orderByDesc('updated_at')->orderByDesc('id')->first() ?? $this->create($buyer);
    }

    public function create(BuyerContext $buyer): Conversation
    {
        return Conversation::query()->create([
            'owner_key' => ChatIdentity::ownerKey($buyer),
            'id' => (string) Str::uuid(), 'actor_id' => $buyer->actorId,
            'guest_token' => $buyer->actorId === null ? ChatIdentity::guestToken() : null,
            'locale' => $buyer->locale,
        ]);
    }

    public function delete(BuyerContext $buyer, string $id): void
    {
        DB::transaction(function () use ($buyer, $id) {
            $chat = Conversation::query()->where('owner_key', ChatIdentity::ownerKey($buyer))->lockForUpdate()->findOrFail($id);
            $operations = DB::table('ai_turns')->where('conversation_id', $id)->pluck('operation_id');
            AiCheckout::query()->whereIn('turn_id', $operations)->delete();
            if (Schema::hasTable('ai_exchange_logs')) {
                AiExchangeLog::query()->where('conversation_id', $id)->delete();
            }
            $chat->delete(); // FK cascades remove messages, turns and catalogue snapshots.
        });
    }

    /** Lock the conversation first everywhere, so cancel, retry and callbacks serialize. */
    public function enqueue(Conversation $conversation, string $text, bool $retry = false): ?string
    {
        return DB::transaction(function () use ($conversation, $text, $retry) {
            $chat = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->expireLocked($chat);
            if ($chat->active_turn_id !== null) {
                return null;
            }
            $id = (string) Str::uuid();
            $now = now();
            if ($retry) {
                $last = DB::table('ai_turns')->where('conversation_id', $chat->id)->orderByDesc('model_message_id')->orderByDesc('attempt')->first();
                if (! $last || ! in_array($last->status, ['failed', 'cancelled'], true)) {
                    return null;
                }
                $userId = $last->user_message_id;
                $modelId = $last->model_message_id;
                // A failed or cancelled generation cannot lend tool facts to its retry.
                DB::table('ai_tool_results')->where('conversation_id', $chat->id)
                    ->whereIn('turn_id', DB::table('ai_turns')->select('id')->where('operation_id', $last->operation_id))
                    ->delete();
                DB::table('ai_messages')->where('id', $modelId)->update(['turn_id' => $id, 'text' => '', 'updated_at' => $now]);
            } else {
                $userId = DB::table('ai_messages')->insertGetId(['conversation_id' => $chat->id, 'turn_id' => $id, 'role' => 'user', 'text' => MessageText::encode($text), 'created_at' => $now, 'updated_at' => $now]);
                $modelId = DB::table('ai_messages')->insertGetId(['conversation_id' => $chat->id, 'turn_id' => $id, 'role' => 'model', 'text' => '', 'created_at' => $now, 'updated_at' => $now]);
                if (Schema::hasTable('assistant_sessions')) {
                    app(ShoppingService::class)->accept($chat, $text);
                }
            }
            DB::table('ai_turns')->insert([
                'id' => $id, 'conversation_id' => $chat->id, 'user_message_id' => $userId, 'model_message_id' => $modelId,
                'operation_id' => $retry ? $last->operation_id : $id, 'attempt' => $retry ? $last->attempt + 1 : 1,
                'status' => 'queued', 'deadline_at' => now()->addSeconds((int) config('ai.queue_wait_seconds', 120)),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $chat->update([
                'active_turn_id' => $id, 'locale' => $conversation->locale,
                'title' => $chat->title ?? mb_substr($text, 0, 80),
            ]);

            return $id;
        });
    }

    public function recover(Conversation $conversation): void
    {
        DB::transaction(function () use ($conversation) {
            if ($chat = Conversation::query()->lockForUpdate()->find($conversation->id)) {
                $this->expireLocked($chat);
            }
        });
    }

    private function expireLocked(Conversation $chat): void
    {
        if (! $chat->active_turn_id) {
            return;
        }
        $turn = DB::table('ai_turns')->where('id', $chat->active_turn_id)->first();
        if ($turn && now()->greaterThanOrEqualTo($turn->deadline_at)) {
            DB::table('ai_turns')->where('id', $turn->id)->update(['status' => 'failed', 'error_code' => $turn->status === 'queued' ? 'worker_unavailable' : 'generation_timeout', 'updated_at' => now()]);
            $chat->update(['active_turn_id' => null]);
        }
    }

    public function claim(string $turnId): ?object
    {
        $turn = DB::table('ai_turns')->where('id', $turnId)->first();
        if (! $turn) {
            return null;
        }

        return DB::transaction(function () use ($turnId, $turn) {
            $chat = Conversation::query()->lockForUpdate()->find($turn->conversation_id);
            if (! $chat) {
                return null;
            }
            $this->expireLocked($chat);
            $turn = DB::table('ai_turns')->where('id', $turnId)->first();
            if ($chat->active_turn_id !== $turnId || $turn->status !== 'queued') {
                return null;
            }
            DB::table('ai_turns')->where('id', $turnId)->update(['status' => 'running', 'deadline_at' => now()->addSeconds((int) config('ai.turn_timeout_seconds', 45)), 'updated_at' => now()]);

            return $turn;
        });
    }

    public function active(string $turnId): bool
    {
        return DB::table('ai_turns')->join('ai_conversations', 'ai_conversations.id', '=', 'ai_turns.conversation_id')
            ->where('ai_turns.id', $turnId)->where('ai_conversations.active_turn_id', $turnId)
            ->whereIn('ai_turns.status', ['queued', 'running'])->where('ai_turns.deadline_at', '>', now())->exists();
    }

    public function write(string $turnId, ?string $text = null, ?string $status = null, ?string $error = null): void
    {
        $turn = DB::table('ai_turns')->where('id', $turnId)->first();
        if (! $turn) {
            return;
        }
        $finished = false;
        DB::transaction(function () use ($turn, $turnId, $text, $status, $error, &$finished) {
            $chat = Conversation::query()->lockForUpdate()->find($turn->conversation_id);
            if (! $chat) {
                return;
            }
            $this->expireLocked($chat);
            if ($chat->active_turn_id !== $turnId) {
                return;
            }
            if ($text !== null) {
                DB::table('ai_messages')->where('id', $turn->model_message_id)->where('turn_id', $turnId)->update(['text' => MessageText::encode($text), 'updated_at' => now()]);
            }
            if ($status !== null) {
                DB::table('ai_turns')->where('id', $turnId)->update(['status' => $status, 'error_code' => $error, 'updated_at' => now()]);
                $chat->update(['active_turn_id' => null]);
                $finished = true;
            }
        });
        if ($finished) {
            try {
                AiExchangeLog::recordTurn($turnId);
            } catch (\Throwable) {
                // Журнал разбора не должен срывать уже сохранённый ответ.
            }
        }
    }

    public function recordToolResult(string $turnId, string $name, array $args, array $result): void
    {
        $snapshot = ToolResultSnapshot::make($name, $args, $result);
        if ($snapshot === null) {
            return;
        }
        $turn = DB::table('ai_turns')->where('id', $turnId)->first();
        if (! $turn) {
            return;
        }
        DB::transaction(function () use ($turn, $turnId, $name, $snapshot) {
            $chat = Conversation::query()->lockForUpdate()->find($turn->conversation_id);
            if (! $chat) {
                return;
            }
            $this->expireLocked($chat);
            if ($chat->active_turn_id !== $turnId || ! DB::table('ai_turns')->where('id', $turnId)->where('status', 'running')->exists()) {
                return;
            }
            if ($name === 'search_products' && Schema::hasTable('assistant_sessions')) {
                app(ShoppingService::class)->searched($chat, $snapshot['args'], $snapshot['result']);
            }
            // Tool steps and response fields are bounded independently of the provider payload.
            if (DB::table('ai_tool_results')->where('turn_id', $turnId)->count() >= 9) {
                return;
            }
            while (strlen(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 10000 && count($snapshot['result']['items'] ?? []) > 0) {
                array_pop($snapshot['result']['items']);
            }
            DB::table('ai_tool_results')->insert([
                'conversation_id' => $chat->id, 'turn_id' => $turnId, 'name' => $name,
                'arguments' => ToolPayload::encode($snapshot['args']),
                'result' => ToolPayload::encode($snapshot['result']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function history(object $turn): array
    {
        $messages = DB::table('ai_messages as m')->leftJoin('ai_turns as t', 't.id', '=', 'm.turn_id')
            ->where('m.conversation_id', $turn->conversation_id)->where('m.id', '<=', $turn->user_message_id)
            ->where(fn ($query) => $query->where('m.role', 'user')->orWhere('t.status', 'completed'))
            ->orderByDesc('m.id')->limit(40)->get(['m.id', 'm.role', 'm.text'])->reverse()->values();
        $completed = DB::table('ai_turns')->where('conversation_id', $turn->conversation_id)
            ->whereIn('user_message_id', $messages->where('role', 'user')->pluck('id'))
            ->where('status', 'completed')->orderByDesc('attempt')->get()
            ->unique('user_message_id')->keyBy('user_message_id');
        $toolRows = DB::table('ai_tool_results')->where('conversation_id', $turn->conversation_id)
            ->whereIn('turn_id', $completed->pluck('id'))->orderBy('id')->get()->groupBy('turn_id');
        $checkouts = AiCheckout::query()->whereIn('turn_id', $completed->pluck('operation_id'))
            ->get(['turn_id', 'status', 'result'])->keyBy('turn_id');

        $history = [];
        foreach ($messages as $message) {
            $history[] = ['role' => $message->role, 'text' => MessageText::decode($message->text)];
            if ($message->role !== 'user' || ! ($past = $completed->get($message->id))) {
                continue;
            }
            $tools = ($toolRows->get($past->id) ?? collect())->map(fn ($row) => [
                'name' => $row->name,
                'args' => ToolPayload::decode($row->arguments),
                'result' => ToolPayload::decode($row->result),
            ])->all();
            if ($checkout = $checkouts->get($past->operation_id)) {
                $number = $checkout->result['order_number'] ?? null;
                if ($checkout->status === 'submitted' && is_string($number)) {
                    $tools[] = ['name' => 'prepare_order', 'args' => [], 'result' => [
                        'status' => 'submitted', 'order_number' => mb_substr($number, 0, 60),
                    ]];
                }
            }
            if ($tools !== []) {
                $history[] = ['role' => 'tool_context', 'tools' => $tools];
            }
        }

        return $history;
    }
}
