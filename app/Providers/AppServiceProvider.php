<?php

namespace App\Providers;

use App\Ai\Gemini\GeminiClient;
use App\Ai\Gemini\GeminiRotator;
use App\Cart\Cart;
use App\Settings\AppSettings;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        require_once app_path('helpers.php');

        // Корзина одна на запрос: иначе два обращения к ней в одном запросе
        // прочитают сессию по отдельности и разъедутся.
        $this->app->scoped(Cart::class, fn ($app) => new Cart($app['session.store']));
        $this->app->singleton(AppSettings::class);

        /*
         * Клиент Gemini собирается на каждый запрос: список моделей и паузу
         * можно поменять в CRM, и они должны подхватиться без деплоя.
         * Ключи — только из .env, в базу их не тащим.
         */
        $this->app->bind(GeminiClient::class, function () {
            $fromCrm = preg_split('/\R/u', (string) settings()->get('ai_models', '')) ?: [];
            $models = array_values(array_filter(array_map('trim', $fromCrm))) ?: config('ai.models');
            $cooldown = (int) settings()->get('ai_cooldown_minutes', 0) ?: (int) config('ai.cooldown_minutes');

            return new GeminiClient(
                new GeminiRotator(config('ai.keys'), $models, $cooldown),
                config('ai.endpoint'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
