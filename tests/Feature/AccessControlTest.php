<?php

namespace Tests\Feature;

use App\Enums\AssignmentType;
use App\Enums\UserRole;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Assignment;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Кто что видит.
 *
 * Раньше документы были закрыты только `auth`: любой сотрудник, зная номер
 * заказа — а он подбирается перебором, — открывал чужой счёт с телефоном,
 * адресом и суммой. И менеджер с администратором различались только на словах.
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private function orderWithCourier(User $courier): Order
    {
        $order = Order::factory()->create(['total_amount' => 30000]);
        $order->items()->create(['name' => 'Лобовое стекло', 'price' => 30000, 'quantity' => 1]);

        Assignment::factory()->type(AssignmentType::Delivery)->create([
            'order_id' => $order->id,
            'assignee_id' => $courier->id,
            'scheduled_at' => today()->setTime(11, 0),
        ]);

        return $order->fresh();
    }

    private function plainOrder(): Order
    {
        $order = Order::factory()->create(['total_amount' => 30000]);
        $order->items()->create(['name' => 'Боковое стекло', 'price' => 30000, 'quantity' => 1]);

        return $order->fresh();
    }

    // ------------------------------------------------------------------
    // документы по заказу
    // ------------------------------------------------------------------

    /** Главная дыра: чужой заказ по угаданному номеру. */
    public function test_курьер_чужой_документ_не_откроет(): void
    {
        $courier = User::factory()->courier()->create();

        $this->actingAs($courier)
            ->get(route('document.order', [$this->plainOrder(), 'invoice']))
            ->assertForbidden();
    }

    /** Наряд и есть основание видеть клиента. */
    public function test_курьер_свой_документ_открывает(): void
    {
        $courier = User::factory()->courier()->create();
        $order = $this->orderWithCourier($courier);

        $this->actingAs($courier)
            ->get(route('document.order', [$order, 'sales_receipt']))
            ->assertOk();
    }

    public function test_менеджер_видит_документы_по_любой_заявке(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get(route('document.order', [$this->plainOrder(), 'invoice']))
            ->assertOk();
    }

    public function test_покупатель_документы_не_открывает(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Customer)->create())
            ->get(route('document.order', [$this->plainOrder(), 'sales_receipt']))
            ->assertForbidden();
    }

    public function test_гость_на_документ_уходит_на_вход(): void
    {
        $this->get(route('document.order', [$this->plainOrder(), 'sales_receipt']))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    // ------------------------------------------------------------------
    // реестр курьера
    // ------------------------------------------------------------------

    /** В реестре весь маршрут смены: адреса, телефоны и суммы к получению. */
    public function test_курьер_чужой_реестр_не_откроет(): void
    {
        $courier = User::factory()->courier()->create();
        $other = User::factory()->courier()->create();

        $this->actingAs($courier)
            ->get(route('document.manifest', $other))
            ->assertForbidden();
    }

    public function test_курьер_свой_реестр_открывает(): void
    {
        $courier = User::factory()->courier()->create();
        $this->orderWithCourier($courier);

        $this->actingAs($courier)
            ->get(route('document.manifest', $courier))
            ->assertOk();
    }

    public function test_диспетчер_открывает_реестр_любого_курьера(): void
    {
        $courier = User::factory()->courier()->create();
        $this->orderWithCourier($courier);

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('document.manifest', $courier))
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // разделение менеджера и администратора
    // ------------------------------------------------------------------

    public function test_менеджер_поставщиков_не_видит(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get(SupplierResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_менеджер_сотрудников_не_заводит(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get(UserResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_администратор_сотрудников_ведёт(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(UserResource::getUrl('index'))
            ->assertOk();
    }

    public function test_менеджер_заявки_и_клиентов_видит(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get(OrderResource::getUrl('index'))->assertOk();
        $this->actingAs($manager)->get(CustomerResource::getUrl('index'))->assertOk();
    }

    /** У курьера своя панель — списка всех клиентов ему там видеть незачем. */
    public function test_курьер_клиентов_в_админке_не_видит(): void
    {
        $this->actingAs(User::factory()->courier()->create())
            ->get(CustomerResource::getUrl('index'))
            ->assertForbidden();
    }
}
