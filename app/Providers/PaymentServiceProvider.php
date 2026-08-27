<?php

namespace App\Providers;

use App\Payments\Gateways\FreedomPayGateway;
use App\Payments\Gateways\HalykEpayGateway;
use App\Payments\Gateways\TestGateway;
use App\Payments\PaymentGateway;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    /** Драйверы, доступные приложению. Боевые добавляются сюда же. */
    private const DRIVERS = [
        'test' => TestGateway::class,
        'halyk' => HalykEpayGateway::class,
        'freedompay' => FreedomPayGateway::class,
    ];

    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function () {
            $driver = (string) config('payments.driver');

            $class = self::DRIVERS[$driver] ?? null;

            if ($class === null) {
                // Молчаливый откат на заглушку означал бы, что в проде
                // «оплата прошла» без единого списания.
                throw new \RuntimeException("Неизвестный драйвер платежей: {$driver}");
            }

            return $this->app->make($class);
        });
    }
}
