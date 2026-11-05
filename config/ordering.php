<?php

return [
    'api_enabled' => (bool) env('ORDERING_API_ENABLED', false),
    // Витрина оформляет заказы через DraftService/SubmitDraft вместо legacy CreateOrder.
    'storefront_core' => (bool) env('ORDERING_STOREFRONT_CORE', false),
    // Automatic retail confirm + full reserve. Off until catalogue offers and payments are ready.
    'auto_checkout_enabled' => (bool) env('ORDERING_AUTO_CHECKOUT_ENABLED', false),
    'wholesale_auto_checkout_enabled' => (bool) env('ORDERING_WHOLESALE_AUTO_CHECKOUT_ENABLED', false),
    'online_reservation_minutes' => max(1, (int) env('ORDERING_ONLINE_RESERVATION_MINUTES', 15)),
    'idempotency_result_hours' => 24,
    'quote_ttl_minutes' => max(1, (int) env('ORDERING_QUOTE_TTL_MINUTES', 15)),
    'stock_freshness_hours' => 24,
    'fitment' => [
        // These physical options must be answered explicitly for automatic vehicle fitment.
        'required_options' => [
            'windshield' => ['camera', 'rain_sensor', 'heated_wipers', 'heated_full', 'hud', 'rhd', 'mirror_bracket'],
            'rear' => ['heated_rear', 'antenna'],
            'side' => ['rhd'],
            'quarter' => ['rhd'],
            'roof' => [],
        ],
    ],
    'fulfillment' => [
        // Explicit whole-city delivery policies, keyed by normalized city name.
        // Example shape only: ['fee_minor' => 150000, 'supplier_ids' => [12, 15]].
        // Empty configuration requires a manager; it never means free delivery.
        'delivery_zones' => [],
    ],
    'limits' => [
        'retail_max_quantity' => 20,
        'wholesale_max_quantity' => 99,
        // A larger retail draft is retained, but must go to a manager in QuoteDraft.
        'retail_auto_max_items' => 50,
        'max_draft_items' => 500,
    ],
];
