<?php

namespace App\Console\Commands;

use App\Analytics\SalesReport;
use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\FulfillmentType;
use App\Enums\InteractionType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Assignment;
use App\Models\Customer;
use App\Models\Interaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ServicePoint;
use App\Models\Task;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Демонстрационная история заказов.
 *
 * Нужна затем, что на пустой базе не проверить ни воронку, ни выручку,
 * ни конверсию: все отчёты покажут нули и будут выглядеть работающими.
 *
 * История делается неровной намеренно — с отменами, неудачными оплатами
 * и заявками, застрявшими в работе. Ровный поток «заявка -> оплата ->
 * выполнено» приукрасил бы отчёты и скрыл ровно те места, ради которых
 * их и смотрят.
 */
class SeedDemoOrders extends Command
{
    protected $signature = 'demo:orders
        {--days=30 : За сколько дней создать историю}
        {--count=60 : Сколько заявок}
        {--fresh : Удалить прежние демо-данные}';

    protected $description = 'Создать демонстрационную историю заказов, оплат и нарядов';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->truncate();
        }

        $days = (int) $this->option('days');
        $count = (int) $this->option('count');

        $products = Product::where('is_published', true)
            ->whereNotNull('price_min')
            ->where('price_min', '>', 5000)
            ->inRandomOrder()
            ->limit(40)
            ->get();

        if ($products->isEmpty()) {
            $this->error('Нет опубликованных товаров — сначала соберите каталог.');

            return self::FAILURE;
        }

        $manager = $this->staff(UserRole::Manager, 'Менеджер Айгуль', 'manager@avtoglass.kz');
        $courier = $this->staff(UserRole::Courier, 'Курьер Ерлан', 'courier@avtoglass.kz');
        $installer = $this->staff(UserRole::Installer, 'Мастер Асхат', 'master@avtoglass.kz');
        $point = ServicePoint::firstOrCreate(
            ['name' => 'Сервис на Аэрологической'],
            ['address' => 'Караганда, ул. Аэрологическая, 22', 'bays' => 2]
        );

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        for ($i = 0; $i < $count; $i++) {
            DB::transaction(function () use ($products, $manager, $courier, $installer, $point, $days) {
                $this->makeOrder($products, $manager, $courier, $installer, $point, $days);
            });

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->report();

        return self::SUCCESS;
    }

    private function makeOrder($products, User $manager, User $courier, User $installer, ServicePoint $point, int $days): void
    {
        $createdAt = now()->subDays(random_int(0, $days))->setTime(random_int(9, 19), random_int(0, 59));

        $customer = Customer::create([
            'name' => $this->name(),
            'phone' => '77'.random_int(100000000, 799999999),
        ]);

        $product = $products->random();
        $price = (float) $product->price_min;

        $fulfillment = collect([
            FulfillmentType::Installation,
            FulfillmentType::Visit,
            FulfillmentType::Delivery,
            FulfillmentType::Pickup,
        ])->random();

        $order = Order::create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::New,
            'source' => collect(OrderSource::cases())->random(),
            'fulfillment' => $fulfillment,
            'address' => $fulfillment->needsAddress() ? $this->address() : null,
            'total_amount' => $price,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'price' => $price,
            'quantity' => 1,
        ]);

        Interaction::create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'type' => InteractionType::System,
            'body' => 'Заявка создана: '.$order->source->label(),
            'occurred_at' => $createdAt,
        ]);

        // Судьба заявки. Доли примерно как в живой рознице: часть отваливается
        // сразу, часть висит, и меньшая половина доходит до денег.
        $fate = random_int(1, 100);

        if ($fate <= 15) {
            $this->advance($order, OrderStatus::Cancelled, $createdAt, $manager, 'клиент нашёл дешевле');

            return;
        }

        $this->advance($order, OrderStatus::InProgress, $createdAt->copy()->addMinutes(random_int(5, 90)), $manager);

        if ($fate <= 40) {
            return;   // застряла в работе — менеджер не дожал
        }

        $paidAt = $createdAt->copy()->addHours(random_int(1, 20));
        $this->pay($order, $price, $paidAt, $fate > 45);

        if ($fate <= 45) {
            return;   // оплата не прошла, заявка повисла
        }

        $this->advance($order, OrderStatus::Confirmed, $paidAt, $manager);

        $scheduledAt = $paidAt->copy()->addDays(random_int(1, 4))->setTime(random_int(9, 17), [0, 30][random_int(0, 1)]);

        $assignment = Assignment::create([
            'order_id' => $order->id,
            'type' => $fulfillment === FulfillmentType::Delivery
                ? AssignmentType::Delivery
                : AssignmentType::Installation,
            'status' => AssignmentStatus::Assigned,
            'assignee_id' => $fulfillment === FulfillmentType::Delivery ? $courier->id : $installer->id,
            'service_point_id' => $fulfillment === FulfillmentType::Delivery ? null : $point->id,
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => $fulfillment === FulfillmentType::Delivery ? 40 : 120,
            'address' => $order->address,
            'created_at' => $paidAt,
        ]);

        $this->advance($order, OrderStatus::Scheduled, $paidAt, $manager);

        // Прошедшие наряды закрываем, будущие оставляем в работе
        if ($scheduledAt->isPast()) {
            $assignment->update([
                'status' => AssignmentStatus::Done,
                'started_at' => $scheduledAt,
                'finished_at' => $scheduledAt->copy()->addMinutes($assignment->duration_minutes),
            ]);

            $this->advance($order, OrderStatus::Completed, $scheduledAt, $manager);
        }
    }

    private function pay(Order $order, float $amount, Carbon $at, bool $success): void
    {
        Payment::create([
            'order_id' => $order->id,
            'provider' => 'test',
            'status' => $success ? PaymentStatus::Succeeded : PaymentStatus::Failed,
            'amount' => $amount,
            'currency' => 'KZT',
            'paid_at' => $success ? $at : null,
            'failure_reason' => $success ? null : 'Недостаточно средств',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** Двигает статус вместе с записью в журнале — как это делает живой код. */
    private function advance(Order $order, OrderStatus $to, Carbon $at, User $manager, ?string $reason = null): void
    {
        $from = $order->status;

        $order->forceFill([
            'status' => $to,
            'manager_id' => $order->manager_id ?? $manager->id,
            'cancel_reason' => $reason,
            'completed_at' => $to === OrderStatus::Completed ? $at : $order->completed_at,
            'updated_at' => $at,
        ])->save();

        Interaction::create([
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'user_id' => $manager->id,
            'type' => InteractionType::System,
            'body' => "Статус: {$from->label()} -> {$to->label()}",
            'occurred_at' => $at,
        ]);

        if ($to === OrderStatus::InProgress) {
            Task::create([
                'customer_id' => $order->customer_id,
                'order_id' => $order->id,
                'assigned_to' => $manager->id,
                'title' => "Перезвонить по заявке {$order->number}",
                'due_at' => $at->copy()->addMinutes(15),
                'status' => $at->isPast() ? TaskStatus::Done : TaskStatus::Open,
                'completed_at' => $at->isPast() ? $at->copy()->addMinutes(random_int(2, 40)) : null,
                'created_at' => $at,
            ]);
        }
    }

    private function staff(UserRole $role, string $name, string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => bcrypt('secret123'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function name(): string
    {
        $first = ['Айгуль', 'Ерлан', 'Данил', 'Асхат', 'Мария', 'Тимур', 'Гульнара', 'Сергей', 'Алия', 'Нурлан', 'Ольга', 'Бауыржан'];
        $last = ['Сериков', 'Ким', 'Абдуллин', 'Петров', 'Жумабаев', 'Смагулова', 'Иванов', 'Нурпеисов', 'Ли', 'Оспанова'];

        return $first[array_rand($first)].' '.$last[array_rand($last)];
    }

    private function address(): string
    {
        $streets = ['Бухар-Жырау', 'Абая', 'Ерубаева', 'Гоголя', 'Мира', 'Сатпаева', 'Лободы'];

        return 'Караганда, ул. '.$streets[array_rand($streets)].', '.random_int(1, 120);
    }

    private function truncate(): void
    {
        foreach (['assignments', 'tasks', 'interactions', 'payments', 'receipts', 'order_items', 'orders', 'customers'] as $table) {
            DB::table($table)->delete();
        }

        $this->warn('Прежние заказы удалены.');
    }

    private function report(): void
    {
        $month = new SalesReport(now()->subDays((int) $this->option('days')), now());

        $this->table(['Показатель', 'Значение'], [
            ['Заявок', $month->ordersCount()],
            ['Выручка', number_format($month->revenue(), 0, ',', ' ').' ₸'],
            ['Оплат', $month->paymentsCount()],
            ['Средний чек', number_format($month->averageCheck(), 0, ',', ' ').' ₸'],
            ['Конверсия', $month->conversion().' %'],
            ['Отменено', $month->cancelledCount()],
            ['Нарядов', Assignment::count()],
        ]);
    }
}
