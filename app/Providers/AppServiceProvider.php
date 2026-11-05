<?php

namespace App\Providers;

use App\Ai\Gemini\GeminiClient;
use App\Ai\Gemini\GeminiRotator;
use App\Ai\Providers\ChatProvider;
use App\Ai\Providers\ProviderRouter;
use App\Ai\Runtime\RuntimeMetrics;
use App\Cart\Cart;
use App\Domain\Risk\FraudProvider;
use App\Domain\Risk\LocalFraudProvider;
use App\Settings\AppSettings;
use App\Support\StorefrontCache;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->scoped(AppSettings::class);
        $this->app->scoped(StorefrontCache::class);
        $this->app->scoped(RuntimeMetrics::class);
        $this->app->bind(ChatProvider::class, ProviderRouter::class);

        // Антифрод: по умолчанию свои правила, внешний провайдер — через risk.provider.
        $this->app->bind(FraudProvider::class, fn () => match (config('risk.provider', 'local')) {
            default => new LocalFraudProvider,
        });

        /*
         * Клиент Gemini собирается на каждый запрос: список моделей и паузу
         * можно поменять в CRM, и они должны подхватиться без деплоя.
         * Ключи — только из .env, в базу их не тащим.
         */
        $this->app->bind(GeminiClient::class, function () {
            $fromCrm = preg_split('/\R/u', (string) settings()->get('ai_models', '')) ?: [];
            $allowed = array_values(array_intersect((array) config('ai.models'), (array) config('ai.approved_gemini_models')));
            $models = array_values(array_intersect(array_filter(array_map('trim', $fromCrm)), $allowed)) ?: $allowed;
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
        DB::listen(fn (QueryExecuted $event) => app(RuntimeMetrics::class)->query((float) $event->time));
        /*
         * Именованные ограничители вместо разрозненных throttle: лимиты
         * форм — это часть антифрода, и меняться они должны в одном месте.
         */
        RateLimiter::for('orders', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('lookup', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
