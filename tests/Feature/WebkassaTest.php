<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\ReceiptStatus;
use App\Fiscal\WebkassaClient;
use App\Jobs\IssueReceipt;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use App\Payments\Gateways\TestGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Фискализация через Webkassa.
 *
 * Главное, что проверяется здесь, — что касса ОФД не может испортить оплату.
 * Она падает чаще банка, и если чек будет частью подтверждения платежа,
 * каждый её сбой станет «клиент заплатил, а заказ не подтвердился».
 */
class WebkassaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fiscal.enabled' => true,
            'fiscal.webkassa.login' => 'demo@webkassa.kz',
            'fiscal.webkassa.password' => 'secret',
            'fiscal.webkassa.cashbox' => 'SWK00000001',
            'fiscal.webkassa.test_mode' => true,
        ]);

        Cache::flush();
    }

    private function paidOrder(float $amount = 27000): Payment
    {
        $order = Order::factory()->create(['total_amount' => $amount]);
        $order->items()->create(['name' => 'Лобовое стекло Camry', 'price' => $amount, 'quantity' => 1]);

        return Payment::factory()->for($order)->create(['amount' => $amount]);
    }

    private function callbackUrl(Payment $payment, string $status = 'success'): string
    {
        $gateway = app(TestGateway::class);

        $data = [
            'token' => $payment->token,
            'status' => $status,
            'amount' => (string) $payment->amount,
            'external_id' => 'test-'.$payment->id,
        ];

        return route('payment.callback', $data + ['signature' => $gateway->sign($data)]);
    }

    private function fakeWebkassa(array $checkData = []): void
    {
        Http::fake([
            '*/api/Authorize' => Http::response(['Data' => ['Token' => 'WK-TOKEN']]),
            '*/api/Check' => Http::response(['Data' => array_merge([
                'CheckNumber' => '000123',
                'TicketUrl' => 'https://devkkm.webkassa.kz/t/000123',
                'DateTime' => '2026-08-27 12:00:00',
            ], $checkData)]),
        ]);
    }

    // ------------------------------------------------------------------
    // связь с оплатой
    // ------------------------------------------------------------------

    public function test_успешная_оплата_ставит_чек_в_очередь(): void
    {
        Queue::fake();

        $payment = $this->paidOrder();

        $this->get($this->callbackUrl($payment));

        Queue::assertPushed(IssueReceipt::class);
        $this->assertSame(1, Receipt::count());
        $this->assertSame(ReceiptStatus::Pending, Receipt::sole()->status);
    }

    public function test_неудачная_оплата_чек_не_создаёт(): void
    {
        Queue::fake();

        $payment = $this->paidOrder();

        $this->get($this->callbackUrl($payment, 'fail'));

        Queue::assertNothingPushed();
        $this->assertSame(0, Receipt::count());
    }

    /** Выключенная фискализация не должна ничего создавать. */
    public function test_при_выключенной_фискализации_чеков_нет(): void
    {
        config(['fiscal.enabled' => false]);
        Queue::fake();

        $this->get($this->callbackUrl($this->paidOrder()));

        $this->assertSame(0, Receipt::count());
    }

    /** Два чека на одну оплату — это уже разговор с налоговой. */
    public function test_повторный_колбэк_не_плодит_чеки(): void
    {
        Queue::fake();

        $payment = $this->paidOrder();
        $url = $this->callbackUrl($payment);

        $this->get($url);
        $this->get($url);

        $this->assertSame(1, Receipt::count());
    }

    // ------------------------------------------------------------------
    // сам чек
    // ------------------------------------------------------------------

    public function test_чек_выбивается_и_сохраняет_номер(): void
    {
        $this->fakeWebkassa();

        $payment = $this->paidOrder(27000);
        $receipt = Receipt::create([
            'order_id' => $payment->order_id,
            'payment_id' => $payment->id,
            'amount' => 27000,
        ]);

        (new IssueReceipt($receipt))->handle(app(WebkassaClient::class));

        $receipt->refresh();

        $this->assertSame(ReceiptStatus::Issued, $receipt->status);
        $this->assertSame('000123', $receipt->check_number);
        $this->assertNotNull($receipt->ticket_url);
        $this->assertNotNull($receipt->fiscal_at);
    }

    public function test_в_чек_уходят_позиции_заказа(): void
    {
        $this->fakeWebkassa();

        $payment = $this->paidOrder(27000);
        $receipt = Receipt::create([
            'order_id' => $payment->order_id,
            'payment_id' => $payment->id,
            'amount' => 27000,
        ]);

        (new IssueReceipt($receipt))->handle(app(WebkassaClient::class));

        Http::assertSent(function ($request) use ($receipt) {
            if (! str_contains($request->url(), '/api/Check')) {
                return true;
            }

            $data = $request->data();

            return $data['CashboxUniqueNumber'] === 'SWK00000001'
                && $data['OperationType'] === 2
                && $data['Positions'][0]['PositionName'] === 'Лобовое стекло Camry'
                && $data['Payments'][0]['Sum'] === 27000.0
                && $data['ExternalCheckNumber'] === $receipt->order->number;
        });
    }

    public function test_выбитый_чек_попадает_в_журнал_заказа(): void
    {
        $this->fakeWebkassa();

        $payment = $this->paidOrder();
        $receipt = Receipt::create([
            'order_id' => $payment->order_id,
            'payment_id' => $payment->id,
            'amount' => $payment->amount,
        ]);

        (new IssueReceipt($receipt))->handle(app(WebkassaClient::class));

        $this->assertTrue(
            $receipt->order->interactions()->where('body', 'like', '%чек выбит%')->exists()
        );
    }

    // ------------------------------------------------------------------
    // сбои кассы
    // ------------------------------------------------------------------

    /** Сбой кассы не должен отменять оплату. */
    public function test_падение_кассы_не_ломает_оплату(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $payment = $this->paidOrder();

        $this->get($this->callbackUrl($payment))->assertRedirect();

        $payment->refresh();

        // оплата прошла, несмотря на то что чек не выбился
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertTrue($payment->order->isPaid());
        $this->assertSame(ReceiptStatus::Failed, Receipt::sole()->status);
    }

    public function test_ошибка_записывается_в_чек(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $payment = $this->paidOrder();
        $receipt = Receipt::create([
            'order_id' => $payment->order_id,
            'amount' => $payment->amount,
        ]);

        (new IssueReceipt($receipt))->handle(app(WebkassaClient::class));

        $receipt->refresh();

        $this->assertSame(ReceiptStatus::Failed, $receipt->status);
        $this->assertSame(1, $receipt->attempts);
        $this->assertNotNull($receipt->error);
    }

    /** Исчерпавший попытки чек перестаёт ретраиться — им занимается человек. */
    public function test_чек_с_исчерпанными_попытками_не_повторяется(): void
    {
        $payment = $this->paidOrder();

        $exhausted = Receipt::create([
            'order_id' => $payment->order_id,
            'amount' => $payment->amount,
            'status' => ReceiptStatus::Failed,
            'attempts' => (int) config('fiscal.webkassa.max_attempts'),
        ]);

        $this->assertTrue($exhausted->needsAttention());
        $this->assertSame(0, Receipt::retryable()->count());
    }

    public function test_уже_выбитый_чек_повторно_не_выбивается(): void
    {
        $this->fakeWebkassa();

        $payment = $this->paidOrder();
        $receipt = Receipt::create([
            'order_id' => $payment->order_id,
            'amount' => $payment->amount,
            'status' => ReceiptStatus::Issued,
            'check_number' => '000001',
        ]);

        (new IssueReceipt($receipt))->handle(app(WebkassaClient::class));

        Http::assertNothingSent();
        $this->assertSame('000001', $receipt->fresh()->check_number);
    }

    /** Токен кешируется: у Webkassa есть предел на частоту авторизаций. */
    public function test_токен_берётся_из_кеша_а_не_запрашивается_каждый_раз(): void
    {
        $this->fakeWebkassa();

        $client = app(WebkassaClient::class);

        $client->token();
        $client->token();
        $client->token();

        Http::assertSentCount(1);
    }
}
