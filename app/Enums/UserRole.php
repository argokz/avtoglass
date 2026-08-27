<?php

namespace App\Enums;

/**
 * Роли сотрудников. Курьер и мастер заведены заранее: под них уже есть
 * задачи в плане (доставка, запись на установку), и вводить роль задним
 * числом дороже, чем описать её сразу.
 */
enum UserRole: string
{
    case Customer = 'customer';
    case Manager = 'manager';
    case Courier = 'courier';
    case Installer = 'installer';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Покупатель',
            self::Manager => 'Менеджер',
            self::Courier => 'Курьер',
            self::Installer => 'Мастер',
            self::Admin => 'Администратор',
        };
    }

    /** Кого пускаем в административную панель. */
    public function canAccessAdminPanel(): bool
    {
        return in_array($this, [self::Admin, self::Manager], true);
    }

    /**
     * Кого пускаем в полевую панель — туда, где видны наряды.
     * Администратор входит тоже: ему надо видеть то же, что видит курьер,
     * когда тот звонит и говорит «у меня ничего не открывается».
     */
    public function canAccessFieldPanel(): bool
    {
        return in_array($this, [self::Courier, self::Installer, self::Admin], true);
    }

    /**
     * Кто ведёт продажи: заявки, клиенты, задачи, наряды.
     * Это ежедневная работа менеджера, и админ делает её же, когда подменяет.
     */
    public function canWorkWithOrders(): bool
    {
        return in_array($this, [self::Admin, self::Manager], true);
    }

    /**
     * Кто правит справочники: товары, поставщики, прайсы, модели машин.
     *
     * Только администратор. Менеджеру эти экраны не нужны в работе, а цена
     * ошибки на них другая: неверная наценка у поставщика или снесённая
     * модель ломают витрину целиком, а не одну заявку.
     */
    public function canManageCatalog(): bool
    {
        return $this === self::Admin;
    }

    /** Кто заводит сотрудников и меняет им роли. */
    public function canManageStaff(): bool
    {
        return $this === self::Admin;
    }

    /** @return array<string,string> для селектов в формах */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $acc, self $role) => $acc + [$role->value => $role->label()],
            []
        );
    }
}
