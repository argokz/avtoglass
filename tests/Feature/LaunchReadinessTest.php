<?php

namespace Tests\Feature;

use App\Filament\Widgets\LaunchReadiness;
use App\Models\ServicePoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LaunchReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_админ_видит_виджет_готовности(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->assertTrue(LaunchReadiness::canView());
    }

    public function test_менеджер_виджет_не_видит(): void
    {
        $this->actingAs(User::factory()->manager()->create());

        $this->assertFalse(LaunchReadiness::canView());
    }

    public function test_зависшая_очередь_считается_проблемой(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subHour()->getTimestamp(),
            'created_at' => now()->subHour()->getTimestamp(),
        ]);

        $widget = app(LaunchReadiness::class);
        $method = new \ReflectionMethod($widget, 'issues');
        $method->setAccessible(true);

        $issues = $method->invoke($widget);

        $this->assertContains('Очередь', $issues);
        $this->assertContains('Касса', $issues);
    }

    /**
     * Точка в чужом городе — не косметика: покупатель выбирает время, видит
     * адрес и едет не туда. Проверка ловит это до клиента.
     */
    public function test_точка_в_чужом_городе_считается_проблемой(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        ServicePoint::factory()->create([
            'name' => 'Сервис на Абая',
            'city' => 'Караганда',
            'address' => 'Алматы, пр. Абая 150',
            'is_active' => true,
        ]);

        $this->assertContains('Сервисные точки', $this->issues());
    }

    public function test_согласованная_точка_проблемой_не_считается(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        ServicePoint::factory()->create([
            'name' => 'Сервис на Аэрологической',
            'city' => 'Караганда',
            'address' => 'Караганда, ул. Аэрологическая, 22',
            'is_active' => true,
        ]);

        $this->assertNotContains('Сервисные точки', $this->issues());
    }

    /** Выключенную точку покупатель не видит — и тревожить из-за неё незачем. */
    public function test_выключенная_точка_не_тревожит(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        ServicePoint::factory()->create([
            'city' => 'Караганда',
            'address' => 'Алматы, пр. Абая 150',
            'is_active' => false,
        ]);

        $this->assertNotContains('Сервисные точки', $this->issues());
    }

    public function test_оптовая_заявка_без_менеджера_дольше_часа_проблема(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        \App\Models\Order::factory()->create([
            'source' => \App\Enums\OrderSource::Wholesale,
            'status' => \App\Enums\OrderStatus::New,
            'manager_id' => null,
            'created_at' => now()->subHours(2),
        ]);

        $this->assertContains('Опт', $this->issues());
    }

    public function test_свежая_оптовая_заявка_не_тревожит(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        \App\Models\Order::factory()->create([
            'source' => \App\Enums\OrderSource::Wholesale,
            'status' => \App\Enums\OrderStatus::New,
            'manager_id' => null,
            'created_at' => now(),
        ]);

        $this->assertNotContains('Опт', $this->issues());
    }

    /** @return list<string> */
    private function issues(): array
    {
        $widget = app(LaunchReadiness::class);
        $method = new \ReflectionMethod($widget, 'issues');
        $method->setAccessible(true);

        return $method->invoke($widget);
    }
}
