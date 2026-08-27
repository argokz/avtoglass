<?php

namespace App\Enums;

/**
 * Состояние наряда глазами исполнителя.
 *
 * «Не получилось» — полноценный исход, а не сбой: клиента не оказалось дома,
 * стекло разбилось в дороге, машина не подошла. Такой наряд надо перенести,
 * а не считать выполненным и не терять.
 */
enum AssignmentStatus: string
{
    case Planned = 'planned';     // время назначено, исполнитель ещё не выбран
    case Assigned = 'assigned';    // исполнитель знает о наряде
    case EnRoute = 'en_route';    // курьер выехал
    case InProgress = 'in_progress'; // работа идёт
    case Done = 'done';
    case Failed = 'failed';      // не получилось, нужен перенос

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Запланирован',
            self::Assigned => 'Назначен',
            self::EnRoute => 'В пути',
            self::InProgress => 'В работе',
            self::Done => 'Выполнен',
            self::Failed => 'Не получилось',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::Assigned => 'info',
            self::EnRoute => 'warning',
            self::InProgress => 'primary',
            self::Done => 'success',
            self::Failed => 'danger',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Done, self::Failed], true);
    }

    /**
     * Куда можно перейти. «В пути» есть только у доставки — установщик
     * никуда не едет, он начинает работу на месте.
     *
     * @return array<int,self>
     */
    public function allowedTransitions(AssignmentType $type): array
    {
        return match ($this) {
            self::Planned => [self::Assigned, self::Failed],
            self::Assigned => $type === AssignmentType::Delivery
                ? [self::EnRoute, self::Failed]
                : [self::InProgress, self::Failed],
            self::EnRoute => [self::InProgress, self::Failed],
            self::InProgress => [self::Done, self::Failed],
            self::Done, self::Failed => [],
        };
    }

    public function canTransitionTo(self $target, AssignmentType $type): bool
    {
        return in_array($target, $this->allowedTransitions($type), true);
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
