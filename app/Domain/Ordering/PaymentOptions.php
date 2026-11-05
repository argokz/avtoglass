<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Domain\Identity\BuyerContext;

final class PaymentOptions
{
    public static function driver(string $method): ?string
    {
        return match ($method) {
            'card' => in_array(config('payments.driver'), ['test', 'freedompay'], true) ? config('payments.driver') : 'halyk',
            'kaspi' => 'kaspi',
            default => null,
        };
    }

    public static function available(BuyerContext $buyer): array
    {
        $options = ['cash' => $buyer->locale === 'kk' ? 'Алған кезде төлеу' : 'При получении'];
        $verified = (array) config('payments.verified_drivers', []);
        if (settings()->enabled('payments')) {
            foreach (['card' => self::driver('card'), 'kaspi' => 'kaspi'] as $method => $driver) {
                // Kaspi adapter has no verified signed callback contract yet.
                if (in_array($driver, ['test', 'halyk', 'freedompay'], true) && in_array($driver, $verified, true)) {
                    $options[$method] = $method === 'card' ? ($buyer->locale === 'kk' ? 'Онлайн картамен' : 'Картой онлайн') : 'Kaspi Pay';
                }
            }
        }
        $requisites = settings()->requisites();
        if ($buyer->wholesaleApproved && filled($requisites['iik'] ?? null) && filled($requisites['legal_name'] ?? null)) {
            $options['invoice'] = $buyer->locale === 'kk' ? 'Ұйымға шот' : 'Счёт на организацию';
        }

        return $options;
    }
}
