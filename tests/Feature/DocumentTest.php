<?php

namespace Tests\Feature;

use App\Enums\AssignmentType;
use App\Enums\DocumentType;
use App\Enums\PaymentStatus;
use App\Models\Assignment;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Печатные документы.
 *
 * Главное свойство — неизменность. Документ, отданный клиенту, при повторной
 * печати обязан выглядеть так же и нести тот же номер: покупатель на него
 * сослался, бухгалтерия внесла в учёт, и второй номер на тот же чек —
 * это расхождение в отчётности.
 */
class DocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-27 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function order(float $amount = 30000): Order
    {
        // Один и тот же телефон — один и тот же клиент: телефон теперь уникален
        $customer = Customer::forPhone('77015553311', 'Данил Шафеев');
        $order = Order::factory()->create(['customer_id' => $customer->id, 'total_amount' => $amount]);

        $order->items()->create([
            'name' => 'Лобовое стекло Toyota Camry',
            'supplier_name' => 'КМК',
            'price' => $amount,
            'quantity' => 1,
        ]);

        return $order->fresh();
    }

    private function manager(): User
    {
        return User::factory()->manager()->create();
    }

    // ------------------------------------------------------------------
    // номера
    // ------------------------------------------------------------------

    public function test_номер_содержит_тип_и_год(): void
    {
        $document = Document::issue(DocumentType::SalesReceipt, $this->order());

        $this->assertSame('ТЧ-2026-000001', $document->number);
    }

    /** Повторная печать не должна выдавать второй номер на тот же документ. */
    public function test_повторная_выдача_возвращает_тот_же_номер(): void
    {
        $order = $this->order();

        $first = Document::issue(DocumentType::SalesReceipt, $order);
        $second = Document::issue(DocumentType::SalesReceipt, $order);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->number, $second->number);
        $this->assertSame(1, Document::count());
    }

    /**
     * Свой счётчик на каждый тип: сквозная нумерация выглядела бы у бухгалтера
     * как дыры в журнале счетов.
     */
    public function test_у_каждого_типа_своя_нумерация(): void
    {
        Document::issue(DocumentType::SalesReceipt, $this->order());
        Document::issue(DocumentType::SalesReceipt, $this->order());
        $invoice = Document::issue(DocumentType::Invoice, $this->order());

        $this->assertSame('СЧ-2026-000001', $invoice->number);
    }

    public function test_номера_растут_последовательно(): void
    {
        $numbers = collect(range(1, 3))
            ->map(fn () => Document::issue(DocumentType::SalesReceipt, $this->order())->number);

        $this->assertSame(['ТЧ-2026-000001', 'ТЧ-2026-000002', 'ТЧ-2026-000003'], $numbers->all());
    }

    /** Разные документы по одному заказу не конфликтуют между собой. */
    public function test_по_одному_заказу_выдаются_разные_документы(): void
    {
        $order = $this->order();

        $receipt = Document::issue(DocumentType::SalesReceipt, $order);
        $warranty = Document::issue(DocumentType::Warranty, $order);

        $this->assertNotSame($receipt->number, $warranty->number);
        $this->assertSame(2, Document::count());
    }

    // ------------------------------------------------------------------
    // доступ
    // ------------------------------------------------------------------

    /** В документах телефон, адрес и суммы — посторонним их отдавать нельзя. */
    public function test_документ_закрыт_от_неавторизованных(): void
    {
        $order = $this->order();

        $this->get(route('document.order', [$order, 'sales_receipt']))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_неизвестный_тип_даёт_404(): void
    {
        $this->actingAs($this->manager())
            ->get(route('document.order', [$this->order(), 'nonexistent']))
            ->assertNotFound();
    }

    /** Реестр курьера привязан к смене, а не к заказу. */
    public function test_реестр_нельзя_запросить_как_документ_заказа(): void
    {
        $this->actingAs($this->manager())
            ->get(route('document.order', [$this->order(), 'courier_manifest']))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // содержимое
    // ------------------------------------------------------------------

    public function test_товарный_чек_содержит_позиции_и_итог(): void
    {
        $order = $this->order(30000);

        $this->actingAs($this->manager())
            ->get(route('document.order', [$order, 'sales_receipt']))
            ->assertOk()
            ->assertSee('Товарный чек')
            ->assertSee('Лобовое стекло Toyota Camry')
            ->assertSee('Данил Шафеев')
            ->assertSee('30 000,00');
    }

    /** Товарный чек не заменяет фискальный — об этом должно быть сказано. */
    public function test_товарный_чек_предупреждает_что_не_заменяет_фискальный(): void
    {
        $this->actingAs($this->manager())
            ->get(route('document.order', [$this->order(), 'sales_receipt']))
            ->assertOk()
            ->assertSee('не заменяет фискальный');
    }

    public function test_гарантийный_талон_показывает_срок(): void
    {
        $this->actingAs($this->manager())
            ->get(route('document.order', [$this->order(), 'warranty']))
            ->assertOk()
            ->assertSee('Гарантийный талон')
            ->assertSee('12 мес.')
            // год от даты выдачи
            ->assertSee('27.08.2027');
    }

    /** Про калибровку в талоне предупреждаем только там, где есть камера. */
    public function test_про_калибровку_пишем_только_при_камере(): void
    {
        $order = $this->order();
        $product = Product::factory()->withCamera()->create();
        $order->items()->update(['product_id' => $product->id]);

        $this->actingAs($this->manager())
            ->get(route('document.order', [$order->fresh(), 'warranty']))
            ->assertOk()
            ->assertSee('калибровка');

        $plain = $this->order();

        $this->actingAs($this->manager())
            ->get(route('document.order', [$plain, 'warranty']))
            ->assertOk()
            ->assertDontSee('обязательна калибровка');
    }

    /** Ради этой оговорки акт и подписывают: стекло бьётся в дороге. */
    public function test_акт_содержит_оговорку_о_претензиях(): void
    {
        $this->actingAs($this->manager())
            ->get(route('document.order', [$this->order(), 'handover_act']))
            ->assertOk()
            ->assertSee('Претензии по сколам');
    }

    public function test_счёт_показывает_реквизиты_и_сумму(): void
    {
        settings()->save([
            'bin' => '123456789012',
            'legal_name' => 'ТОО «АвтостеклоЦентр»',
        ]);

        $this->actingAs($this->manager())
            ->get(route('document.order', [$this->order(45000), 'invoice']))
            ->assertOk()
            ->assertSee('Счёт на оплату')
            ->assertSee('123456789012')
            ->assertSee('45 000,00');
    }

    /** Без реквизитов счёт юридической силы не имеет — это должно быть видно. */
    public function test_без_реквизитов_документ_помечается(): void
    {
        settings()->save(['bin' => '']);
        config(['company.requisites.bin' => null]);

        $this->actingAs($this->manager())
            ->get(route('document.order', [$this->order(), 'invoice']))
            ->assertOk()
            ->assertSee('юридической силы он не имеет')
            ->assertSee('Настройки');
    }

    // ------------------------------------------------------------------
    // реестр курьера
    // ------------------------------------------------------------------

    public function test_реестр_собирает_наряды_курьера_на_день(): void
    {
        $courier = User::factory()->courier()->create(['name' => 'Курьер Ерлан']);

        $order = $this->order(25000);
        Assignment::factory()->type(AssignmentType::Delivery)->create([
            'order_id' => $order->id,
            'assignee_id' => $courier->id,
            'scheduled_at' => today()->setTime(14, 0),
            'address' => 'Караганда, Гоголя 15',
        ]);

        $this->actingAs($this->manager())
            ->get(route('document.manifest', $courier))
            ->assertOk()
            ->assertSee('Реестр отправлений')
            ->assertSee('Курьер Ерлан')
            ->assertSee('Караганда, Гоголя 15')
            ->assertSee('14:00');
    }

    /** Курьер должен видеть, где брать деньги, а где заказ уже оплачен. */
    public function test_реестр_разделяет_оплаченное_и_наличные(): void
    {
        $courier = User::factory()->courier()->create();

        $paid = $this->order(25000);
        Payment::factory()->for($paid)->create(['status' => PaymentStatus::Succeeded, 'amount' => 25000]);

        $unpaid = $this->order(18000);

        foreach ([$paid, $unpaid] as $order) {
            Assignment::factory()->type(AssignmentType::Delivery)->create([
                'order_id' => $order->id,
                'assignee_id' => $courier->id,
                'scheduled_at' => today()->setTime(12, 0),
            ]);
        }

        $this->actingAs($this->manager())
            ->get(route('document.manifest', $courier))
            ->assertOk()
            ->assertSee('оплачено')
            // получить наличными надо только за неоплаченный
            ->assertSee('18 000,00');
    }

    public function test_пустая_смена_реестр_не_ломает(): void
    {
        $this->actingAs($this->manager())
            ->get(route('document.manifest', User::factory()->courier()->create()))
            ->assertOk()
            ->assertSee('Всего адресов');
    }
}
