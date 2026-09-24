<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\UserRole;
use App\Filament\Field\Pages\MyAssignments;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Assignment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Панелей две, и права у них разные. Ошибка здесь означает, что курьер
 * видит прайсы и заявки всех клиентов, поэтому границу проверяем с обеих сторон.
 */
class FieldPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-01 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_курьер_попадает_в_полевую_панель(): void
    {
        $this->actingAs(User::factory()->courier()->create())
            ->get(MyAssignments::getUrl(panel: 'field'))
            ->assertOk();
    }

    public function test_курьер_открывает_профиль_для_2fa(): void
    {
        $this->actingAs(User::factory()->courier()->create())
            ->get('/naryad/profile')
            ->assertOk();
    }

    public function test_установщик_попадает_в_полевую_панель(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Installer)->create())
            ->get(MyAssignments::getUrl(panel: 'field'))
            ->assertOk();
    }

    /** Курьеру нечего делать в прайсах и чужих заявках. */
    public function test_курьер_в_админку_не_попадает(): void
    {
        $this->actingAs(User::factory()->courier()->create())
            ->get(OrderResource::getUrl('index'))
            ->assertForbidden();
    }

    /** И наоборот: менеджер не работает в поле. */
    public function test_менеджер_в_полевую_панель_не_попадает(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get(MyAssignments::getUrl(panel: 'field'))
            ->assertForbidden();
    }

    public function test_покупатель_никуда_не_попадает(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Customer)->create())
            ->get(MyAssignments::getUrl(panel: 'field'))
            ->assertForbidden();
    }

    public function test_отключённый_курьер_теряет_доступ(): void
    {
        $this->actingAs(User::factory()->courier()->create(['is_active' => false]))
            ->get(MyAssignments::getUrl(panel: 'field'))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // экран нарядов
    // ------------------------------------------------------------------

    public function test_курьер_видит_только_свои_наряды(): void
    {
        $mine = User::factory()->courier()->create();
        $other = User::factory()->courier()->create();

        $ours = Assignment::factory()->type(AssignmentType::Delivery)->create([
            'assignee_id' => $mine->id,
            'scheduled_at' => today()->setTime(10, 0),
        ]);

        Assignment::factory()->type(AssignmentType::Delivery)->create([
            'assignee_id' => $other->id,
            'scheduled_at' => today()->setTime(11, 0),
        ]);

        $assignments = Livewire::actingAs($mine)
            ->test(MyAssignments::class)
            ->instance()
            ->assignments;

        $this->assertCount(1, $assignments);
        $this->assertSame($ours->id, $assignments->first()->id);
    }

    public function test_наряды_другого_дня_не_показываются(): void
    {
        $courier = User::factory()->courier()->create();

        Assignment::factory()->create([
            'assignee_id' => $courier->id,
            'scheduled_at' => today()->addDays(3)->setTime(10, 0),
        ]);

        Livewire::actingAs($courier)
            ->test(MyAssignments::class)
            ->assertSet('dayOffset', 0)
            ->tap(fn ($t) => $this->assertCount(0, $t->instance()->assignments));
    }

    public function test_переключение_дня_показывает_завтрашние(): void
    {
        $courier = User::factory()->courier()->create();

        Assignment::factory()->create([
            'assignee_id' => $courier->id,
            'scheduled_at' => today()->addDay()->setTime(10, 0),
        ]);

        $component = Livewire::actingAs($courier)
            ->test(MyAssignments::class)
            ->call('nextDay');

        $this->assertCount(1, $component->instance()->assignments);
    }

    public function test_исполнитель_двигает_статус_своего_наряда(): void
    {
        $courier = User::factory()->courier()->create();

        $assignment = Assignment::factory()
            ->type(AssignmentType::Delivery)
            ->status(AssignmentStatus::Assigned)
            ->create(['assignee_id' => $courier->id, 'scheduled_at' => today()->setTime(10, 0)]);

        Livewire::actingAs($courier)
            ->test(MyAssignments::class)
            ->call('move', $assignment->id, AssignmentStatus::EnRoute->value);

        $this->assertSame(AssignmentStatus::EnRoute, $assignment->fresh()->status);
    }

    /** Чужой наряд нельзя тронуть, даже зная его номер. */
    public function test_чужой_наряд_не_подвинуть(): void
    {
        $courier = User::factory()->courier()->create();
        $other = User::factory()->courier()->create();

        $assignment = Assignment::factory()
            ->type(AssignmentType::Delivery)
            ->status(AssignmentStatus::Assigned)
            ->create(['assignee_id' => $other->id]);

        // Наряд ищется среди своих, поэтому чужой попросту не находится
        $this->expectException(ModelNotFoundException::class);

        try {
            Livewire::actingAs($courier)
                ->test(MyAssignments::class)
                ->call('move', $assignment->id, AssignmentStatus::EnRoute->value);
        } finally {
            $this->assertSame(AssignmentStatus::Assigned, $assignment->fresh()->status);
        }
    }

    /** Запрещённый переход не проходит даже подделанным запросом. */
    public function test_запрещённый_переход_отклоняется(): void
    {
        $courier = User::factory()->courier()->create();

        $assignment = Assignment::factory()
            ->type(AssignmentType::Delivery)
            ->status(AssignmentStatus::Assigned)
            ->create(['assignee_id' => $courier->id]);

        Livewire::actingAs($courier)
            ->test(MyAssignments::class)
            ->call('move', $assignment->id, AssignmentStatus::Done->value);

        $this->assertSame(AssignmentStatus::Assigned, $assignment->fresh()->status);
    }

    /**
     * Экран должен показывать именно то, что нужно в дороге: время, адрес,
     * телефон клиента и что везём. Без этого курьер всё равно звонит диспетчеру.
     */
    public function test_экран_показывает_всё_нужное_для_выезда(): void
    {
        $courier = User::factory()->courier()->create();

        $customer = Customer::factory()->create(['name' => 'Айгуль', 'phone' => '77015551122']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $order->items()->create(['name' => 'Лобовое стекло Camry', 'quantity' => 1]);

        Assignment::factory()->type(AssignmentType::Delivery)->create([
            'order_id' => $order->id,
            'assignee_id' => $courier->id,
            'scheduled_at' => today()->setTime(11, 30),
            'address' => 'Алматы, Абая 150',
        ]);

        Livewire::actingAs($courier)
            ->test(MyAssignments::class)
            ->assertSee('11:30')
            ->assertSee('Айгуль')
            ->assertSee('77015551122')
            ->assertSee('Алматы, Абая 150')
            ->assertSee('Лобовое стекло Camry')
            ->assertSee($order->number);
    }

    public function test_пустой_день_показывает_подсказку(): void
    {
        Livewire::actingAs(User::factory()->courier()->create())
            ->test(MyAssignments::class)
            ->assertSee('нарядов нет');
    }

    /** У установщика нет статуса «в пути» — он никуда не едет. */
    public function test_у_установки_нет_статуса_в_пути(): void
    {
        $transitions = AssignmentStatus::Assigned->allowedTransitions(AssignmentType::Installation);

        $this->assertNotContains(AssignmentStatus::EnRoute, $transitions);
        $this->assertContains(AssignmentStatus::InProgress, $transitions);
    }
}
