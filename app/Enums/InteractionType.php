<?php

namespace App\Enums;

/**
 * Запись в истории общения с клиентом.
 *
 * System — не человеческое действие, а след системы: смена статуса, назначение
 * менеджера, создание наряда. Такие записи нельзя редактировать и удалять,
 * иначе история перестаёт быть доказательством того, что произошло.
 */
enum InteractionType: string
{
    case Note = 'note';
    case CallIn = 'call_in';
    case CallOut = 'call_out';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Note => 'Заметка',
            self::CallIn => 'Входящий звонок',
            self::CallOut => 'Исходящий звонок',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Почта',
            self::System => 'Система',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Note => 'heroicon-o-pencil-square',
            self::CallIn => 'heroicon-o-phone-arrow-down-left',
            self::CallOut => 'heroicon-o-phone-arrow-up-right',
            self::WhatsApp => 'heroicon-o-chat-bubble-left-right',
            self::Email => 'heroicon-o-envelope',
            self::System => 'heroicon-o-cog-6-tooth',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Note => 'gray',
            self::CallIn => 'success',
            self::CallOut => 'info',
            self::WhatsApp => 'success',
            self::Email => 'info',
            self::System => 'gray',
        };
    }

    /** Системные записи пишет код, руками их не заводят и не правят. */
    public function isSystem(): bool
    {
        return $this === self::System;
    }

    /** @return array<string,string> типы, доступные человеку в форме */
    public static function manualOptions(): array
    {
        return array_reduce(
            array_filter(self::cases(), fn (self $t) => ! $t->isSystem()),
            fn (array $acc, self $t) => $acc + [$t->value => $t->label()],
            []
        );
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $acc, self $t) => $acc + [$t->value => $t->label()],
            []
        );
    }
}
