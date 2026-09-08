<?php

namespace App\Services\Xs2;

use App\Services\Currency\CurrencyConversionService;
use App\Services\SplitListings\SplitListingService;

/**
 * Builds admin previews showing XS2 prices alongside SB publish prices.
 */
class ListingPublishPricePreviewService
{
    public function __construct(
        private readonly SplitListingService $splitListings,
        private readonly PriceIncrementResolver $increments,
        private readonly CurrencyConversionService $currency,
    ) {}

    /**
     * @param  list<array{split_order: int, quantity: int, price: float}>  $listings
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    public function enrichListings(array $listings, array $settings, string $ticketCurrency = 'EUR', ?string $sellerCurrency = null): array
    {
        $ticketCurrency = $this->currency->normalizeCurrency($ticketCurrency) ?? PriceIncrementResolver::BASE_CURRENCY;
        $sellerCurrency = $this->currency->normalizeCurrency($sellerCurrency) ?? $ticketCurrency;
        $incrementType = (string) ($settings['default_price_increment_type'] ?? 'percentage');
        $incrementValue = (float) ($settings['default_price_increment_value'] ?? 0);
        $sellerIncrement = $this->increments->incrementForCurrency($settings, $sellerCurrency);

        $baseSeller = null;
        $baseXs2 = $listings !== [] ? (float) $listings[0]['price'] : null;
        if ($listings !== [] && $this->currency->needsConversion($ticketCurrency, $sellerCurrency)) {
            $baseSeller = $this->currency->convertMajor((float) $listings[0]['price'], $ticketCurrency, $sellerCurrency);
        }

        return array_map(function (array $listing) use (
            $ticketCurrency,
            $sellerCurrency,
            $incrementType,
            $incrementValue,
            $sellerIncrement,
            $baseSeller,
            $baseXs2,
        ): array {
            $xs2Price = (float) $listing['price'];
            $splitOrder = (int) ($listing['split_order'] ?? 1);
            $index = max(0, $splitOrder - 1);

            $sellerPrice = $xs2Price;
            $converted = false;

            if ($this->currency->needsConversion($ticketCurrency, $sellerCurrency)) {
                $converted = true;
                if ($incrementType === 'fixed' && $baseSeller !== null) {
                    $sellerPrice = round($baseSeller + ($index * $sellerIncrement), 2);
                } else {
                    $sellerPrice = $this->currency->convertMajor($xs2Price, $ticketCurrency, $sellerCurrency);
                }
            }

            $enriched = [
                ...$listing,
                'xs2_price' => round($xs2Price, 2),
                'xs2_currency' => $ticketCurrency,
                'original_price' => $baseXs2 !== null ? round($baseXs2, 2) : round($xs2Price, 2),
                'seller_price' => round($sellerPrice, 2),
                'seller_currency' => $sellerCurrency,
            ];

            if ($converted) {
                $enriched['price_conversion'] = $this->currency->conversionSummary($xs2Price, $ticketCurrency, $sellerCurrency);
            }

            if ($incrementType === 'fixed' && $index > 0) {
                $enriched['increment_applied'] = [
                    'type' => 'fixed',
                    'xs2' => round($incrementValue * $index, 2),
                    'xs2_currency' => $ticketCurrency,
                    'seller' => round($sellerIncrement * $index, 2),
                    'seller_currency' => $sellerCurrency,
                ];
            }

            return $enriched;
        }, $listings);
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function attachPriceExample(array $preview, array $settings, int $splitSize): array
    {
        if (! ($preview['matched'] ?? false) || ($preview['mode'] ?? '') !== 'split') {
            return $preview;
        }

        $basePrice = 100.0;
        $stock = (int) ($preview['stock'] ?? 0);
        $quantities = $this->splitListings->calculateSplitQuantities($stock, max(1, $splitSize));
        $listings = $this->splitListings->calculatePrices(
            $quantities,
            $basePrice,
            (string) $settings['default_price_increment_type'],
            (float) $settings['default_price_increment_value'],
        );

        $preview['price_example'] = [
            'xs2_base_price' => $basePrice,
            'xs2_currency' => PriceIncrementResolver::BASE_CURRENCY,
            'seller_currency' => 'GBP',
            'listings' => $this->enrichListings($listings, $settings, PriceIncrementResolver::BASE_CURRENCY, 'GBP'),
        ];

        return $preview;
    }
}
