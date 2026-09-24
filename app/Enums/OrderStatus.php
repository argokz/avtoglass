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

    /**
     * Что происходит сейчас — текст для клиента, без внутреннего жаргона CRM.
     */
    public function clientHint(): string
    {
        return match ($this) {
            self::New => 'Заявка у менеджера. Обычно перезваниваем в течение рабочего дня.',
            self::InProgress => 'Уточняем наличие и цену с работой. Дождитесь звонка — писать повторно не нужно.',
            self::Confirmed => 'Стекло согласовано. Дальше назначим установку или доставку.',
            self::Scheduled => 'Время назначено. Если планы изменятся — позвоните, освободим слот.',
            self::Completed => 'Работа выполнена. Сохраните гарантийный талон.',
            self::Cancelled => 'Заявка отменена. Если стекло ещё нужно — оставьте новую.',
        };
    }

    /**
     * Шаги, которые клиент видит на странице статуса.
     * Отмена — отдельный конец, в эту цепочку не входит.
     *
     * @return list<self>
     */
    public static function trackPipeline(): array
    {
        return [self::New, self::InProgress, self::Confirmed, self::Scheduled, self::Completed];
    }

    public function trackIndex(): int
    {
        return match ($this) {
            self::New => 0,
            self::InProgress => 1,
            self::Confirmed => 2,
            self::Scheduled => 3,
            self::Completed => 4,
            self::Cancelled => -1,
        };
    }

    /** В какие статусы можно уйти отсюда. Отмена доступна из любого живого. */
    public function allowedTransitions(): array
    {
        return match ($this) {
            /*
             * Из «новой» можно сразу в «записана»: когда клиент выбрал время
             * онлайн, работа назначена ещё до того, как менеджер до заявки
             * дошёл. Подтверждённой она при этом не считается — наличие стекла
             * никто пока не проверял.
             */
            self::New => [self::InProgress, self::Confirmed, self::Scheduled, self::Cancelled],
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
