<?php

namespace App\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * Контракт платёжной системы.
 *
 * Намеренно узкий: всё, что нужно магазину, — получить ссылку на оплату
 * и уметь разобрать то, что касса пришлёт обратно. Всё остальное (форма карты,
 * 3-D Secure, антифрод) происходит на стороне кассы и нас не касается.
 */
interface PaymentGateway
{
    /** Имя драйвера, оно же попадает в payments.provider. */
    public function name(): string;

    /**
     * Куда отправить клиента, чтобы он заплатил.
     *
     * null — кассу поднять не удалось. У драйверов, которые перед показом
     * формы ходят в банк за ссылкой, это штатный исход, и молча отдавать
     * битый URL нельзя: клиент решит, что оплатил.
     */
    public function redirectUrl(Payment $payment): ?string;

    /**
     * Разбирает уведомление кассы.
     * Возвращает null, если уведомлению нельзя верить — подпись не сошлась.
     */
    public function parseCallback(Request $request): ?CallbackResult;
}
