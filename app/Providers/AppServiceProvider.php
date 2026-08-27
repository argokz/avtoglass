<?php

namespace App\Providers;

use App\Cart\Cart;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Корзина одна на запрос: иначе два обращения к ней в одном запросе
        // прочитают сессию по отдельности и разъедутся.
        $this->app->scoped(Cart::class, fn ($app) => new Cart($app['session.store']));

        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
