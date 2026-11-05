<?php

namespace App\Enums;

/** Откуда пришла заявка — по этому полю считают отдачу каналов. */
enum OrderSource: string
{
    case ProductPage = 'product_page'; // кнопка в карточке товара
    case OneClick = 'one_click';    // экспресс-покупка: только телефон, перезвонить
    case Selection = 'selection';    // «подберите мне стекло», товар ещё не выбран
    case Cart = 'cart';         // корзина: несколько позиций одной заявкой
    case Phone = 'phone';
    case WhatsApp = 'whatsapp';
    case Booking = 'booking';      // клиент сам выбрал время на сайте
    case Wholesale = 'wholesale';  // спецификация оптовика: много позиций, без корзины
    case Assistant = 'assistant';  // диалог с AI-помощником
    case Manual = 'manual';       // менеджер завёл руками

    public function label(): string
    {
        return match ($this) {
            self::ProductPage => 'Карточка товара',
            self::OneClick => 'Покупка в 1 клик',
            self::Selection => 'Заявка на подбор',
            self::Cart => 'Корзина',
            self::Phone => 'Телефон',
            self::WhatsApp => 'WhatsApp',
            self::Booking => 'Онлайн-запись',
            self::Wholesale => 'Оптовая заявка',
            self::Assistant => 'AI-помощник',
            self::Manual => 'Заведён вручную',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Booking => 'success',
            self::OneClick => 'success',
            self::WhatsApp => 'info',
            self::Cart => 'primary',
            self::Wholesale => 'warning',
            self::Assistant => 'info',
            default => 'gray',
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
