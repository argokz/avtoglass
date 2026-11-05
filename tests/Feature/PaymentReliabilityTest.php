<?php

namespace Tests\Feature;

use App\Actions\ApplyPaymentCallback;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReceiptStatus;
use App\Fiscal\WebkassaClient;
use App\Jobs\IssueReceipt;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use App\Payments\CallbackResult;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Надёжность денежного контура.
 *
 * Здесь проверяется не «работает ли оплата» — это делает PaymentTest, — а то,
 * что она переживает второй колбэк, недоделанный платёж и лежащую кассу.
 * Каждый из этих случаев на бою происходит регулярно, и каждый молча портит
 * либо деньги, либо отчётность.
 */
class PaymentReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private function payment(float $amount = 30000): Payment
    {
        $order = Order::factory()->create(['total_amount' => $amount]);
        $order->items()->create(['name' => 'Лобовое стекло', 'price' => $amount, 'quantity' => 1]);

        return Payment::factory()->for($order)->create([
            'amount' => $amount,
            'status' => PaymentStatus::Pending,
        ]);
    }

    private function callbackResult(Payment $payment, PaymentStatus $status): CallbackResult
    {
        return new CallbackResult(
            token: $payment->token,
            status: $status,
            amount: (float) $payment->amount,
            externalId: 'ext-'.$payment->id,
            failureReason: null,
            raw: ['pg_result' => $status->isPaid() ? '1' : '2'],
        );
    }

    // ------------------------------------------------------------------
    // промежуточное уведомление
    // ------------------------------------------------------------------

    /**
     * Freedom Pay шлёт pg_result=2, пока клиент ещё на странице банка.
     * Это не отказ, и трогать платёж нельзя.
     */
    public function test_промежуточный_колбэк_не_отменяет_платёж(): void
    {
        $payment = $this->payment();

        app(ApplyPaymentCallback::class)->handle($payment, $this->callbackResult($payment, PaymentStatus::Pending));

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::New, $payment->order->fresh()->status);
    }

    public function test_промежуточный_колбэк_не_пишет_об_отказе(): void
    {
        $payment = $this->payment();

        app(ApplyPaymentCallback::class)->handle($payment, $this->callbackResult($payment, PaymentStatus::Pending));

        $this->assertSame(
            0,
            $payment->order->interactions()->where('body', 'like', '%не прошла%')->count()
        );
    }

    /**
     * Главное следствие: после промежуточного уведомления настоящая оплата
     * всё ещё должна примениться. Пометь мы платёж завершённым — деньги
     * пришли бы, а заказ остался неоплаченным.
     */
    public function test_после_промежуточного_колбэка_оплата_проходит(): void
    {
        $payment = $this->payment();
        $action = app(ApplyPaymentCallback::class);

        $action->handle($payment, $this->callbackResult($payment, PaymentStatus::Pending));
        $action->handle($payment->fresh(), $this->callbackResult($payment, PaymentStatus::Succeeded));

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Confirmed, $payment->order->fresh()->status);
    }

    // ------------------------------------------------------------------
    // повторное уведомление
    // ------------------------------------------------------------------

    /** Кассы шлют колбэк, пока не получат ответ. Второй раз — ничего. */
    public function test_второй_колбэк_не_дублирует_запись_в_журнале(): void
    {
        $payment = $this->payment();
        $action = app(ApplyPaymentCallback::class);

        $this->assertTrue($action->handle($payment, $this->callbackResult($payment, PaymentStatus::Succeeded)));
        $this->assertFalse($action->handle($payment->fresh(), $this->callbackResult($payment, PaymentStatus::Succeeded)));

        $this->assertSame(
            1,
            $payment->order->interactions()->where('body', 'like', '%Оплата получена%')->count()
        );
    }

    /**
     * Платёж перечитывается под блокировкой, а не берётся из аргумента:
     * два колбэка входят сюда с одинаково устаревшими объектами.
     */
    public function test_устаревший_объект_платежа_повторно_не_применяется(): void
    {
        $payment = $this->payment();
        $stale = Payment::find($payment->id);   // снимок «до», статус Pending

        app(ApplyPaymentCallback::class)->handle($payment, $this->callbackResult($payment, PaymentStatus::Succeeded));

        $this->assertFalse(
            app(ApplyPaymentCallback::class)->handle($stale, $this->callbackResult($payment, PaymentStatus::Succeeded))
        );
        $this->assertSame(
            1,
            $payment->order->interactions()->where('body', 'like', '%Оплата получена%')->count()
        );
    }

    // ------------------------------------------------------------------
    // повторы фискального чека
    // ------------------------------------------------------------------

    private function receipt(int $attempts = 0): Receipt
    {
        $payment = $this->payment(27000);

        return Receipt::create([
            'order_id' => $payment->order_id,
            'payment_id' => $payment->id,
            'status' => ReceiptStatus::Pending,
            'amount' => $payment->amount,
            'attempts' => $attempts,
        ]);
    }

    /**
     * Ради этого всё и переписывалось: упавшая касса обязана привести
     * к повтору. Раньше исключение глоталось, задача считалась успешной,
     * и чек не выбивался уже никогда.
     */
    public function test_упавшая_касса_ставит_задачу_на_повтор(): void
    {
        config(['fiscal.enabled' => true]);
        Http::fake(['*' => Http::response('', 500)]);

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('release')->once()->with(30);

        $task = new IssueReceipt($this->receipt());
        $task->setJob($job);
        $task->handle(app(WebkassaClient::class));
    }

    /** Интервалы растут, а после списка держатся на последнем. */
    public function test_пауза_между_повторами_увеличивается(): void
    {
        $task = new IssueReceipt($this->receipt());

        $this->assertSame(30, $task->retryDelay(1));
        $this->assertSame(120, $task->retryDelay(2));
        $this->assertSame(600, $task->retryDelay(3));
        $this->assertSame(1800, $task->retryDelay(9));
    }

    /**
     * Когда попытки кончились, долбиться в кассу бессмысленно —
     * дальше нужен человек, а не очередь.
     */
    public function test_исчерпав_попытки_задача_не_повторяется(): void
    {
        config(['fiscal.enabled' => true, 'fiscal.webkassa.max_attempts' => 3]);
        Http::fake(['*' => Http::response('', 500)]);

        $job = Mockery::mock(Job::class);
        $job->shouldNotReceive('release');

        $receipt = $this->receipt(attempts: 2);   // третья попытка — последняя

        $task = new IssueReceipt($receipt);
        $task->setJob($job);
        $task->handle(app(WebkassaClient::class));

        $this->assertTrue($receipt->fresh()->needsAttention());
        $this->assertNotNull(
            $receipt->order->interactions()->where('body', 'like', '%Чек не выбит%')->first()
        );
    }

    // ------------------------------------------------------------------
    // один телефон — один клиент
    // ------------------------------------------------------------------

    public function test_телефон_клиента_уникален(): void
    {
        Customer::forPhone('77015550001', 'Первый');

        $this->expectException(UniqueConstraintViolationException::class);

        Customer::create(['phone' => '77015550001', 'name' => 'Второй']);
    }

    /**
     * Две заявки в одну секунду: пока мы искали карточку, её создал
     * параллельный запрос. Правильный исход — один клиент, а не 500.
     */
    /**
     * Вклинивает «параллельный запрос» ровно между поиском клиента и его
     * созданием: слушатель срабатывает после того, как SELECT вернул пусто,
     * и создаёт карточку до нашей вставки. Это и есть та гонка, ради которой
     * телефон сделан уникальным.
     */
    private function raceOnLookup(string $phone, string $name): void
    {
        $raced = false;

        DB::listen(function ($query) use (&$raced, $phone, $name) {
            if ($raced || ! str_contains($query->sql, 'phone_hash')) {
                return;
            }

            $raced = true;

            // Параллельный запрос пишет так же, как forPhone: шифротекст + хэш.
            DB::table('customers')->insert([
                'phone' => Crypt::encryptString($phone),
                'phone_hash' => Customer::phoneHash($phone),
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_гонка_двух_заявок_даёт_одного_клиента(): void
    {
        $this->raceOnLookup('77015550002', 'Создан параллельным запросом');

        $customer = Customer::forPhone('+7 701 555 00 02', 'Наш');

        $this->assertSame('Создан параллельным запросом', $customer->name);
        $this->assertSame(1, Customer::where('phone_hash', Customer::phoneHash('77015550002'))->count());
    }

    /**
     * Заявка создаётся внутри транзакции, а PostgreSQL после любой упавшей
     * команды обрывает её целиком. Значит, столкновение по телефону обязано
     * обходиться без ошибки на стороне базы, иначе клиент вместо номера заказа
     * получит пятисотую.
     */
    public function test_гонка_не_обрывает_транзакцию_заказа(): void
    {
        $this->raceOnLookup('77015550003', 'Создан параллельным запросом');

        $order = DB::transaction(function () {
            $customer = Customer::forPhone('77015550003', 'Наш');

            return Order::factory()->create(['customer_id' => $customer->id]);
        });

        $this->assertNotNull($order->number);
        $this->assertSame(1, Customer::where('phone_hash', Customer::phoneHash('77015550003'))->count());
    }
}
