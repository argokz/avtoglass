<?php

return [
    /*
     * Драйвер платежей: test | halyk | freedompay
     *
     * «test» — встроенная заглушка, которая проводит клиента по тому же пути,
     * что и настоящая касса. Боевые драйверы работают через тот же контракт,
     * поэтому переключение — это одна строка здесь.
     */
    'driver' => env('PAYMENT_DRIVER', 'test'),
    'verified_drivers' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAYMENTS_VERIFIED_DRIVERS', ''))))),

    /*
     * Секрет для подписи колбэков заглушки. У боевой кассы вместо него
     * ключ мерчанта; здесь он нужен, чтобы подпись проверялась по-настоящему
     * и переход на боевой драйвер не вскрыл дыру, которой в тестах не было.
     */
    'secret' => env('PAYMENT_SECRET', 'local-test-secret'),

    /*
     * Через сколько минут неоплаченная ссылка считается протухшей.
     * Резерв стекла под неоплаченный заказ дольше держать нельзя.
     */
    'ttl_minutes' => 30,

    'halyk' => [
        /*
         * Значения по умолчанию — публичная песочница Halyk из их документации.
         * Она реальна и отвечает, поэтому интеграцию можно проверять до того,
         * как банк выдаст боевой терминал.
         */
        'test_mode' => env('HALYK_TEST_MODE', true),
        'client_id' => env('HALYK_CLIENT_ID', 'test'),
        'client_secret' => env('HALYK_CLIENT_SECRET', 'yF587AV9Ms94qN2QShFzVR3vFnWkhjbAK3sG'),
        'terminal' => env('HALYK_TERMINAL', '67e34d63-102f-4bd1-898e-370781d0074d'),

        'endpoints' => [
            'test' => [
                'oauth' => 'https://test-epay-oauth.epayment.kz/oauth2/token',
                'api' => 'https://testepay.homebank.kz/api',
                // Песочница виджета переехала с test-epay.homebank.kz —
                // тот хост больше не открывается, форма карты не поднималась.
                'widget' => 'https://test-epay.epayment.kz/payform/payment-api.js',
            ],
            'live' => [
                'oauth' => 'https://epay-oauth.homebank.kz/oauth2/token',
                'api' => 'https://epay-api.homebank.kz',
                'widget' => 'https://epay.homebank.kz/payform/payment-api.js',
            ],
        ],
    ],

        'freedompay' => [
            'merchant_id' => env('FREEDOMPAY_MERCHANT_ID'),
            'secret' => env('FREEDOMPAY_SECRET'),
            // Пока не подписан акт, мерчант в тестовом режиме — банк требует
            // передавать это признаком в каждом запросе.
            'test_mode' => env('FREEDOMPAY_TEST_MODE', true),
            'init_url' => 'https://api.freedompay.kz/init_payment.php',
            'revoke_url' => 'https://api.freedompay.kz/revoke.php',
        ],

        'kaspi' => [
            'merchant_id' => env('KASPI_MERCHANT_ID', 'test_merchant'),
            'api_key' => env('KASPI_API_KEY', 'test_api_key'),
            'test_mode' => env('KASPI_TEST_MODE', true),
            'api_url' => env('KASPI_API_URL', 'https://api.kaspi.kz/pay/v1/orders'),
            'refund_url' => env('KASPI_REFUND_URL'),
        ],
];
