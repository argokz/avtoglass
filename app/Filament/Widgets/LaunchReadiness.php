<?php

namespace App\Filament\Widgets;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Filament\Pages\ManageSettings;
use App\Models\Order;
use App\Models\ServicePoint;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Готовность к запуску.
 *
 * Не про продажи, а про то, что сайт ещё нельзя открывать клиентам:
 * тестовая касса, пустой БИН, очередь без воркера, фискализация без ключей.
 * Видит только администратор — менеджеру эти цифры в работе не нужны.
 */
class LaunchReadiness extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    public static function canView(): bool
    {
        return Auth::user()?->role->canManageCatalog() ?? false;
    }

    protected function getStats(): array
    {
        $issues = $this->issues();

        $stats = [
            Stat::make('Готовность к запуску', $issues === [] ? 'Готово' : count($issues).' проблем')
                ->description($issues === []
                    ? 'Касса, реквизиты и очередь в порядке'
                    : implode(' · ', array_slice($issues, 0, 3)))
                ->color($issues === [] ? 'success' : 'danger')
                ->url(ManageSettings::getUrl()),
        ];

        foreach (array_slice($this->checks(), 0, 3) as $check) {
            $stats[] = Stat::make($check['label'], $check['ok'] ? 'Ок' : 'Нужно')
                ->description($check['detail'])
                ->color($check['ok'] ? 'success' : 'warning');
        }

        return $stats;
    }

    /** @return list<string> */
    private function issues(): array
    {
        return collect($this->checks())
            ->reject(fn (array $check) => $check['ok'])
            ->pluck('label')
            ->values()
            ->all();
    }

    /**
     * @return list<array{label:string,ok:bool,detail:string}>
     */
    private function checks(): array
    {
        $driver = (string) config('payments.driver', 'test');
        $fiscalOn = settings()->enabled('fiscal');
        $webkassaReady = filled(config('fiscal.webkassa.login'))
            && filled(config('fiscal.webkassa.password'))
            && filled(config('fiscal.webkassa.cashbox'));

        $pendingJobs = 0;
        if (Schema::hasTable('jobs')) {
            $pendingJobs = (int) DB::table('jobs')->count();
        }

        $staleJobs = 0;
        if (Schema::hasTable('jobs')) {
            $staleJobs = (int) DB::table('jobs')
                ->where('created_at', '<', now()->subMinutes(10)->getTimestamp())
                ->count();
        }

        $точки = $this->несостыковкиТочек();

        /*
         * Оптовая заявка ждёт рассмотрения: менеджер решает по ней счёт,
         * онлайн-оплату или оплату при получении. Дольше часа без
         * ответственного — это уже простаивающая сделка.
         */
        $оптЖдёт = Schema::hasTable('orders')
            ? (int) Order::query()
                ->where('source', OrderSource::Wholesale)
                ->where('status', OrderStatus::New)
                ->whereNull('manager_id')
                ->where('created_at', '<', now()->subHour())
                ->count()
            : 0;

        return [
            [
                'label' => 'Касса',
                'ok' => $driver !== 'test',
                'detail' => $driver === 'test'
                    ? 'PAYMENT_DRIVER=test — на бою деньги не придут'
                    : 'Драйвер: '.$driver,
            ],
            [
                'label' => 'БИН',
                'ok' => settings()->hasBin(),
                'detail' => settings()->hasBin()
                    ? 'Реквизиты заполнены'
                    : 'Счета без юридической силы — Настройки → Реквизиты',
            ],
            [
                'label' => 'Очередь',
                'ok' => $staleJobs === 0,
                'detail' => $staleJobs > 0
                    ? "{$staleJobs} задач старше 10 мин — нужен queue:work"
                    : ($pendingJobs > 0
                        ? "В очереди {$pendingJobs}, воркер, похоже, работает"
                        : 'Очередь пуста'),
            ],
            [
                'label' => 'ОФД',
                'ok' => ! $fiscalOn || $webkassaReady,
                'detail' => $fiscalOn && ! $webkassaReady
                    ? 'Фискализация включена, ключи Webkassa пусты'
                    : ($fiscalOn ? 'Webkassa настроена' : 'Фискализация выключена'),
            ],
            [
                'label' => 'Сервисные точки',
                'ok' => $точки === [],
                'detail' => $точки === []
                    ? 'Города точек совпадают с городами сайта'
                    : implode('; ', array_slice($точки, 0, 2)),
            ],
            [
                'label' => 'Наличие',
                'ok' => true,
                'detail' => settings()->enabled('display_stock')
                    ? 'Витрина показывает остатки — убедитесь, что они из 1С'
                    : 'На витрине «под заказ» (безопасно без 1С)',
            ],
            [
                'label' => 'Опт',
                'ok' => $оптЖдёт === 0,
                'detail' => $оптЖдёт > 0
                    ? "Оптовых заявок без менеджера дольше часа: {$оптЖдёт}"
                    : 'Оптовые заявки разобраны',
            ],
        ];
    }

    /**
     * Ищет точки, чей город расходится с настройками сайта или с собственным
     * адресом. Покупатель выбирает время на странице записи и видит там адрес:
     * «Караганда» в карточке и «Алматы» в адресе — это несделанная поездка
     * и потерянный клиент, а не косметика.
     *
     * @return list<string>
     */
    private function несостыковкиТочек(): array
    {
        if (! Schema::hasTable('service_points')) {
            return [];
        }

        $города = settings()->cities();
        $проблемы = [];

        foreach (ServicePoint::query()->where('is_active', true)->get() as $точка) {
            $город = (string) $точка->city;
            $адрес = (string) $точка->address;

            if ($город !== '' && ! in_array($город, $города, true)) {
                $проблемы[] = "{$точка->name}: город «{$город}» не значится на сайте";

                continue;
            }

            /*
             * Адрес пишут строкой и начинают городом. Сверяем только когда
             * в адресе явно назван другой город из списка: угадывать по
             * произвольному тексту нельзя, ложная тревога здесь хуже молчания.
             */
            foreach ($города as $другой) {
                if ($другой !== $город && mb_stripos($адрес, $другой) !== false) {
                    $проблемы[] = "{$точка->name}: город «{$город}», а адрес в «{$другой}»";

                    break;
                }
            }

            if ($город !== '' && mb_stripos($адрес, $город) === false && $адрес !== '') {
                $проблемы[] = "{$точка->name}: адрес не начинается с города «{$город}»";
            }
        }

        return $проблемы;
    }
}
