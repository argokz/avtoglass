<?php

namespace App\Ai\Runtime;

use App\Ai\AssistantDialog;
use App\Domain\Assistant\ProcurementService;
use App\Domain\Assistant\ShoppingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class GenerateReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $turnId)
    {
        $connection = (string) config('ai.queue_connection', 'database');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw new \LogicException('AI requires a durable asynchronous queue connection.');
        }
        $this->onConnection($connection)->onQueue((string) config('ai.queue_name', 'ai'));
    }

    public function handle(AssistantDialog $dialog, ChatStore $store): void
    {
        $turn = $store->claim($this->turnId);
        if (! $turn) {
            return;
        }
        $started = hrtime(true);
        app(RuntimeMetrics::class)->begin();
        Log::info('ai.turn.started', ['queue_ms' => max(0, (int) now()->diffInMilliseconds($turn->created_at, true))]);
        $previousLocale = app()->getLocale();
        try {
            $conversation = Conversation::query()->findOrFail($turn->conversation_id);
            $buyer = $conversation->buyer(); // Refresh privileges at execution time.
            app()->setLocale($buyer->locale);
            $context = Schema::hasTable('assistant_sessions') ? app(ShoppingService::class)->context($conversation, $buyer) : [];
            if (($context['lines'] ?? []) !== [] && ! array_key_exists('candidates', $context['lines'][0])) {
                app(ProcurementService::class)->replace($buyer, $conversation->id, $context['lines']);
                $store->write($this->turnId, $buyer->locale === 'kk'
                    ? 'Тізім сақталды. Әр жол үшін артикул мен өндірушіні тексеріп, нақты нұсқаны таңдаңыз. Табылмаған жолдар сақталады.'
                    : 'Список сохранён. Проверьте артикул и производителя каждой строки и выберите конкретный вариант. Ненайденные строки сохраняются.', 'completed');

                return;
            }
            $buffer = '';
            $lastWrite = 0.0;
            $reply = $dialog->reply($store->history($turn), $buyer,
                function (string $delta) use ($store, &$buffer, &$lastWrite): void {
                    $buffer .= $delta;
                    if (microtime(true) - $lastWrite >= 0.2) {
                        $store->write($this->turnId, $buffer);
                        $lastWrite = microtime(true);
                    }
                },
                fn (): bool => ! $store->active($this->turnId),
                $this->turnId,
                fn (string $name, array $args, array $result) => $store->recordToolResult($this->turnId, $name, $args, $result),
                $context,
            );
            $store->write($this->turnId, $reply, 'completed');
        } catch (Throwable $e) {
            // Raw provider exceptions can contain credentials or customer data.
            Log::warning('ai.turn.failed', ['turn_id' => $this->turnId, 'exception_type' => $e::class]);
            $store->write($this->turnId, null, 'failed', 'provider_unavailable');
        } finally {
            Log::info('ai.turn.finished', ['duration_ms' => (int) ((hrtime(true) - $started) / 1e6), ...app(RuntimeMetrics::class)->finish()]);
            app()->setLocale($previousLocale);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ChatStore::class)->write($this->turnId, null, 'failed', 'worker_failed');
    }
}
