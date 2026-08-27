<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Панель для тех, кто работает «в поле»: курьеров и установщиков.
 *
 * Отдельная от админки намеренно. Курьеру нечего делать в прайсах и заявках,
 * а класть его в общую панель со скрытыми разделами — значит рано или поздно
 * забыть скрыть один из них. Здесь он видит только свои наряды на сегодня.
 */
class FieldPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('field')
            ->path('naryad')
            ->login()
            ->brandName('Мои наряды')
            ->colors(['primary' => Color::Blue])
            ->font('Inter')
            ->discoverPages(in: app_path('Filament/Field/Pages'), for: 'App\Filament\Field\Pages')
            // Телефон в одной руке, стекло в другой — боковое меню только мешает
            ->topNavigation()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
