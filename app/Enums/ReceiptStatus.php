<?php

namespace App\Enums;

enum ReceiptStatus: string
{
    case Pending = 'pending';   // ждёт отправки в ОФД
    case Issued = 'issued';    // чек выбит, номер получен
    case Failed = 'failed';    // не удалось, нужна повторная попытка

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает',
            self::Issued => 'Выбит',
            self::Failed => 'Ошибка',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Issued => 'success',
            self::Failed => 'danger',
        };
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
