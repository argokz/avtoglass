<?php

namespace App\Http\Controllers;

use App\Actions\ApplyPaymentCallback;
use App\Actions\StartPayment;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\Gateways\FreedomPayGateway;
use App\Payments\Gateways\HalykEpayGateway;
use App\Payments\Gateways\TestGateway;
use App\Payments\PaymentGateway;
use App\Providers\PaymentServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentController extends Controller
{
    public function __construct(
        private readonly StartPayment $start,
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * Начало оплаты. Заказ берётся из сессии, а не из адреса: номера идут
     * подряд, и по /oplata/АС-000124 любой желающий оплачивал бы чужие заказы
     * или, что хуже, просматривал их суммы.
     */
    public function start(Request $request)
    {
        $order = $this->orderFromSession($request);

        abort_if($order === null, 404);

        $driver = $order->payment_method === PaymentMethod::Card ? 'halyk' : null;
        $url = $this->start->begin($order, $driver);

        if ($url === null) {
            return redirect()->route('order.thanks')
                ->with('payment_error', 'Этот заказ сейчас нельзя оплатить онлайн');
        }

        return redirect()->away($url);
    }

    /**
     * Страница «кассы». Существует только у тестового драйвера — у боевого
     * здесь был бы домен банка.
     */
    public function mock(Payment $payment)
    {
        abort_unless($this->gateway instanceof TestGateway, 404);

        if (! $payment->isPayable()) {
            return redirect()->route('payment.result', $payment);
        }

        $gateway = $this->gateway;

        $link = function (string $status) use ($payment, $gateway) {
            $data = [
                'token' => $payment->token,
                'status' => $status,
                'amount' => (string) $payment->amount,
                'external_id' => 'test-'.$payment->id,
            ];

            return route('payment.callback', $data + ['signature' => $gateway->sign($data)]);
        };

        return view('payment.mock', [
            'payment' => $payment,
            'links' => [
                'success' => $link('success'),
                'fail' => $link('fail'),
                'cancel' => $link('cancel'),
            ],
        ]);
    }

    /**
     * Уведомление кассы. Открыто наружу и без сессии — защищает только подпись.
     */
    public function callback(Request $request, ApplyPaymentCallback $apply)
    {
        $result = $this->gateway->parseCallback($request);

        if ($result === null) {
            // Подпись не сошлась: либо чужой запрос, либо подделка.
            // Наружу подробностей не сообщаем.
            return response('Invalid signature', Response::HTTP_BAD_REQUEST);
        }

        $payment = Payment::where('token', $result->token)->first();

        if ($payment === null) {
            return response('Unknown payment', Response::HTTP_NOT_FOUND);
        }

        $apply->handle($payment, $result);

        return redirect()->route('payment.result', $payment);
    }

    /**
     * Страница с виджетом Halyk. Токен получаем здесь, на сервере:
     * он привязан к сумме и номеру счёта, и отдавать ключи мерчанта
     * в браузер нельзя.
     */
    public function halyk(Payment $payment, ApplyPaymentCallback $apply)
    {
        $gateway = PaymentServiceProvider::driver('halyk');

        abort_unless($gateway instanceof HalykEpayGateway, 404);
        abort_unless($payment->provider === 'halyk', 404);

        $payment->loadMissing('order.customer');

        if (! $payment->isPayable()) {
            return redirect()->route('payment.result', $payment);
        }

        $auth = $gateway->authorize($payment);

        if ($auth === null) {
            // Банк не выдал токен — платить нечем. Молча показывать пустую
            // страницу нельзя: клиент решит, что оплатил.
            return redirect()->route('payment.result', $payment)
                ->with('payment_error', 'Касса недоступна, попробуйте позже');
        }

        return view('payment.halyk', [
            'payment' => $payment,
            'payload' => $gateway->widgetPayload($payment, $auth),
            'script' => $gateway->widgetScript(),
        ]);
    }

    /**
     * postLink от Halyk: банк шлёт его сервером на сервер и ждёт короткий ответ.
     * Редиректить в ответ нельзя — на той стороне никто по нему не пойдёт.
     */
    public function halykCallback(Request $request, ApplyPaymentCallback $apply)
    {
        $result = PaymentServiceProvider::driver('halyk')->parseCallback($request);

        if ($result === null) {
            return response()->json(['status' => 'invalid'], Response::HTTP_BAD_REQUEST);
        }

        $payment = Payment::where('token', $result->token)->first();

        if ($payment === null) {
            return response()->json(['status' => 'unknown'], Response::HTTP_NOT_FOUND);
        }

        $apply->handle($payment, $result);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Уведомление Freedom Pay. Отвечать надо подписанным XML: без него банк
     * считает, что магазин уведомление не принял, и присылает его снова.
     */
    public function freedomPayCallback(Request $request, ApplyPaymentCallback $apply)
    {
        abort_unless($this->gateway instanceof FreedomPayGateway, 404);

        $result = $this->gateway->parseCallback($request);

        if ($result === null) {
            return response($this->gateway->callbackResponse('error'), Response::HTTP_BAD_REQUEST)
                ->header('Content-Type', 'text/xml');
        }

        $payment = Payment::where('token', $result->token)->first();

        if ($payment !== null) {
            $apply->handle($payment, $result);
        }

        return response($this->gateway->callbackResponse('ok'))
            ->header('Content-Type', 'text/xml');
    }

    /** Что показать клиенту после возврата из кассы. */
    public function result(Payment $payment)
    {
        return view('payment.result', ['payment' => $payment->load('order')]);
    }

    private function orderFromSession(Request $request): ?Order
    {
        $number = $request->session()->get('last_order_number');

        return $number ? Order::where('number', $number)->first() : null;
    }
}
