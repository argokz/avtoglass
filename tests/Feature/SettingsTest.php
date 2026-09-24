<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageSettings;
use App\Models\Product;
use App\Models\User;
use App\Settings\AppSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_по_умолчанию_витрина_работает_как_раньше(): void
    {
        $this->assertTrue(settings()->enabled('booking'));
        $this->assertTrue(settings()->enabled('vin_lookup'));
        $this->assertTrue(settings()->enabled('cart'));
        $this->assertTrue(settings()->enabled('payments'));
        $this->assertFalse(settings()->enabled('fiscal'));
        $this->assertFalse(settings()->enabled('telegram_alerts'));
        $this->assertTrue(settings()->enabled('email_alerts'));
        $this->assertTrue(settings()->enabled('ai_assistant'));
        // Без 1С наличие на витрине выключено — иначе «есть» врёт
        $this->assertFalse(settings()->enabled('display_stock'));
    }

    public function test_страница_политики_открывается(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('Политика обработки персональных данных')
            ->assertSee('персональных данных');
    }

    public function test_выключенное_наличие_не_обещает_резерв(): void
    {
        settings()->save(['display_stock' => false, 'payments' => true]);

        $product = Product::factory()->create(['in_stock' => true, 'price_min' => 25000]);

        $this->get(route('catalog.product', $product))
            ->assertOk()
            ->assertSee('Под заказ')
            ->assertDontSee('Есть в наличии');
    }

    public function test_включённое_наличие_показывает_бейдж(): void
    {
        settings()->save(['display_stock' => true]);

        $product = Product::factory()->create(['in_stock' => true, 'price_min' => 25000]);

        $this->get(route('catalog.product', $product))
            ->assertOk()
            ->assertSee('Есть в наличии');
    }

    public function test_реквизиты_из_настроек_попадают_в_счёт(): void
    {
        settings()->save([
            'bin' => '990840000123',
            'legal_name' => 'ТОО «ТестСтекло»',
        ]);

        $this->assertTrue(settings()->hasBin());
        $this->assertSame('990840000123', settings()->requisites()['bin']);
        $this->assertSame('ТОО «ТестСтекло»', settings()->requisites()['legal_name']);
    }

    public function test_выключенный_vin_отдаёт_404(): void
    {
        settings()->save(['vin_lookup' => false]);

        $this->get(route('vin.form'))->assertNotFound();
    }

    public function test_выключенная_запись_отдаёт_404(): void
    {
        settings()->save(['booking' => false]);

        $this->get(route('booking.form'))->assertNotFound();
    }

    public function test_выключенная_корзина_не_принимает_товар(): void
    {
        settings()->save(['cart' => false]);

        $this->post(route('cart.add', Product::factory()->create()))
            ->assertNotFound();
    }

    public function test_карта_сайта_открывается(): void
    {
        $this->get(route('sitemap'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function test_роботы_не_индексируют_корзину_и_админку(): void
    {
        $this->get(route('robots'))
            ->assertOk()
            ->assertSee('Disallow: /admin', false)
            ->assertSee('Disallow: /korzina', false)
            ->assertSee('Disallow: /oplata', false);
    }

    public function test_карту_сайта_генерируют_по_расписанию(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command ?? $event->description ?? '');

        $this->assertTrue(
            $commands->contains(fn (string $command) => str_contains($command, 'sitemap:generate')),
            $commands->implode("\n")
        );
    }

    public function test_администратор_открывает_настройки(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(ManageSettings::getUrl())
            ->assertOk();
    }

    public function test_менеджер_настройки_не_откроет(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get(ManageSettings::getUrl())
            ->assertForbidden();
    }

    public function test_сохранение_настроек_выключает_витрину(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageSettings::class)
            ->set('data.whatsapp', false)
            ->call('save');

        $this->assertFalse(app(AppSettings::class)->enabled('whatsapp'));

        $this->get('/')
            ->assertOk()
            ->assertDontSee('wa.me/', false);
    }
}
