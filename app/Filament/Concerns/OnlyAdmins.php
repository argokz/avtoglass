<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Экран только для администратора.
 *
 * Так закрыты справочники: товары, поставщики, прайсы, модели машин.
 * Менеджеру они не нужны в работе, а цена ошибки на них другая — неверная
 * наценка у поставщика или снесённая модель ломают витрину целиком,
 * а не одну заявку.
 */
trait OnlyAdmins
{
    public static function canViewAny(): bool
    {
        return Auth::user()?->role->canManageCatalog() ?? false;
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }
}
