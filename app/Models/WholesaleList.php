<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сохранённый список оптовика. items — [{offer_id, qty}], без цен.
 *
 * remind_weekday делает список регулярной заявкой: в этот день он первым
 * ждёт на /opt, а если настроен WhatsApp — приходит напоминание. Заказ
 * сам не создаётся: проверяет и отправляет оптовик.
 */
class WholesaleList extends Model
{
    /** Больше — уже не «мои списки», а второй каталог. */
    public const MAX_PER_CUSTOMER = 20;

    /** ISO-день недели => как он звучит в «по понедельникам». */
    public const WEEKDAYS = [
        1 => 'понедельникам',
        2 => 'вторникам',
        3 => 'средам',
        4 => 'четвергам',
        5 => 'пятницам',
        6 => 'субботам',
        7 => 'воскресеньям',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'remind_weekday' => 'integer',
            'reminded_on' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isDueOn(CarbonImmutable $day): bool
    {
        return $this->remind_weekday === $day->dayOfWeekIso;
    }

    public function scheduleLabel(): ?string
    {
        return $this->remind_weekday ? 'по '.self::WEEKDAYS[$this->remind_weekday] : null;
    }
}
