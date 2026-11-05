<?php

namespace App\Actions;

use App\Domain\Ordering\PurchaseLifecycle;
use App\Domain\Pricing\Money;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\PaymentGateway;
use App\Providers\PaymentServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Создание попытки оплаты.
 *
 * Сумма фиксируется здесь и больше не пересчитывается: прайсы переливаются
 * еженедельно, и клиент должен заплатить ровно ту сумму, которую видел,
 * а не ту, что окажется в базе к моменту нажатия кнопки.
 */
class StartPayment
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function handle(Order $order, ?PaymentGateway $gateway = null): ?Payment
    {
        $gateway ??= $this->gateway;

        /*
         * Заказ блокируется на всё время проверки и создания.
         *
         * Без этого два одновременных нажатия «оплатить» — двойной клик,
         * две вкладки, повторная отправка формы — оба не находят незакрытой
         * попытки и оба заводят свою. Дальше каждый колбэк блокирует только
         * свой платёж, оба проходят проверку и оба применяются: клиент
         * заплатил дважды за один заказ.
         *
         * Блокировка именно на заказе, а не на платеже: платежа, из-за
         * которого всё и ломается, в этот момент ещё не существует.
         */
        return DB::transaction(function () use ($order, $gateway) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $this->isPayable($locked)) {
                return null;
            }

            // Незакрытую попытку переиспользуем: клиент мог вернуться назад
            // и нажать «оплатить» второй раз — плодить платежи из-за этого не нужно.
            $pending = $locked->payments()->pending()->latest('id')->first();

            if ($pending !== null && $pending->isPayable()) {
                if (Money::fromDecimal($pending->amount)->decimal() === Money::fromDecimal($locked->total_amount)->decimal()
                    && $pending->currency === ($locked->currency ?: 'KZT') && $pending->provider === $gateway->name()) {
                    return $pending;
                }

                // An unresolved bank attempt must not be duplicated through another provider.
                return null;
            }

            return $locked->payments()->create([
                'provider' => $gateway->name(),
                'status' => PaymentStatus::Pending,
                'amount' => $locked->total_amount,
                'currency' => $locked->currency ?: 'KZT',
            ]);
        });
    }

    public function redirectUrl(Payment $payment): ?string
    {
        return PaymentServiceProvider::driver($payment->provider)->redirectUrl($payment);
    }

    /**
     * Создать платёж и вернуть адрес кассы. null — платить сейчас нельзя.
     */
    public function begin(Order $order, ?string $driver = null): ?string
    {
        $gateway = $driver ? PaymentServiceProvider::driver($driver) : $this->gateway;
        $payment = $this->handle($order, $gateway);

        return $payment ? $gateway->redirectUrl($payment) : null;
    }

    /** После заявки: онлайн-способ — сразу в свою кассу, иначе страница «спасибо». */
    public function redirectAfterOrder(Order $order): RedirectResponse
    {
        session(['last_order_number' => $order->number]);

        if ($order->purchase_draft_id) {
            return redirect()->route('purchase.show', $order->public_token);
        }

        $driver = $order->payment_method?->driver();

        if ($driver !== null) {
            $url = $this->begin($order, $driver);

            if ($url !== null) {
                return redirect()->away($url);
            }
        }

        return redirect()->route('order.thanks');
    }

    /** Платить можно за живой заказ с посчитанной суммой и без уже прошедшей оплаты. */
    public function isPayable(Order $order): bool
    {
        if ($order->purchase_draft_id && ($order->purchase_state !== 'confirmed'
            || ($order->risk_status && $order->risk_status->value !== 'allow')
            || ! app(PurchaseLifecycle::class)->fullyReserved($order))) {
            return false;
        }
        if (! settings()->enabled('payments')) {
            return false;
        }

        if ($order->status->isFinal()) {
            return false;
        }

        if ($order->total_amount === null || (float) $order->total_amount <= 0) {
            return false;
        }

        return ! $order->isPaid();
    }
}
