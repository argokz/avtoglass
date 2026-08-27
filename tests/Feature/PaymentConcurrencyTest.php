<?php

namespace Tests\Feature;

use App\Actions\AcceptCashPayment;
use App\Actions\StartPayment;
use App\Enums\PaymentStatus;
use App\Jobs\IssueReceipt;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\Gateways\FreedomPayGateway;
use App\Payments\Gateways\HalykEpayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Одновременные действия с деньгами.
 *
 * Всё здесь — про случаи, когда два запроса приходят разом: двойной клик
 * по «оплатить», курьер и менеджер, принимающие один остаток, обрыв связи
 * с банком посреди платежа. По одному такие сценарии не воспроизводятся,
 * и находят их обычно по расхождению в кассе через месяц.
 */
class PaymentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function order(float $amount = 30000): Order
    {
        $order = Order::factory()->create(['total_amount' => $amount]);
        $order->items()->create(['name' => 'Лобовое стекло', 'price' => $amount, 'quantity' => 1]);

        return $order->fresh();
    }

    // ------------------------------------------------------------------
    // создание платежа
    // ------------------------------------------------------------------

    /**
     * Двойной клик по «оплатить» не должен заводить две попытки: каждая
     * получит свой колбэк, обе применятся, и клиент заплатит дважды.
     */
    public function test_повторный_запуск_не_плодит_платежи(): void
    {
        $order = $this->order();
        $action = app(StartPayment::class);

        $first = $action->handle($order);
        $second = $action->handle($order->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $order->payments()->count());
    }

    /**
     * Проверка и создание идут под блокировкой заказа, поэтому решение
     * принимается по свежим данным, а не по объекту, полученному раньше.
     */
    public function test_устаревший_заказ_второй_платёж_не_создаёт(): void
    {
        $order = $this->order();
        $stale = Order::find($order->id);   // снимок «до»

        $first = app(StartPayment::class)->handle($order);
        $second = app(StartPayment::class)->handle($stale);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payment::count());
    }

    /** Оплаченный заказ второй попытки не получает. */
    public function test_оплаченный_заказ_новую_попытку_не_заводит(): void
    {
        $order = $this->order();

        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Succeeded,
            'amount' => 30000,
            'paid_at' => now(),
        ]);

        $this->assertNull(app(StartPayment::class)->handle($order->fresh()));
    }

    // ------------------------------------------------------------------
    // приём наличных
    // ------------------------------------------------------------------

    /**
     * Курьер на адресе и менеджер в панели могут нажать «принять» разом.
     * Оба видят «к оплате 30 000» — но принять эту сумму должен только один.
     */
    public function test_остаток_проверяется_по_свежим_данным(): void
    {
        $order = $this->order(30000);
        $stale = Order::find($order->id);   // объект, полученный до первой оплаты

        $courier = User::factory()->courier()->create();
        $manager = User::factory()->manager()->create();

        $this->assertNotNull(app(AcceptCashPayment::class)->handle($order, 30000, $courier));

        // Второй исполнитель пришёл со своим, уже устаревшим объектом заказа
        $this->assertNull(app(AcceptCashPayment::class)->handle($stale, 30000, $manager));

        $this->assertSame(30000.0, (float) $order->fresh()->paidAmount());
    }

    public function test_частичные_оплаты_не_превышают_суммы_заказа(): void
    {
        $order = $this->order(30000);
        $stale = Order::find($order->id);
        $courier = User::factory()->courier()->create();

        app(AcceptCashPayment::class)->handle($order, 20000, $courier);
        app(AcceptCashPayment::class)->handle($stale, 20000, $courier);

        $this->assertSame(20000.0, (float) $order->fresh()->paidAmount());
    }

    // ------------------------------------------------------------------
    // чек и транзакция
    // ------------------------------------------------------------------

    /**
     * Чек ставится в очередь внутри транзакции. Без afterCommit воркер
     * успевает схватить задачу раньше, чем платёж окажется в базе, — и тихо
     * выходит, не найдя ни чека, ни оплаты.
     */
    public function test_фискальный_чек_уходит_только_после_коммита(): void
    {
        config(['fiscal.enabled' => true]);
        Queue::fake();

        app(AcceptCashPayment::class)->handle(
            $this->order(30000),
            30000,
            User::factory()->courier()->create(),
        );

        Queue::assertPushed(IssueReceipt::class, function (IssueReceipt $job) {
            return $job->afterCommit === true;
        });
    }

    /** Транзакция закрывается: незавершённых после действия оставаться не должно. */
    public function test_приём_наличных_не_оставляет_открытую_транзакцию(): void
    {
        app(AcceptCashPayment::class)->handle(
            $this->order(30000),
            30000,
            User::factory()->courier()->create(),
        );

        // RefreshDatabase держит свою внешнюю — уровней сверх неё быть не должно
        $this->assertSame(1, DB::transactionLevel());
    }

    // ------------------------------------------------------------------
    // связь с банком
    // ------------------------------------------------------------------

    /**
     * Ответ 5xx разбирается отдельно, а вот обрыв связи приходит исключением.
     * Без перехвата клиент вместо «касса недоступна» получает пятисотую.
     */
    public function test_обрыв_связи_с_halyk_не_роняет_оплату(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timed out'));

        $payment = Payment::factory()->for($this->order())->create(['provider' => 'halyk']);

        $this->assertNull(app(HalykEpayGateway::class)->authorize($payment));
    }

    public function test_обрыв_связи_с_freedompay_не_роняет_оплату(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $payment = Payment::factory()->for($this->order())->create(['provider' => 'freedompay']);

        $this->assertNull(app(FreedomPayGateway::class)->redirectUrl($payment));
    }

    /** Клиент видит понятный отказ, а не страницу ошибки. */
    public function test_недоступная_касса_показывает_отказ_а_не_ошибку(): void
    {
        config(['payments.driver' => 'freedompay']);
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $order = $this->order();

        $this->withSession(['last_order_number' => $order->number])
            ->post(route('payment.start'))
            ->assertRedirect(route('order.thanks'))
            ->assertSessionHas('payment_error');
    }
}
