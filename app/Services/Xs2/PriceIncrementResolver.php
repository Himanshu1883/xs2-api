<?php

namespace App\Services\Xs2;

use App\Services\Currency\CurrencyConversionService;

/**
 * Resolves split-listing price increments per currency.
 *
 * The base increment value is stored in EUR. Other currencies are derived via
 * configured conversion rates unless explicitly overridden.
 */
class PriceIncrementResolver
{
    public const BASE_CURRENCY = 'EUR';

    /** @var list<string> */
    public const SUPPORTED_CURRENCIES = ['EUR', 'GBP'];

    public function __construct(
        private readonly CurrencyConversionService $currency,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, float>
     */
    public function incrementsByCurrency(array $settings): array
    {
        $type = (string) ($settings['default_price_increment_type'] ?? 'percentage');
        if ($type !== 'fixed') {
            return [];
        }

        $baseValue = max(0, (float) ($settings['default_price_increment_value'] ?? 0));
        $overrides = is_array($settings['price_increment_by_currency'] ?? null)
            ? $settings['price_increment_by_currency']
            : [];

        $resolved = [];
        foreach (self::SUPPORTED_CURRENCIES as $currency) {
            $override = $overrides[$currency] ?? null;
            if (is_numeric($override)) {
                $resolved[$currency] = round((float) $override, 2);

                continue;
            }

            if ($currency === self::BASE_CURRENCY) {
                $resolved[$currency] = round($baseValue, 2);

                continue;
            }

            $resolved[$currency] = $this->currency->isEnabled() && $baseValue > 0
                ? $this->currency->convertMajor($baseValue, self::BASE_CURRENCY, $currency)
                : round($baseValue, 2);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function incrementForCurrency(array $settings, ?string $currency): float
    {
        $type = (string) ($settings['default_price_increment_type'] ?? 'percentage');
        if ($type !== 'fixed') {
            return max(0, (float) ($settings['default_price_increment_value'] ?? 0));
        }

        $normalized = $this->currency->normalizeCurrency($currency) ?? self::BASE_CURRENCY;
        $byCurrency = $this->incrementsByCurrency($settings);

        return $byCurrency[$normalized]
            ?? $byCurrency[self::BASE_CURRENCY]
            ?? max(0, (float) ($settings['default_price_increment_value'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function settingsMetadata(array $settings): array
    {
        $eurGbpRate = null;
        if ($this->currency->isEnabled()) {
            try {
                $eurGbpRate = $this->currency->rate(self::BASE_CURRENCY, 'GBP');
            } catch (\Throwable) {
                $eurGbpRate = null;
            }
        }

        return [
            'price_increment_base_currency' => self::BASE_CURRENCY,
            'price_increment_by_currency' => $this->incrementsByCurrency($settings),
            'currency_conversion' => [
                'enabled' => $this->currency->isEnabled(),
                'base_currency' => self::BASE_CURRENCY,
                'rates' => $eurGbpRate !== null
                    ? ['EUR_GBP' => round($eurGbpRate, 10)]
                    : [],
                'description' => 'Split increments are applied in the Seats Broker event currency after converting the XS2 base price. '
                    .'EUR increments are derived from the increment value; GBP increments use the EUR→GBP rate unless overridden.',
            ],
        ];
    }
}
