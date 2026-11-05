<?php

return [
    // Провайдер антифрода: local — свои правила; внешний подключается
    // реализацией App\Domain\Risk\FraudProvider и сменой этого ключа.
    'provider' => env('RISK_PROVIDER', 'local'),

    // Пороги локальных правил.
    'review_orders_per_hour' => (int) env('RISK_REVIEW_ORDERS_PER_HOUR', 3),
    'block_orders_per_day' => (int) env('RISK_BLOCK_ORDERS_PER_DAY', 10),
    'review_ip_per_day' => (int) env('RISK_REVIEW_IP_PER_DAY', 5),
    'review_amount' => (float) env('RISK_REVIEW_AMOUNT', 500000),
];
