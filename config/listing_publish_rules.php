<?php

return [
    'enabled' => (bool) env('LISTING_PUBLISH_RULES_ENABLED', true),

    'default_price_increment_type' => env('LISTING_PUBLISH_DEFAULT_INCREMENT_TYPE', 'percentage'),

    'default_price_increment_value' => (float) env('LISTING_PUBLISH_DEFAULT_INCREMENT_VALUE', 0),

    /*
    |--------------------------------------------------------------------------
    | Default publish rules (overridable via integration_settings)
    |--------------------------------------------------------------------------
    |
    | Rules are evaluated in priority order (lowest first). The first enabled
    | rule whose stock conditions match wins.
    |
    | Single mode: one Seats Broker listing (1:1 master path).
    | Split mode: multiple split listings via SplitListingService.
    |
    */
    'rules' => [
        [
            'id' => 'low_stock',
            'label' => 'Low stock (1–4 tickets)',
            'enabled' => true,
            'priority' => 10,
            'conditions' => [
                [
                    'field' => 'stock',
                    'operator' => 'between',
                    'min' => 1,
                    'max' => 4,
                ],
            ],
            'action' => [
                'mode' => 'single',
                'listing_quantity' => 2,
                'listing_quantity_cap_to_stock' => true,
                'pairs_only' => true,
            ],
        ],
        [
            'id' => 'high_stock',
            'label' => 'High stock (5+ tickets)',
            'enabled' => true,
            'priority' => 20,
            'conditions' => [
                [
                    'field' => 'stock',
                    'operator' => 'gte',
                    'value' => 5,
                ],
            ],
            'action' => [
                'mode' => 'split',
                'split_size' => 2,
                'pairs_only' => true,
            ],
        ],
    ],
];
