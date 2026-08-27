<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Открыта',
            self::Done => 'Выполнена',
            self::Cancelled => 'Отменена',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Done => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
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
