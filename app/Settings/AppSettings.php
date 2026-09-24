<?php

namespace App\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Настройки, которые сотрудник меняет в админке.
 *
 * Конфиг остаётся запасным значением: пока строку в таблице не сохраняли,
 * работает то, что уже было в config() и .env. После сохранения источником
 * правды становится база — иначе «выключил оплату» снова включалась бы
 * при следующем деплое.
 *
 * В кеш кладём только то, что лежит в таблице. Значения по умолчанию
 * читаются каждый раз: тесты подменяют config(), и закешированный дефолт
 * спрятал бы эту подмену.
 */
class AppSettings
{
    public const CACHE_KEY = 'app_settings';

    public function enabled(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->stored()[$key] ?? null;

        if ($raw === null) {
            return $this->defaults()[$key] ?? $default;
        }

        return json_decode((string) $raw, true);
    }

    /** @param  array<string,mixed>  $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $this->defaults())) {
                continue;
            }

            // Пустые строки из формы не должны затирать запасной config null-ом
            // в местах, где мы читаем через (string) — храним как есть.
            Setting::query()->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value ?? '', JSON_UNESCAPED_UNICODE), 'updated_at' => now()]
            );
        }

        Cache::forget(self::CACHE_KEY);
    }

    /** Состояние формы админки: сохранённое поверх дефолтов. */
    public function formState(): array
    {
        $state = $this->defaults();

        foreach ($this->stored() as $key => $raw) {
            if (array_key_exists($key, $state)) {
                $state[$key] = json_decode((string) $raw, true);
            }
        }

        return $state;
    }

    /** Публичное имя на витрине и в шапке. */
    public function brandName(): string
    {
        $name = trim((string) $this->get('brand_name', ''));

        return $name !== '' ? $name : (string) config('company.name');
    }

    /** @return list<array{display:string,tel:string}> */
    public function phones(): array
    {
        $parsed = $this->parsePhones((string) $this->get('phones', ''));

        return $parsed !== [] ? $parsed : config('company.phones');
    }

    /** @return list<string> */
    public function cities(): array
    {
        $raw = $this->get('cities', '');

        // После сохранения из формы значение — строка; если вдруг пришёл массив, не ломаемся.
        if (is_array($raw)) {
            $cities = array_values(array_filter(array_map('trim', $raw)));
        } else {
            $cities = preg_split('/\R/u', (string) $raw) ?: [];
            $cities = array_values(array_filter(array_map('trim', $cities)));
        }

        $fallback = config('company.cities', ['Караганда']);

        return $cities !== [] ? $cities : (is_array($fallback) ? $fallback : ['Караганда']);
    }

    public function defaultCity(): string
    {
        $city = trim((string) $this->get('default_city', ''));

        return $city !== '' ? $city : (string) config('company.legal_city');
    }

    /** Чат на витрине: тумблер в CRM и хотя бы один ключ Gemini в .env. */
    public function aiChatEnabled(): bool
    {
        return $this->enabled('ai_assistant') && config('ai.keys') !== [];
    }

    public function whatsapp(): ?string
    {
        $number = preg_replace('/\D+/', '', (string) $this->get('whatsapp_number', '')) ?: null;

        return $number ?: config('company.whatsapp');
    }

    public function instagram(): ?string
    {
        $handle = ltrim(trim((string) $this->get('instagram', '')), '@');

        return $handle !== '' ? $handle : config('company.instagram');
    }

    public function hours(): string
    {
        $hours = trim((string) $this->get('hours', ''));

        return $hours !== '' ? $hours : (string) config('company.hours');
    }

    public function address(): string
    {
        $address = trim((string) $this->get('address', ''));

        return $address !== '' ? $address : (string) config('company.address');
    }

    public function email(): string
    {
        $email = trim((string) $this->get('email', ''));

        return $email !== '' ? $email : (string) config('company.email');
    }

    public function warrantyMonths(): int
    {
        $months = (int) $this->get('warranty_months', 0);

        return $months > 0 ? $months : (int) config('company.warranty_months', 12);
    }

    /**
     * Реквизиты для счетов и актов.
     *
     * @return array{
     *     legal_name:string,
     *     bin:?string,
     *     iik:?string,
     *     bank:?string,
     *     bik:?string,
     *     director:?string,
     *     vat_payer:bool
     * }
     */
    public function requisites(): array
    {
        $fallback = config('company.requisites', []);

        return [
            'legal_name' => $this->filledOr('legal_name', $fallback['legal_name'] ?? ''),
            'bin' => $this->nullableFilled('bin', $fallback['bin'] ?? null),
            'iik' => $this->nullableFilled('iik', $fallback['iik'] ?? null),
            'bank' => $this->nullableFilled('bank', $fallback['bank'] ?? null),
            'bik' => $this->nullableFilled('bik', $fallback['bik'] ?? null),
            'director' => $this->nullableFilled('director', $fallback['director'] ?? null),
            'vat_payer' => (bool) $this->get('vat_payer', (bool) ($fallback['vat_payer'] ?? false)),
        ];
    }

    public function hasBin(): bool
    {
        return filled($this->requisites()['bin']);
    }

