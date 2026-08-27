<?php

namespace App\Enums;

/**
 * Путь заявки от звонка до выданной машины.
 *
 * Статусы описывают именно стекольный процесс, а не абстрактную воронку:
 * между «согласован» и «выполнен» лежит доставка или установка, и заказ
 * может неделю ждать стекло под заказ — это нормальное состояние, а не застой.
 */
enum OrderStatus: string
{
    case New = 'new';         // пришла с сайта, менеджер ещё не смотрел
    case InProgress = 'in_progress'; // уточняем наличие и цену с работами
    case Confirmed = 'confirmed';   // клиент согласился, стекло зарезервировано
    case Scheduled = 'scheduled';   // назначены доставка или установка
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новая',
            self::InProgress => 'В работе',
            self::Confirmed => 'Согласован',
            self::Scheduled => 'Назначен',
            self::Completed => 'Выполнен',
            self::Cancelled => 'Отменён',
        };
    }

    /** Цвет для бейджа в админке. */
    public function color(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::InProgress => 'info',
            self::Confirmed => 'primary',
            self::Scheduled => 'primary',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** Заказ закрыт — по нему больше ничего не ждут. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** В какие статусы можно уйти отсюда. Отмена доступна из любого живого. */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::InProgress, self::Confirmed, self::Cancelled],
            self::InProgress => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Scheduled, self::Completed, self::Cancelled],
            self::Scheduled => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $acc, self $s) => $acc + [$s->value => $s->label()],
            []
        );
    }
}
