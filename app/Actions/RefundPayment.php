<?php

namespace App\Actions;

use App\Catalog\StockLedger;
use App\Enums\PaymentStatus;
use App\Enums\ReceiptStatus;
use App\Fiscal\WebkassaClient;
use App\Models\Interaction;
use App\Models\Payment;
use App\Models\Receipt;
use App\Providers\PaymentServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Возврат оплаты.
 *
 * Сначала касса банка, потом статус в заявке. Иначе менеджер видит
 * «возвращён», а деньги так и лежат на счёте. Чек ОФД — отдельная операция.
 */
class RefundPayment
{
    public function __construct(private readonly WebkassaClient $webkassa) {}

    public function handle(Payment $payment): bool
    {
        if ($payment->status !== PaymentStatus::Succeeded) {
            return false;
        }

        if (! $this->refundAtGateway($payment)) {
            return false;
        }

        return DB::transaction(function () use ($payment) {
            $payment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();

            if ($payment === null || $payment->status !== PaymentStatus::Succeeded) {
                return false;
            }

            $payment->update(['status' => PaymentStatus::Refunded]);

            Interaction::system(
                $payment->order,
                'Возврат: '.number_format((float) $payment->amount, 0, ',', ' ').' ₸'
            );

            $this->releaseStockIfUnpaid($payment);
            $this->scheduleReturnReceipt($payment);

            return true;
        });
    }

    /**
     * Наличные уже в кассе — банк не трогаем.
     * Онлайн: драйвер того платежа, которым платили, а не текущий PAYMENT_DRIVER.
     */
    private function refundAtGateway(Payment $payment): bool
    {
        if ($payment->isCash() || ! filled($payment->provider)) {
            return true;
        }

        try {
            $gateway = PaymentServiceProvider::driver($payment->provider);
        } catch (\RuntimeException $e) {
            Log::warning('Возврат: неизвестный драйвер', [
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
            ]);

            return false;
        }

        return $gateway->refund($payment);
    }

    /**
     * Полный возврат снимает бронь: денег больше нет, держать стекло
     * бессрочно — значит показать витрине «нет», пока заявка жива.
     *
     * Частичный не трогаем: по заказу ещё есть успешный платёж, клиент
     * стекло не отпускал.
     */
    private function releaseStockIfUnpaid(Payment $payment): void
    {
        $order = $payment->order()->first();

        if ($order === null || $order->isPaid()) {
            return;
        }

        app(StockLedger::class)->release($order, 'возврат оплаты');
    }

    private function scheduleReturnReceipt(Payment $payment): void
    {
        if (! settings()->enabled('fiscal')) {
            return;
        }

        $sale = $payment->receipt()->where('status', ReceiptStatus::Issued)->first();

        if ($sale === null) {
            return;
        }

        $receipt = Receipt::create([
            'order_id' => $payment->order_id,
            'payment_id' => $payment->id,
            'status' => ReceiptStatus::Pending,
            'amount' => $payment->amount,
        ]);

        $result = $this->webkassa->refund($receipt, $sale);

        if ($result === null) {
            $receipt->update([
                'status' => ReceiptStatus::Failed,
                'error' => 'Касса не приняла чек возврата',
            ]);

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
            $payment->order,
            'Чек возврата выбит: № '.$result['check_number']
        );
    }
}
