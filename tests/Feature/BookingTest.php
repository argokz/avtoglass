<?php

namespace Tests\Feature;

use App\Actions\BookInstallation;
use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\GlassType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Livewire\SlotPicker;
use App\Logistics\BookingSlots;
use App\Models\Assignment;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServicePoint;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Онлайн-запись на замену.
 *
 * У человека с разбитым стеклом вопрос «когда» стоит вровень с «сколько»,
 * а ответа на него не было: везде «оставьте заявку, менеджер перезвонит».
 *
 * Главное, что здесь проверяется, — что записать двоих на один подъёмник
 * нельзя. Это не ошибка в базе, а испорченный день у обоих клиентов.
 */
class BookingTest extends TestCase
{
    use RefreshDatabase;

    private ServicePoint $point;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ServiceSeeder::class);

        // Понедельник посреди рабочего дня: тесты не должны зависеть
        // от того, в котором часу их запустили.
        Carbon::setTestNow('2026-09-07 09:00:00');

        $this->point = ServicePoint::factory()->create([
            'name' => 'Сервис на Аэрологической',
            'bays' => 1,
            'opens_at' => '09:00:00',
            'closes_at' => '20:00:00',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function windshield(): Product
    {
        $product = Product::factory()->create([
            'glass_type' => GlassType::Windshield->value,
            'price_min' => 30000,
        ]);

        Offer::factory()->price(30000)->create(['product_id' => $product->id]);

        return $product;
    }

    private function book(Carbon $start, ?Product $product = null): ?Order
    {
        return app(BookInstallation::class)->handle(
            ['name' => 'Данил', 'phone' => '77015550001'],
            $this->point,
            $start,
            $product ?? $this->windshield(),
        );
    }

    /** Ближайшее допустимое время: сегодня плюс запас на подготовку. */
    private function firstSlot(): Carbon
    {
        return app(BookingSlots::class)
            ->slots($this->point, now(), null)
            ->firstOrFail();
    }

    // ------------------------------------------------------------------
    // правила показа окон
    // ------------------------------------------------------------------

    /**
     * Через двадцать минут записывать нельзя: клиенту надо доехать,
     * а сервису — достать стекло со склада.
     */
    public function test_ближайшие_часы_в_выбор_не_попадают(): void
    {
        config(['booking.lead_hours' => 3]);

        $slots = app(BookingSlots::class)->slots($this->point, now(), null);

        $this->assertTrue(
            $slots->every(fn (Carbon $slot) => $slot->gte(now()->addHours(3))),
            'окно раньше запаса на подготовку'
        );
    }

    public function test_дальше_горизонта_не_записывают(): void
    {
        config(['booking.horizon_days' => 14]);

        $this->assertFalse(app(BookingSlots::class)->allows(now()->addDays(20)));
        $this->assertNull($this->book(now()->addDays(20)->setTime(12, 0)));
    }

    /** К длительности работы добавляется запас: машину надо загнать и выгнать. */
    public function test_под_работу_бронируется_время_с_запасом(): void
    {
        config(['booking.buffer_minutes' => 30]);

        $windshield = Service::defaultFor(GlassType::Windshield);

        $this->assertSame(120, $windshield->duration_minutes);
        $this->assertSame(150, app(BookingSlots::class)->duration($windshield));
    }

    /** Пустой день в календаре заставляет тыкать наугад. */
    public function test_дни_без_окон_не_предлагаются(): void
    {
        $days = app(BookingSlots::class)->days($this->point, null);

        foreach ($days as $day) {
            $this->assertNotEmpty(
                app(BookingSlots::class)->slots($this->point, $day, null),
                "день {$day->toDateString()} предложен пустым"
            );
        }
    }

    // ------------------------------------------------------------------
    // сама запись
    // ------------------------------------------------------------------

    public function test_запись_создаёт_заявку_и_наряд(): void
    {
        $order = $this->book($this->firstSlot());

        $this->assertNotNull($order);
        $this->assertSame(OrderStatus::Scheduled, $order->status);
        $this->assertSame(OrderSource::Booking, $order->source);

        $assignment = Assignment::sole();

        $this->assertSame(AssignmentType::Installation, $assignment->type);
        $this->assertSame($this->point->id, $assignment->service_point_id);
        $this->assertSame(AssignmentStatus::Planned, $assignment->status);
    }

    /** Запись — это замена в сервисе, значит работа входит в сумму. */
    public function test_в_заявку_попадает_установка(): void
    {
        $order = $this->book($this->firstSlot());

        $this->assertSame(2, $order->items()->count());
        $this->assertSame(45000.0, (float) $order->total_amount);
    }

    public function test_запись_видна_в_журнале_заявки(): void
    {
        $order = $this->book($this->firstSlot());

        $this->assertNotNull(
            $order->interactions()->where('body', 'like', '%записался сам%')->first()
        );
    }

    /** Записаться можно и без товара: стекло подберёт мастер на месте. */
    public function test_запись_без_товара_возможна(): void
    {
        $order = app(BookInstallation::class)->handle(
            ['name' => 'Данил', 'phone' => '77015550002'],
            $this->point,
            $this->firstSlot(),
        );

        $this->assertNotNull($order);
        $this->assertSame(0, $order->items()->count());
    }

    // ------------------------------------------------------------------
    // двойная запись
    // ------------------------------------------------------------------

    /**
     * Ради этого всё и делалось. Пост один, и второй клиент на то же время
     * попасть не должен.
     */
    public function test_занятое_время_второй_раз_не_отдаётся(): void
    {
        $slot = $this->firstSlot();

        $this->assertNotNull($this->book($slot));
        $this->assertNull($this->book($slot), 'записали двоих на один пост');
    }

    /**
     * Отказ обязан быть чистым: заявка без времени хуже, чем «выберите другое»,
     * — клиент считает, что записан, и приезжает.
     */
    public function test_неудачная_запись_не_оставляет_заявку(): void
    {
        $slot = $this->firstSlot();
        $this->book($slot);

        $before = Order::count();
        $this->book($slot);

        $this->assertSame($before, Order::count(), 'осталась заявка без времени');
    }

    /** Два поста — двое клиентов на одно время, и это нормально. */
    public function test_второй_пост_позволяет_записать_двоих(): void
    {
        $this->point->update(['bays' => 2]);
        $slot = $this->firstSlot();

        $this->assertNotNull($this->book($slot));
        $this->assertNotNull($this->book($slot));
    }

    // ------------------------------------------------------------------
    // экран
    // ------------------------------------------------------------------

    public function test_страница_записи_открывается(): void
    {
        $this->get(route('booking.form'))
            ->assertOk()
            ->assertSee('Запись на замену');
    }

    public function test_на_экране_видно_свободное_время(): void
    {
        Livewire::test(SlotPicker::class)
            ->assertSee('Когда удобно')
            ->assertSee('Во сколько');
    }

    public function test_клиент_записывается_с_экрана(): void
    {
        $slot = $this->firstSlot();

        Livewire::test(SlotPicker::class, ['product' => $this->windshield()])
            ->set('pointId', $this->point->id)
            ->call('selectSlot', $slot->toDateTimeString())
            ->set('name', 'Данил')
            ->set('phone', '77015550003')
            ->call('book')
            ->assertSet('booked', Order::sole()->number);

        $this->assertSame(1, Assignment::count());
    }

    public function test_без_имени_и_телефона_не_записывают(): void
    {
        Livewire::test(SlotPicker::class)
            ->set('pointId', $this->point->id)
            ->call('selectSlot', $this->firstSlot()->toDateTimeString())
            ->call('book')
            ->assertHasErrors(['name', 'phone']);

        $this->assertSame(0, Assignment::count());
    }

    /** Пока заполняли форму, время заняли — об этом надо сказать прямо. */
    public function test_занятое_время_объясняется_человеку(): void
    {
        $slot = $this->firstSlot();

        $component = Livewire::test(SlotPicker::class)
            ->set('pointId', $this->point->id)
            ->call('selectSlot', $slot->toDateTimeString())
            ->set('name', 'Данил')
            ->set('phone', '77015550004');

        // Кто-то другой успел занять пост
        $this->book($slot);

        $component->call('book')
            ->assertSet('booked', null)
            ->assertSee('только что заняли');
    }
}
