<?php

return [
    /*
     * Фискализация чеков. Отдельно от приёма денег: эквайер получает оплату,
     * ОФД выбивает чек. Одно без другого в Казахстане незаконно, но и падать
     * друг из-за друга они не должны.
     */
    'enabled' => env('FISCAL_ENABLED', false),

    'webkassa' => [
        'test_mode' => env('WEBKASSA_TEST_MODE', true),
        'login' => env('WEBKASSA_LOGIN'),
        'password' => env('WEBKASSA_PASSWORD'),
        'cashbox' => env('WEBKASSA_CASHBOX'),

        'endpoints' => [
            'test' => 'https://devkkm.webkassa.kz',
            'live' => 'https://api.webkassa.kz',
        ],

        // Сколько раз пытаться выбить чек, прежде чем звать человека
        'max_attempts' => 5,
    ],
];
