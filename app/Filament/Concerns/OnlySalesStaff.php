<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Экран для тех, кто ведёт продажи: менеджер и администратор.
 *
 * Курьер и мастер сюда не заходят — у них своя панель с нарядами,
 * и списка всех клиентов им там видеть незачем.
 */
trait OnlySalesStaff
{
    public static function canViewAny(): bool
    {
        return Auth::user()?->role->canWorkWithOrders() ?? false;
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }
}
