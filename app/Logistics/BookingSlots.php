<?php

namespace App\Logistics;

use App\Models\Service;
use App\Models\ServicePoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Окна, которые можно показать клиенту.
 *
 * Планировщик считает занятость постов и без этого класса, но отдавать его
 * ответ покупателю как есть нельзя: он увидит время через двадцать минут
 * и запишется, а стекло ещё лежит на складе и мастер не предупреждён.
 * Здесь на голую занятость накладываются правила живого сервиса — запас
 * на подготовку, горизонт записи и время на загнать-выгнать машину.
 */
class BookingSlots
{
    public function __construct(private readonly Scheduler $scheduler) {}

    /**
     * Сколько времени бронировать под работу.
     *
     * К длительности самой работы добавляем запас: машину надо загнать,
     * оформить и выгнать. Без него посты встают впритык, и первая же задержка
     * ломает весь день.
     */
    public function duration(?Service $service): int
    {
        $work = $service?->duration_minutes ?? 60;

        return $work + (int) config('booking.buffer_minutes');
    }

    /**
     * Раньше этого момента записывать нельзя.
     *
     * Публичный, потому что то же правило проверяется второй раз при записи:
     * между показом окон и нажатием кнопки проходит время.
     */
    public function earliest(): Carbon
    {
        return now()->addHours((int) config('booking.lead_hours'));
    }

    public function latest(): Carbon
    {
        return now()->addDays((int) config('booking.horizon_days'))->endOfDay();
    }

    public function allows(Carbon $start): bool
    {
        return $start->gte($this->earliest()) && $start->lte($this->latest());
    }

    /**
     * Дни, в которые есть хотя бы одно окно.
     *
     * Показывать пустой день — значит заставить клиента тыкать по календарю
     * наугад. Поэтому дни без окон в выбор не попадают вовсе.
     *
     * @return Collection<int,Carbon>
     */
    public function days(ServicePoint $point, ?Service $service): Collection
    {
        $days = collect();
        $cursor = $this->earliest()->copy()->startOfDay();
        $last = $this->latest();

        while ($cursor->lte($last)) {
            if ($this->slots($point, $cursor->copy(), $service)->isNotEmpty()) {
                $days->push($cursor->copy());
            }

            $cursor->addDay();
        }

        return $days;
    }

    /**
     * Свободные начала на конкретный день, уже с учётом запаса и горизонта.
     *
     * @return Collection<int,Carbon>
     */
    public function slots(ServicePoint $point, Carbon $day, ?Service $service): Collection
    {
        return collect($this->scheduler->availableSlots(
            $point,
            $day->copy()->startOfDay(),
            $this->duration($service),
            (int) config('booking.step_minutes'),
        ))->filter(fn (Carbon $slot) => $this->allows($slot))->values();
    }
}
