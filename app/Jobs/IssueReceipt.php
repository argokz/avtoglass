<?php

namespace App\Jobs;

use App\Enums\ReceiptStatus;
use App\Fiscal\WebkassaClient;
use App\Models\Interaction;
use App\Models\Receipt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Выбивает фискальный чек по оплаченному заказу.
 *
 * Главное свойство — не мешать оплате. Касса ОФД падает и тормозит чаще
 * банка, и если фискализация будет частью подтверждения платежа, то каждый
 * её сбой превратится в «клиент заплатил, а заказ не подтвердился».
 * Поэтому чек выбивается отдельной задачей, а её неудача остаётся
 * проблемой бухгалтерии, а не покупателя.
 *
 * Второе свойство — задача обязана вернуться. Исключение наружу мы не пускаем
 * (при синхронной очереди оно всплыло бы в запрос, который уже отдал клиенту
 * «оплачено»), поэтому повтор запрашиваем сами через release(): без этого
 * очередь считала бы задачу успешной, и чек «падал» бы навсегда с первой же
 * сетевой заминки.
 */
class IssueReceipt implements ShouldQueue
{
    use Queueable;

    /**
     * Интервалы повторов растут: касса чаще лежит недолго.
     * Последнее значение используется и для всех попыток сверх списка.
     */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public Receipt $receipt) {}

    public function handle(WebkassaClient $client): void
    {
        $receipt = $this->receipt->fresh();

        if ($receipt === null || $receipt->isIssued()) {
            return;   // уже выбит, повтор не нужен
        }

        $receipt->increment('attempts');

        try {
            $result = $client->issue($receipt);
        } catch (\Throwable $e) {
            $this->fail($receipt, $e->getMessage());

            return;
        }

        if ($result === null) {
            $this->fail($receipt, 'Касса не вернула номер чека');

            return;
        }

        $receipt->update([
            'status' => ReceiptStatus::Issued,
            'check_number' => $result['check_number'],
            'ticket_url' => $result['ticket_url'],
            'fiscal_at' => $result['datetime'] ? now()->parse($result['datetime']) : now(),
            'payload' => $result['raw'],
            'error' => null,
        ]);

        Interaction::system(
            $receipt->order,
            'Фискальный чек выбит: № '.$result['check_number']
        );
    }

    private function fail(Receipt $receipt, string $error): void
    {
        $receipt->update([
            'status' => ReceiptStatus::Failed,
            'error' => mb_substr($error, 0, 250),
        ]);

        Log::warning('Не удалось выбить фискальный чек', [
            'receipt_id' => $receipt->id,
            'order' => $receipt->order->number,
            'attempts' => $receipt->attempts,
            'error' => $error,
        ]);

        if (! $receipt->fresh()->needsAttention()) {
            // Попытки ещё есть — просим очередь вернуть задачу позже.
            // Считаем по attempts самого чека, а не по attempts() задачи:
            // чек могли перевыставить руками, и счётчик у него честнее.
            $this->release($this->retryDelay($receipt->attempts));

            return;
        }

        // Попытки кончились — это уже не техническая заминка,
        // а чек, которого нет у налоговой. Человек должен узнать.
        Interaction::system(
            $receipt->order,
            'Чек не выбит после '.$receipt->attempts.' попыток: '.mb_substr($error, 0, 120)
        );
    }

    /** Пауза перед следующей попыткой; после списка — его последний интервал. */
    public function retryDelay(int $attempts): int
    {
        return $this->backoff[$attempts - 1] ?? end($this->backoff);
    }
}
