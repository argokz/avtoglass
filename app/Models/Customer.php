<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $guarded = [];

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

    /** Находит клиента по телефону или заводит нового. */
    public static function forPhone(string $phone, string $name): self
    {
        $normalized = self::normalizePhone($phone) ?? $phone;

        $customer = static::where('phone', $normalized)->first();

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
             * insertOrIgnore до ошибки не доводит: конфликт по телефону просто
             * ничего не вставляет, и мы забираем ту карточку, которая победила.
             */
            static::insertOrIgnore([
                'phone' => $normalized,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $customer = static::where('phone', $normalized)->firstOrFail();
        }

        // Имя обновляем, только если раньше его не знали: менеджер мог
        // поправить его руками, и форма с сайта не должна это затирать.
        if (trim((string) $customer->name) === '') {
            $customer->update(['name' => $name]);
        }

        return $customer;
    }
}
