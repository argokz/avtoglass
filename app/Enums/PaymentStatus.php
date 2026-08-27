<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';    // ссылка выдана, клиент ушёл на страницу оплаты
    case Succeeded = 'succeeded';
    case Failed = 'failed';     // банк отклонил
    case Cancelled = 'cancelled';  // клиент передумал
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает оплаты',
            self::Succeeded => 'Оплачен',
            self::Failed => 'Отклонён',
            self::Cancelled => 'Отменён',
            self::Refunded => 'Возвращён',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
            self::Refunded => 'info',
        };
    }

    /**
     * Платёж завершён — его исход больше не изменится.
     * Повторный колбэк по такому платежу игнорируется.
     */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function isPaid(): bool
    {
        return $this === self::Succeeded;
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
