<?php

namespace App\Providers;

use App\Payments\Gateways\FreedomPayGateway;
use App\Payments\Gateways\HalykEpayGateway;
use App\Payments\Gateways\KaspiPayGateway;
use App\Payments\Gateways\TestGateway;
use App\Payments\PaymentGateway;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    /** Драйверы, доступные приложению. Боевые добавляются сюда же. */
    private const DRIVERS = [
        'test' => TestGateway::class,
        'kaspi' => KaspiPayGateway::class,
        'halyk' => HalykEpayGateway::class,
        'freedompay' => FreedomPayGateway::class,
    ];

    public static function driver(?string $name = null): PaymentGateway
    {
        $name ??= (string) config('payments.driver');
        $class = self::DRIVERS[$name] ?? null;

        if ($class === null) {
            throw new \RuntimeException("Неизвестный драйвер платежей: {$name}");
        }

        return app($class);
    }

    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, fn () => self::driver());
    }
}
