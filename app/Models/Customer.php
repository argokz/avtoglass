<?php

namespace App\Models;

use App\Enums\WholesaleStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Crypt;

class Customer extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'wholesale_status' => WholesaleStatus::Retail->value,
    ];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            if ($customer->wholesale_status === WholesaleStatus::Wholesale && $customer->wholesale_approved_at === null) {
                $customer->wholesale_approved_at = now();
            }

            // Слепой индекс телефона: по шифротексту искать нельзя.
            $customer->phone_hash = self::phoneHash($customer->phone);
        });
    }

    protected function casts(): array
    {
        return [
            // Персональные данные лежат в шифротексте: дамп базы их не раскрывает.
            'phone' => 'encrypted',
            'email' => 'encrypted',
            'default_address' => 'encrypted',
            'vin' => 'encrypted',
            'car_plate' => 'encrypted',
            'company_name' => 'encrypted',
            'bin' => 'encrypted',
            'wholesale_status' => WholesaleStatus::class,
            'wholesale_requested_at' => 'datetime',
            'wholesale_approved_at' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class)->latest('occurred_at');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function carModel(): BelongsTo
    {
        return $this->belongsTo(CarModel::class);
    }

    public function account(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function seesWholesalePrices(): bool
    {
        return $this->wholesale_status?->seesWholesalePrices() === true;
    }

    /**
     * Заявка на опт. Цены не меняются, пока менеджер не переведёт статус.
     */
    public function requestWholesale(string $company, ?string $bin = null): void
    {
        if ($this->seesWholesalePrices()) {
            return;
        }

        $alreadyPending = $this->wholesale_status === WholesaleStatus::Pending;

        $this->forceFill([
            'wholesale_status' => WholesaleStatus::Pending,
            'company_name' => trim($company) ?: $this->company_name,
            'bin' => $bin ?: $this->bin,
            'wholesale_requested_at' => $this->wholesale_requested_at ?? now(),
        ])->save();

        if ($alreadyPending) {
            return;
        }

        Task::create([
            'customer_id' => $this->id,
            'title' => 'Опт: '.($this->company_name ?: $this->name),
            'description' => trim(implode("\n", array_filter([
                'Клиент просит оптовые цены.',
                $this->company_name ? 'Организация: '.$this->company_name : null,
                $this->bin ? 'БИН/ИИН: '.$this->bin : null,
                'Телефон: '.$this->phone,
            ]))),
            'due_at' => now()->addHours(4),
        ]);
    }

    public function approveWholesale(?int $discountPercent = null): void
    {
        $this->forceFill([
            'wholesale_status' => WholesaleStatus::Wholesale,
            'wholesale_discount_percent' => $discountPercent ?? $this->wholesale_discount_percent,
            'wholesale_approved_at' => $this->wholesale_approved_at ?? now(),
        ])->save();
    }

    /**
     * Оптовая цена от розницы. Пол не даём пробить: иначе продаём ниже закупа.
     */
    public function wholesalePrice(float $retail, ?float $cost = null): float
    {
        $discount = max(0, min(40, (int) ($this->wholesale_discount_percent ?? 0)));
        $price = $discount > 0 ? round($retail * (1 - $discount / 100), -1) : $retail;

        if ($cost !== null && $cost > 0) {
            $price = max($price, $cost);
        }

        return $price;
    }

    public function carLabel(): ?string
    {
        $parts = array_filter([
            $this->carModel
                ? trim(($this->carModel->brand?->name ?? '').' '.$this->carModel->name)
                : null,
            $this->car_plate ? 'госномер '.$this->car_plate : null,
            $this->vin ? 'VIN '.$this->vin : null,
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }

    /**
     * Запоминает адрес и способ получения после заявки.
     * Перезаписываем последние значения: человек обычно ездит по одному адресу.
     */
    public function rememberDelivery(?string $address, ?string $city, ?string $fulfillment): void
    {
        $updates = [];

        if (is_string($address) && trim($address) !== '') {
            $updates['default_address'] = trim($address);
        }

        if (is_string($city) && trim($city) !== '') {
            $updates['default_city'] = trim($city);
        }

        if (is_string($fulfillment) && $fulfillment !== '') {
            $updates['preferred_fulfillment'] = $fulfillment;
        }

        if ($updates !== []) {
            $this->update($updates);
        }
    }

    /**
     * Приводит телефон к цифрам, чтобы «+7 700 000-00-00» и «87000000000»
     * не заводили двух разных клиентов.
     *
     * Казахстанские номера пишут и через 8, и через +7 — ведущую восьмёрку
     * заменяем на семёрку, иначе один и тот же человек раздвоится.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/u', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (mb_strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.mb_substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Слепой индекс телефона: HMAC от нормализованного номера.
     *
     * Сам телефон хранится шифрованным, и каждый раз под новым IV — искать
     * по нему нельзя. HMAC детерминирован, поэтому равенство по хэшу
     * заменяет равенство по номеру, а без ключа приложения из хэша
     * номер не восстановить.
     */
    public static function phoneHash(?string $phone): ?string
    {
        $digits = self::normalizePhone($phone);

        if ($digits === null) {
            return null;
        }

        return hash_hmac('sha256', $digits, (string) config('app.key'));
    }

    /**
     * Поиск клиента для селектов CRM: по имени подстрокой, по телефону — хэшем.
     *
     * @return array<int,string>
     */
    public static function searchForSelect(string $term, int $limit = 50): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $hash = self::phoneHash($term);

        return static::query()
            ->where(function ($query) use ($term, $hash) {
                $query->where('name', 'ilike', '%'.$term.'%');

                if ($hash !== null && mb_strlen(preg_replace('/\D+/u', '', $term) ?? '') >= 10) {
                    $query->orWhere('phone_hash', $hash);
                }
            })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Customer $customer) => [
                $customer->id => $customer->name.' · '.телефонДляГлаз($customer->phone),
            ])
            ->all();
    }

    /** Находит клиента по телефону или заводит нового. */
    public static function forPhone(string $phone, string $name): self
    {
        $normalized = self::normalizePhone($phone) ?? $phone;
        $hash = self::phoneHash($normalized);

        $customer = static::where('phone_hash', $hash)->first();

        if ($customer === null) {
            /*
             * Гонку разруливаем на стороне базы, а не исключением.
             *
             * Пока мы искали, тот же человек мог отправить вторую заявку, и его
             * карточку успели создать. Ловить на это UniqueConstraintViolation
             * нельзя: заявка создаётся внутри транзакции, а PostgreSQL после
             * любой упавшей команды обрывает всю транзакцию — следующий запрос
             * вернёт «current transaction is aborted», и вместо одного клиента
             * получится ошибка оформления.
             *
             * insertOrIgnore до ошибки не доводит: конфликт по phone_hash просто
             * ничего не вставляет, и мы забираем ту карточку, которая победила.
             * Касты здесь не работают — шифруем вручную.
             */
            static::insertOrIgnore([
                'phone' => Crypt::encryptString($normalized),
                'phone_hash' => $hash,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $customer = static::where('phone_hash', $hash)->firstOrFail();
        }

        // Имя обновляем, только если раньше его не знали: менеджер мог
        // поправить его руками, и форма с сайта не должна это затирать.
        if (trim((string) $customer->name) === '') {
            $customer->update(['name' => $name]);
        }

        return $customer;
    }
}
