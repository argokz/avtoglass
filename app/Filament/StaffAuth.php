<?php

namespace App\Filament;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Panel;

/**
 * Общий вход для обеих панелей.
 *
 * 2FA включается в профиле, а не галочкой в карточке сотрудника:
 * секрет живёт в телефоне человека, администратор его не выдаёт.
 * Обязательность — через STAFF_2FA_REQUIRED: на бою включать после того,
 * как у смены появились приложения-аутентификаторы, иначе первый вход
 * после выкладки запрёт всех, у кого их ещё нет.
 */
final class StaffAuth
{
    public static function configure(Panel $panel): Panel
    {
        return $panel
            ->login()
            ->profile()
            ->passwordReset()
            ->multiFactorAuthentication(
                [
                    AppAuthentication::make()
                        ->recoverable()
                        ->brandName((string) config('company.name')),
                ],
                isRequired: fn (): bool => (bool) config('security.staff_2fa_required', false),
            );
    }
}
