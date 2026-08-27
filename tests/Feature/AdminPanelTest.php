<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Resources\CarBrands\CarBrandResource;
use App\Filament\Resources\CarModelAliases\CarModelAliasResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_панель_закрыта_от_посторонних(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    /**
     * Главное правило доступа: аккаунт на сайте не даёт входа в админку.
     * Без явной проверки роли Filament пустил бы туда любого покупателя.
     */
    public function test_покупателя_в_админку_не_пускают(): void
    {
        $customer = User::factory()->role(UserRole::Customer)->create();

        $this->actingAs($customer)
            ->get(ProductResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_курьер_в_админку_не_попадает(): void
    {
        $this->actingAs(User::factory()->courier()->create())
            ->get(ProductResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_менеджер_в_админку_попадает(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get(OrderResource::getUrl('index'))
            ->assertOk();
    }

    /**
     * Менеджер ведёт продажи, но справочники ему закрыты: неверная наценка
     * у поставщика или снесённая модель ломают витрину целиком, а не одну
     * заявку, и цена ошибки здесь другая.
     */
    public function test_менеджер_к_справочникам_не_допущен(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get(ProductResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_администратор_справочники_видит(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(ProductResource::getUrl('index'))
            ->assertOk();
    }

    /** Уволенного сотрудника отключают флагом, а не удалением аккаунта. */
    public function test_отключённый_сотрудник_теряет_доступ(): void
    {
        $manager = User::factory()->manager()->create(['is_active' => false]);

        $this->actingAs($manager)
            ->get(ProductResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_страница_входа_открывается(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_разделы_админки_открываются(): void
    {
        $this->actingAs($this->admin());

        $resources = [
            ProductResource::class,
            CarBrandResource::class,
            SupplierResource::class,
            CarModelAliasResource::class,
            OrderResource::class,
            CustomerResource::class,
        ];

        foreach ($resources as $resource) {
            $this->get($resource::getUrl('index'))
                ->assertOk();
        }
    }

    public function test_карточка_товара_открывается_на_редактирование(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())
            ->get(ProductResource::getUrl('edit', ['record' => $product]))
            ->assertOk();
    }

    /** Счётчик в меню показывает очередь разбора, а не общее число товаров. */
    public function test_счётчик_в_меню_считает_только_очередь_проверки(): void
    {
        Product::factory()->count(3)->create(['needs_review' => false]);
        Product::factory()->count(2)->create(['needs_review' => true]);

        $this->assertSame('2', ProductResource::getNavigationBadge());
    }

    /** Менеджер возвращается в админку за новыми заявками — счёт идёт по ним. */
    public function test_счётчик_заявок_считает_только_новые(): void
    {
        Order::factory()->count(2)->status(OrderStatus::New)->create();
        Order::factory()->status(OrderStatus::Completed)->create();

        $this->assertSame('2', OrderResource::getNavigationBadge());
    }

    public function test_заявка_открывается_на_редактирование(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->admin())
            ->get(OrderResource::getUrl('edit', ['record' => $order]))
            ->assertOk();
    }

    public function test_пустая_очередь_не_показывает_счётчик(): void
    {
        Product::factory()->create(['needs_review' => false]);

        $this->assertNull(ProductResource::getNavigationBadge());
    }
}
