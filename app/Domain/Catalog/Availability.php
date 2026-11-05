<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Offer;
use Carbon\CarbonImmutable;

/**
 * «Когда будет стекло» — главный вопрос и водителя, и установщика.
 *
 * Отвечаем только тем, что знаем. Остаток — со склада; срок под заказ —
 * из предложения или, если там пусто, из карточки поставщика. Ноль и
 * пустое значение означают «срок не задан»: тогда честно говорим, что
 * его назовёт менеджер, а не рисуем «3–5 дней» из головы.
 */
final class Availability
{
    public const IN_STOCK = 'in_stock';

    public const LEAD = 'lead';

    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $state,
        public readonly ?int $days = null,
        public readonly ?CarbonImmutable $readyBy = null,
    ) {}

    /** $available — свободный остаток, если вызывающий уже посчитал его без лишнего запроса. */
    public static function forOffer(Offer $offer, ?int $available = null, ?CarbonImmutable $now = null): self
    {
        $available ??= $offer->available();
        if ($available > 0 && settings()->enabled('display_stock')) {
            return new self(self::IN_STOCK);
        }

        return self::lead(self::leadDays($offer), $now);
    }

    /** Товар без предложений: знаем только общий флаг из карточки. */
    public static function forProductFlag(bool $inStock): self
    {
        return $inStock && settings()->enabled('display_stock') ? new self(self::IN_STOCK) : new self(self::UNKNOWN);
    }

    public static function leadDays(Offer $offer): ?int
    {
        $days = (int) ($offer->lead_days ?? 0) ?: (int) ($offer->supplier?->default_lead_days ?? 0);

        return $days > 0 ? $days : null;
    }

    public static function lead(?int $days, ?CarbonImmutable $now = null): self
    {
        if ($days === null || $days <= 0) {
            return new self(self::UNKNOWN);
        }
        $now ??= CarbonImmutable::now(config('app.timezone'));

        return new self(self::LEAD, $days, $now->addDays($days)->startOfDay());
    }

    /** Строка для карточки: «В наличии», «Под заказ · 3 дн., ориентировочно к 12 ноября». */
    public function label(bool $kk = false): string
    {
        return match ($this->state) {
            self::IN_STOCK => $kk ? 'Қорда бар' : 'Есть в наличии',
            self::LEAD => ($kk ? 'Тапсырыспен · ' : 'Под заказ · ').$this->days.($kk ? ' күн, шамамен ' : ' дн., ориентировочно к ')
                .$this->readyBy->locale($kk ? 'kk' : 'ru')->translatedFormat('j F'),
            default => $kk ? 'Тапсырыспен · мерзімін менеджер айтады' : 'Под заказ · срок назовёт менеджер',
        };
    }

    /** Коротко для таблицы и чата: «в наличии», «3 дн.», «срок уточним». */
    public function short(bool $kk = false): string
    {
        return match ($this->state) {
            self::IN_STOCK => $kk ? 'қорда бар' : 'в наличии',
            self::LEAD => $this->days.($kk ? ' күн' : ' дн.'),
            default => $kk ? 'мерзімі нақтыланады' : 'срок уточним',
        };
    }

    /** Обратно из результата поиска — чтобы шаблон не ходил за остатком второй раз. */
    public static function fromArray(?array $data): self
    {
        return match ($data['state'] ?? null) {
            self::IN_STOCK => new self(self::IN_STOCK),
            self::LEAD => new self(self::LEAD, (int) $data['days'], CarbonImmutable::parse((string) $data['ready_by'], config('app.timezone'))),
            default => new self(self::UNKNOWN),
        };
    }

    /** @return array{state:string,days:?int,ready_by:?string} */
    public function toArray(): array
    {
        return ['state' => $this->state, 'days' => $this->days, 'ready_by' => $this->readyBy?->toDateString()];
    }
}
