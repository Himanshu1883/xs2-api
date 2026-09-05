<?php

/*
|--------------------------------------------------------------------------
| Listing currency conversion (XS2 ticket → Seats Broker event)
|--------------------------------------------------------------------------
|
| XS2 tickets are often priced in EUR while a mapped Seats Broker event may
| only accept GBP (match_info.price_type). Rates are fixed configuration by
| default; set CURRENCY_CONVERSION_ENABLED=false to disable conversion.
|
| EUR→GBP default: 81.67 / 95 = 0.8596842105 (example market rate).
| Override via CURRENCY_RATE_EUR_GBP without redeploying code.
|
*/

return [
    'enabled' => (bool) env('CURRENCY_CONVERSION_ENABLED', true),

    /*
    | 1 unit of the source currency equals `rate` units of the target currency.
    | Example: 95 EUR × 0.8596842105 ≈ 81.67 GBP.
    */
    'rates' => [
        'EUR' => [
            'GBP' => (float) env('CURRENCY_RATE_EUR_GBP', 81.67 / 95),
        ],
        'USD' => [
            'GBP' => (float) env('CURRENCY_RATE_USD_GBP', 0.79),
            'EUR' => (float) env('CURRENCY_RATE_USD_EUR', 0.92),
        ],
        'GBP' => [
            'EUR' => (float) env('CURRENCY_RATE_GBP_EUR', 95 / 81.67),
        ],
    ],

    'decimal_places' => [
        'default' => 2,
        'GBP' => 2,
        'EUR' => 2,
        'USD' => 2,
    ],
];
