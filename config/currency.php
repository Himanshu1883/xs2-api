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
| QAR and AED are pegged to USD (3.64 QAR/USD, 3.6725 AED/USD).
| Override any pair via CURRENCY_RATE_{FROM}_{TO} without redeploying code.
|
*/

$qarUsd = 1 / 3.64;
$usdEur = (float) env('CURRENCY_RATE_USD_EUR', 0.92);
$usdGbp = (float) env('CURRENCY_RATE_USD_GBP', 0.79);
$aedUsd = 1 / 3.6725;

return [
    'enabled' => (bool) env('CURRENCY_CONVERSION_ENABLED', true),

    /*
    | 1 unit of the source currency equals `rate` units of the target currency.
    | Example: 95 EUR × 0.8596842105 ≈ 81.67 GBP.
    | Example: 364 QAR × 0.2747252747 ≈ 100 USD (QAR pegged at 3.64 per USD).
    */
    'rates' => [
        'EUR' => [
            'GBP' => (float) env('CURRENCY_RATE_EUR_GBP', 81.67 / 95),
        ],
        'USD' => [
            'GBP' => $usdGbp,
            'EUR' => $usdEur,
            'QAR' => (float) env('CURRENCY_RATE_USD_QAR', 3.64),
            'AED' => (float) env('CURRENCY_RATE_USD_AED', 3.6725),
        ],
        'GBP' => [
            'EUR' => (float) env('CURRENCY_RATE_GBP_EUR', 95 / 81.67),
        ],
        'QAR' => [
            'USD' => (float) env('CURRENCY_RATE_QAR_USD', $qarUsd),
            'EUR' => (float) env('CURRENCY_RATE_QAR_EUR', $qarUsd * $usdEur),
            'GBP' => (float) env('CURRENCY_RATE_QAR_GBP', $qarUsd * $usdGbp),
        ],
        'AED' => [
            'USD' => (float) env('CURRENCY_RATE_AED_USD', $aedUsd),
            'EUR' => (float) env('CURRENCY_RATE_AED_EUR', $aedUsd * $usdEur),
            'GBP' => (float) env('CURRENCY_RATE_AED_GBP', $aedUsd * $usdGbp),
        ],
    ],

    'decimal_places' => [
        'default' => 2,
        'GBP' => 2,
        'EUR' => 2,
        'USD' => 2,
        'QAR' => 2,
        'AED' => 2,
    ],
];
