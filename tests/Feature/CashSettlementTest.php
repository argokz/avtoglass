<?php

namespace Tests\Feature;

use App\Actions\AcceptCashPayment;
use App\Actions\SettleCash;
use App\Analytics\CashOnHand;
use App\Enums\PaymentStatus;
use App\Filament\Field\Pages\MyAssignments;
use App\Filament\Pages\CashDesk;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Инкассация: приём наличных в кассу.
 *
 * Приём денег от клиента был закрыт — известно, кто взял и сколько. Но между
 * «курьер взял на адресе» и «деньги в кассе» стоит поездка, и она нигде
 * не отражалась: поле settled_at существовало, а заполнять его было нечем.
 * Система знала, что деньги получены, и не знала, дошли ли они.
 */
class CashSettlementTest extends TestCase
{
    use RefreshDatabase;

    private function cashPayment(User $courier, float $amount = 30000): Payment
    {
        $order = Order::factory()->create(['total_amount' => $amount]);
        $order->items()->create(['name' => 'Лобовое стекло', 'price' => $amount, 'quantity' => 1]);

        return app(AcceptCashPayment::class)->handle($order->fresh(), $amount, $courier);
    }

    // ------------------------------------------------------------------
    // сводка
    // ------------------------------------------------------------------

    public function test_принятые_наличные_числятся_за_курьером(): void
    {
        $courier = User::factory()->courier()->create(['name' => 'Курьер Ерлан']);

        $this->cashPayment($courier, 25000);
        $this->cashPayment($courier, 15000);

        $holders = app(CashOnHand::class)->byPerson();

        $this->assertCount(1, $holders);
        $this->assertSame('Курьер Ерлан', $holders->first()->user->name);
        $this->assertSame(40000.0, $holders->first()->total);
        $this->assertSame(2, $holders->first()->count);
    }

