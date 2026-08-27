<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\UserRole;
use App\Logistics\ScheduleConflict;
use App\Logistics\Scheduler;
use App\Models\Assignment;
use App\Models\Order;
use App\Models\ServicePoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Двойная запись обнаруживается в тот момент, когда два клиента одновременно
 * заезжают на один пост. Поэтому проверки занятости закрыты тестами плотнее
 * всего остального в логистике.
 */
class SchedulerTest extends TestCase
{
    use RefreshDatabase;

    private Scheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scheduler = new Scheduler;
        // Фиксируем «сейчас»: тесты про расписание не должны зависеть от того,
        // в котором часу их запустили.
        Carbon::setTestNow('2026-09-01 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function tomorrow(string $time): Carbon
    {
        return Carbon::parse('2026-09-02 '.$time);
    }

    public function test_запись_на_свободное_время_создаёт_наряд(): void
    {
        $order = Order::factory()->create();
        $point = ServicePoint::factory()->create();

        $assignment = $this->scheduler->schedule(
            $order,
            AssignmentType::Installation,
            $this->tomorrow('10:00'),
            $point,
        );

        $this->assertNotNull($assignment);
        $this->assertSame(AssignmentStatus::Planned, $assignment->status);
        $this->assertSame(120, $assignment->duration_minutes);
    }

    /** С исполнителем наряд сразу «назначен» — человек уже знает о работе. */
    public function test_с_исполнителем_наряд_сразу_назначен(): void
    {
        $point = ServicePoint::factory()->create();
        $installer = User::factory()->role(UserRole::Installer)->create();

        $assignment = $this->scheduler->schedule(
            Order::factory()->create(),
            AssignmentType::Installation,
            $this->tomorrow('10:00'),
            $point,
            $installer,
        );

        $this->assertSame(AssignmentStatus::Assigned, $assignment->status);
    }

    public function test_единственный_пост_нельзя_занять_дважды(): void
    {
        $point = ServicePoint::factory()->create();  // один пост

        Assignment::factory()->create([
            'service_point_id' => $point->id,
            'scheduled_at' => $this->tomorrow('10:00'),
            'duration_minutes' => 120,
        ]);

        $conflicts = $this->scheduler->conflicts(
            AssignmentType::Installation,
            $this->tomorrow('11:00'),
            120,
            $point,
        );

        $this->assertContains(ScheduleConflict::PointBusy, $conflicts);
    }

    /** Два поста — две машины одновременно, это норма. */
    public function test_два_поста_принимают_две_машины(): void
    {
        $point = ServicePoint::factory()->bays(2)->create();

        Assignment::factory()->create([
            'service_point_id' => $point->id,
            'scheduled_at' => $this->tomorrow('10:00'),
            'duration_minutes' => 120,
        ]);

        $this->assertSame([], $this->scheduler->conflicts(
            AssignmentType::Installation,
            $this->tomorrow('10:00'),
            120,
            $point,
        ));
    }

    /** А третью на два поста — уже нет. */
    public function test_третья_машина_на_два_поста_не_помещается(): void
    {
        $point = ServicePoint::factory()->bays(2)->create();

        Assignment::factory()->count(2)->create([
            'service_point_id' => $point->id,
            'scheduled_at' => $this->tomorrow('10:00'),
            'duration_minutes' => 120,
        ]);

        $this->assertContains(ScheduleConflict::PointBusy, $this->scheduler->conflicts(
            AssignmentType::Installation,
            $this->tomorrow('10:30'),
            120,
            $point,
        ));
    }

    /** Наряд с 10 до 12 и следующий ровно с 12 не мешают друг другу. */
    public function test_наряды_встык_не_считаются_пересечением(): void
    {
        $point = ServicePoint::factory()->create();

        Assignment::factory()->create([
            'service_point_id' => $point->id,
            'scheduled_at' => $this->tomorrow('10:00'),
            'duration_minutes' => 120,
        ]);

        $this->assertSame([], $this->scheduler->conflicts(
            AssignmentType::Installation,
            $this->tomorrow('12:00'),
            120,
            $point,
        ));
    }

    /** Отменённый наряд пост не занимает. */
    public function test_несостоявшийся_наряд_освобождает_пост(): void
    {
        $point = ServicePoint::factory()->create();

        Assignment::factory()->status(AssignmentStatus::Failed)->create([
            'service_point_id' => $point->id,
            'scheduled_at' => $this->tomorrow('10:00'),
            'duration_minutes' => 120,
        ]);

        $this->assertSame([], $this->scheduler->conflicts(
            AssignmentType::Installation,
            $this->tomorrow('10:00'),
            120,
            $point,
        ));
    }

    public function test_курьер_не_может_быть_в_двух_местах(): void
    {
        $courier = User::factory()->courier()->create();

        Assignment::factory()->type(AssignmentType::Delivery)->create([
            'assignee_id' => $courier->id,
            'scheduled_at' => $this->tomorrow('10:00'),
        ]);

        $conflicts = $this->scheduler->conflicts(
            AssignmentType::Delivery,
            $this->tomorrow('10:20'),
            40,
            null,
            $courier,
        );

        $this->assertContains(ScheduleConflict::AssigneeBusy, $conflicts);
    }

    public function test_запись_вне_рабочих_часов_отклоняется(): void
    {
        $point = ServicePoint::factory()->create(['opens_at' => '09:00', 'closes_at' => '18:00']);

        $conflicts = $this->scheduler->conflicts(
            AssignmentType::Installation,
            $this->tomorrow('17:00'),
            120,   // закончится в 19:00, сервис уже закрыт
            $point,
        );

        $this->assertContains(ScheduleConflict::PointClosed, $conflicts);
    }

    public function test_запись_в_прошлое_отклоняется(): void
    {
        $conflicts = $this->scheduler->conflicts(
            AssignmentType::Installation,
            Carbon::parse('2026-08-30 10:00'),
            120,
        );

        $this->assertContains(ScheduleConflict::InThePast, $conflicts);
    }

    public function test_при_конфликте_наряд_не_создаётся(): void
    {
        $point = ServicePoint::factory()->create();

        Assignment::factory()->create([
            'service_point_id' => $point->id,
            'scheduled_at' => $this->tomorrow('10:00'),
            'duration_minutes' => 120,
        ]);

        $result = $this->scheduler->schedule(
            Order::factory()->create(),
            AssignmentType::Installation,
            $this->tomorrow('10:30'),
            $point,
        );

        $this->assertNull($result);
        $this->assertSame(1, Assignment::count());
    }

    /** Переносимый наряд не должен конфликтовать сам с собой. */
    public function test_перенос_наряда_не_конфликтует_сам_с_собой(): void
    {
        $point = ServicePoint::factory()->create();

        $assignment = Assignment::factory()->create([
            'service_point_id' => $point->id,
            'scheduled_at' => $this->tomorrow('10:00'),
            'duration_minutes' => 120,
        ]);

        $conflicts = $this->scheduler->conflicts(
            AssignmentType::Installation,
            $this->tomorrow('10:30'),
            120,
            $point,
            null,
            ignoreId: $assignment->id,
        );

        $this->assertSame([], $conflicts);
    }

    public function test_адрес_доставки_сохраняется_снимком(): void
    {
        $order = Order::factory()->create(['address' => 'Алматы, Абая 150']);

        $assignment = $this->scheduler->schedule(
            $order,
            AssignmentType::Delivery,
            $this->tomorrow('10:00'),
        );

        $order->update(['address' => 'Другой адрес']);

        $this->assertSame('Алматы, Абая 150', $assignment->fresh()->address);
    }

    // ------------------------------------------------------------------
    // свободные слоты
    // ------------------------------------------------------------------

    public function test_свободные_слоты_считаются_на_день(): void
    {
        $point = ServicePoint::factory()->create(['opens_at' => '09:00', 'closes_at' => '13:00']);

        $slots = $this->scheduler->availableSlots($point, $this->tomorrow('00:00'), 120);

        // 09:00, 09:30, 10:00, 10:30, 11:00 — последний заканчивается ровно в 13:00
        $this->assertCount(5, $slots);
        $this->assertSame('09:00', $slots[0]->format('H:i'));
        $this->assertSame('11:00', end($slots)->format('H:i'));
    }

    public function test_занятое_время_из_слотов_исчезает(): void
    {
        $point = ServicePoint::factory()->create(['opens_at' => '09:00', 'closes_at' => '13:00']);

        Assignment::factory()->create([
            'service_point_id' => $point->id,
            'scheduled_at' => $this->tomorrow('09:00'),
            'duration_minutes' => 120,
        ]);

        $slots = $this->scheduler->availableSlots($point, $this->tomorrow('00:00'), 120);

        $times = array_map(fn (Carbon $s) => $s->format('H:i'), $slots);

        $this->assertNotContains('09:00', $times);
        $this->assertNotContains('10:30', $times);
        $this->assertContains('11:00', $times);
    }
}
