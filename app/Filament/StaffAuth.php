<?php

namespace App\Filament;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Panel;

/**
 * Общий вход для обеих панелей.
 *
 * 2FA включается в профиле, а не галочкой в карточке сотрудника:
 * секрет живёт в телефоне человека, администратор его не выдаёт.
 * Обязательной не делаем — иначе первый вход после выкладки
 * запирает всех, у кого ещё нет приложения-аутентификатора.
 */
final class StaffAuth
{
    public static function configure(Panel $panel): Panel
    {
        return $panel
            ->login()
            ->profile()
            ->passwordReset()
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable()
                    ->brandName((string) config('company.name')),
            ]);
    }
}