    /** Текст политики: сохранённый или короткий дефолт для РК. */
    public function privacyPolicy(): string
    {
        $text = trim((string) $this->get('privacy_policy', ''));

        return $text !== '' ? $text : $this->defaultPrivacyPolicy();
    }

    /** @return array<string,mixed> */
    public function defaults(): array
    {
        $phones = collect(config('company.phones', []))
            ->map(fn (array $phone) => $phone['display'] ?? $phone['tel'] ?? '')
            ->filter()
            ->implode("\n");

        $requisites = config('company.requisites', []);

        return [
            'payments' => true,
            'booking' => true,
            'vin_lookup' => true,
            'whatsapp' => true,
            'telegram_alerts' => (bool) config('notifications.telegram.enabled'),
            'email_alerts' => true,
            'fiscal' => (bool) config('fiscal.enabled'),
            'cart' => true,
            'year_filter' => true,
            'privacy_consent' => true,
            'service_prices' => true,
            'cash_in_field' => true,
            'reservations' => true,
            /*
             * Порог оптовой заявки. 0 — любая: ограничение включают,
             * когда появится поток мелких заявок.
             */
            'wholesale_min_total' => 0,
            'wholesale_min_qty' => 0,
            /*
             * AI-помощник. Ключи живут только в .env; здесь — выключатель
             * и переопределение списка моделей/паузы из config/ai.php.
             */
            'ai_assistant' => true,
            'ai_models' => '',
            'ai_cooldown_minutes' => 0,
            /*
             * По умолчанию выкл: остатки пока из catalog:stock (случайные),
             * а не из 1С. Включать только когда склад настоящий.
             */
            'display_stock' => false,
            'cities' => implode("\n", config('company.cities', ['Караганда'])),
            'default_city' => (string) config('company.legal_city'),
            'phones' => $phones,
            'whatsapp_number' => (string) config('company.whatsapp'),
            'instagram' => (string) config('company.instagram'),
            'hours' => (string) config('company.hours'),
            'address' => (string) config('company.address'),
            'email' => (string) config('company.email'),
            'brand_name' => (string) config('company.name'),
            'legal_name' => (string) ($requisites['legal_name'] ?? ''),
            'bin' => (string) ($requisites['bin'] ?? ''),
            'iik' => (string) ($requisites['iik'] ?? ''),
            'bank' => (string) ($requisites['bank'] ?? ''),
            'bik' => (string) ($requisites['bik'] ?? ''),
            'director' => (string) ($requisites['director'] ?? ''),
            'vat_payer' => (bool) ($requisites['vat_payer'] ?? false),
            'warranty_months' => (int) config('company.warranty_months', 12),
            'privacy_policy' => $this->defaultPrivacyPolicy(),
        ];
    }

    private function defaultPrivacyPolicy(): string
    {
        $name = (string) config('company.requisites.legal_name', config('company.name'));
        $email = (string) config('company.email');
        $address = (string) config('company.address');

        return <<<TEXT
Политика обработки персональных данных

Оператор: {$name}, {$address}, {$email}.

Какие данные собираем
• имя и номер телефона — чтобы перезвонить по заявке;
• VIN, марка и модель автомобиля, комментарий к заявке — чтобы подобрать стекло;
• адрес доставки — если выбрали доставку.

Зачем
Данные нужны только для обработки заявки, согласования цены и времени замены или доставки, оформления документов и связи с вами. Мы не передаём их третьим лицам для рекламы.

Срок хранения
Данные заявки хранятся в течение срока, необходимого для исполнения договора и требований бухгалтерского и налогового учёта Республики Казахстан, после чего удаляются или обезличиваются.

Ваши права
Вы можете запросить уточнение, блокирование или уничтожение своих данных, написав на {$email} или позвонив по телефону, указанному на сайте.

Согласие
Отправляя заявку и отмечая согласие на форме, вы подтверждаете, что ознакомлены с этой политикой и соглашаетесь на обработку указанных данных в соответствии с Законом РК «О персональных данных и их защите».
TEXT;
    }

    private function filledOr(string $key, string $fallback): string
    {
        $value = trim((string) $this->get($key, ''));

        return $value !== '' ? $value : $fallback;
    }

    private function nullableFilled(string $key, mixed $fallback): ?string
    {
        $value = trim((string) $this->get($key, ''));

        if ($value !== '') {
            return $value;
        }

        $fallback = trim((string) $fallback);

        return $fallback !== '' ? $fallback : null;
    }

    /** @return array<string,string> */
    private function stored(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, now()->addHour(), function () {
                if (! Schema::hasTable('settings')) {
                    return [];
                }

                return Setting::query()->pluck('value', 'key')->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array{display:string,tel:string}> */
    private function parsePhones(string $raw): array
    {
        $lines = preg_split('/\R/u', $raw) ?: [];
        $phones = [];

        foreach ($lines as $line) {
            $display = trim($line);

            if ($display === '') {
                continue;
            }

            $digits = preg_replace('/\D+/', '', $display) ?? '';

            if ($digits === '') {
                continue;
            }

            if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
                $digits = '7'.substr($digits, 1);
            }

            $phones[] = [
                'display' => $display,
                'tel' => '+'.$digits,
            ];
        }

        return $phones;
    }
}
