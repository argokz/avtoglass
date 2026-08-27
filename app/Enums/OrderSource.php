<?php

namespace App\Enums;

/** Откуда пришла заявка — по этому полю считают отдачу каналов. */
enum OrderSource: string
{
    case ProductPage = 'product_page'; // кнопка в карточке товара
    case Selection = 'selection';    // «подберите мне стекло», товар ещё не выбран
    case Cart = 'cart';         // корзина: несколько позиций одной заявкой
    case Phone = 'phone';
    case WhatsApp = 'whatsapp';
    case Booking = 'booking';      // клиент сам выбрал время на сайте
    case Manual = 'manual';       // менеджер завёл руками

    public function label(): string
    {
        return match ($this) {
            self::ProductPage => 'Карточка товара',
            self::Selection => 'Заявка на подбор',
            self::Cart => 'Корзина',
            self::Phone => 'Телефон',
            self::WhatsApp => 'WhatsApp',
            self::Booking => 'Онлайн-запись',
            self::Manual => 'Заведён вручную',
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
