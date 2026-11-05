<?php

namespace App\Console\Commands;

use App\Domain\Assistant\PersonalDataCleanup;
use App\Enums\OrderStatus;
use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Анонимизация персональных данных по сроку хранения.
 *
 * ЗРК «О персональных данных» требует хранить ПДн не дольше, чем нужно
 * для цели сбора. Заказ как бухгалтерский документ остаётся, а вот контакты
 * человека, чья последняя заявка закрыта дольше срока хранения, заменяются
 * срезом: по ним нельзя ни позвонить, ни узнать адрес.
 *
 * Клиентов с живым кабинетом не трогаем: человек сам держит аккаунт
 * и может удалить его сам.
 */
class AnonymizePersonalData extends Command
{
    protected $signature = 'pdn:anonymize {--dry-run : Показать, сколько карточек под анонимизацию}';

    protected $description = 'Анонимизировать клиентов, чьи заказы закрыты дольше срока хранения ПДн';

    public function handle(): int
    {
        $months = max(1, (int) config('security.pdn_retention_months', 36));
        $cutoff = now()->subMonths($months);

        $final = collect(OrderStatus::cases())
            ->filter(fn (OrderStatus $status) => $status->isFinal())
            ->map(fn (OrderStatus $status) => $status->value)
            ->all();

        $query = Customer::query()
            ->where('created_at', '<', $cutoff)
            // Есть кабинет — человек управляет своими данными сам.
            ->whereDoesntHave('account')
            // Нет незакрытых заявок.
            ->whereDoesntHave('orders', fn ($orders) => $orders->whereNotIn('status', $final))
            // И нет свежих: срок считается от последнего обращения.
            ->whereDoesntHave('orders', fn ($orders) => $orders->where('created_at', '>=', $cutoff))
            // Уже анонимизированные не трогаем.
            ->where('name', 'not like', 'Анонимизирован%');

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Под анонимизацию (срок {$months} мес.): {$count} карточек");

            return self::SUCCESS;
        }

        $done = 0;

        $query->chunkById(100, function ($customers) use (&$done) {
            foreach ($customers as $customer) {
                DB::transaction(function () use ($customer) {
                    app(PersonalDataCleanup::class)->customer($customer);
                    // Телефон заменяем техническим номером: уникальность phone_hash
                    // и ссылочная целостность сохраняются, позвонить по нему нельзя.
                    $customer->forceFill([
                        'name' => 'Анонимизирован #'.$customer->id,
                        'phone' => '900000'.str_pad((string) $customer->id, 5, '0', STR_PAD_LEFT),
                        'email' => null,
                        'note' => null,
                        'default_address' => null,
                        'default_city' => null,
                        'preferred_fulfillment' => null,
                        'car_model_id' => null,
                        'car_plate' => null,
                        'vin' => null,
                        'company_name' => null,
                        'bin' => null,
                    ])->save();

                    $customer->orders()->update(['address' => null]);
                });

                $done++;
            }
        });

        $this->info("Анонимизировано карточек: {$done}");

        return self::SUCCESS;
    }
}