    /** Карточные платежи на руках не лежат — им в сводке инкассации не место. */
    public function test_безналичные_в_сводку_не_попадают(): void
    {
        $order = Order::factory()->create(['total_amount' => 30000]);

        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Succeeded,
            'amount' => 30000,
            'paid_at' => now(),
        ]);

        $this->assertSame(0.0, app(CashOnHand::class)->total());
    }

    public function test_сданное_из_сводки_исчезает(): void
    {
        $courier = User::factory()->courier()->create();
        $cashier = User::factory()->manager()->create();

        $this->cashPayment($courier, 25000);

        app(SettleCash::class)->handle($courier, $cashier);

        $this->assertSame(0.0, app(CashOnHand::class)->total());
    }

    // ------------------------------------------------------------------
    // приём
    // ------------------------------------------------------------------

    public function test_приём_записывает_кто_принял_и_когда(): void
    {
        $courier = User::factory()->courier()->create();
        $cashier = User::factory()->manager()->create(['name' => 'Кассир Айгуль']);

        $payment = $this->cashPayment($courier, 25000);

        $this->assertSame(1, app(SettleCash::class)->handle($courier, $cashier));

        $payment->refresh();

        $this->assertNotNull($payment->settled_at);
        $this->assertSame($cashier->id, $payment->settled_by);
        $this->assertTrue($payment->isSettled());
        // Первая подпись остаётся: кто взял у клиента, тоже видно
        $this->assertSame($courier->id, $payment->received_by);
    }

    /**
     * Кассир пересчитывает деньги в руках. Не сошлось — принимать нельзя:
     * это тот момент, когда расхождение ещё можно разобрать.
     */
    public function test_при_расхождении_сумм_приём_не_проходит(): void
    {
        $courier = User::factory()->courier()->create();
        $cashier = User::factory()->manager()->create();

        $this->cashPayment($courier, 25000);

        $this->assertSame(0, app(SettleCash::class)->handle($courier, $cashier, countedAmount: 20000));
        $this->assertSame(25000.0, app(CashOnHand::class)->total(), 'деньги остались за курьером');
    }

    public function test_совпавшая_сумма_принимается(): void
    {
        $courier = User::factory()->courier()->create();
        $cashier = User::factory()->manager()->create();

        $this->cashPayment($courier, 25000);

        $this->assertSame(1, app(SettleCash::class)->handle($courier, $cashier, countedAmount: 25000));
    }

    public function test_приём_попадает_в_журнал_заявки(): void
    {
        $courier = User::factory()->courier()->create(['name' => 'Курьер Ерлан']);
        $cashier = User::factory()->manager()->create(['name' => 'Кассир Айгуль']);

        $payment = $this->cashPayment($courier, 25000);

        app(SettleCash::class)->handle($courier, $cashier);

        $entry = $payment->order->interactions()->where('body', 'like', '%сданы в кассу%')->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString('Курьер Ерлан', $entry->body);
        $this->assertStringContainsString('Кассир Айгуль', $entry->body);
    }

    public function test_повторный_приём_ничего_не_закрывает(): void
    {
        $courier = User::factory()->courier()->create();
        $cashier = User::factory()->manager()->create();

        $this->cashPayment($courier, 25000);

        app(SettleCash::class)->handle($courier, $cashier);

        $this->assertSame(0, app(SettleCash::class)->handle($courier, $cashier));
    }

    /** Деньги другого человека этот приём не трогает. */
    public function test_приём_закрывает_только_своего_курьера(): void
    {
        $first = User::factory()->courier()->create();
        $second = User::factory()->courier()->create();
        $cashier = User::factory()->manager()->create();

        $this->cashPayment($first, 25000);
        $this->cashPayment($second, 15000);

        app(SettleCash::class)->handle($first, $cashier);

        $this->assertSame(15000.0, app(CashOnHand::class)->total());
        $this->assertSame(15000.0, app(CashOnHand::class)->totalFor($second));
    }

    // ------------------------------------------------------------------
    // экран
    // ------------------------------------------------------------------

    public function test_экран_кассы_показывает_держателей(): void
    {
        $courier = User::factory()->courier()->create(['name' => 'Курьер Ерлан']);
        $this->cashPayment($courier, 25000);

        Livewire::actingAs(User::factory()->manager()->create())
            ->test(CashDesk::class)
            ->assertSee('Курьер Ерлан')
            ->assertSee('25 000');
    }

    public function test_кассир_принимает_с_экрана(): void
    {
        $courier = User::factory()->courier()->create();
        $cashier = User::factory()->manager()->create();

        $this->cashPayment($courier, 25000);

        Livewire::actingAs($cashier)
            ->test(CashDesk::class)
            ->call('accept', $courier->id);

        $this->assertSame(0.0, app(CashOnHand::class)->total());
    }

    /**
     * Вторая подпись обязана быть чужой: отметь человек свои же деньги
     * сданными — и проверка перестаёт быть проверкой.
     */
    public function test_свои_деньги_принять_нельзя(): void
    {
        $manager = User::factory()->manager()->create();

        $this->cashPayment($manager, 25000);

        Livewire::actingAs($manager)
            ->test(CashDesk::class)
            ->call('accept', $manager->id);

        $this->assertSame(25000.0, app(CashOnHand::class)->total());
    }

    public function test_курьер_видит_сколько_на_нём_висит(): void
    {
        $courier = User::factory()->courier()->create();
        $this->cashPayment($courier, 25000);

        Livewire::actingAs($courier)
            ->test(MyAssignments::class)
            ->assertSee('У вас на руках')
            ->assertSee('25 000');
    }

    /** Курьеру в кассу хода нет: он сдаёт, а не принимает. */
    public function test_курьер_в_кассу_не_допущен(): void
    {
        $this->actingAs(User::factory()->courier()->create())
            ->get(CashDesk::getUrl())
            ->assertForbidden();
    }
}
