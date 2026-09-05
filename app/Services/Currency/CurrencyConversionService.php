<?php

namespace App\Services\Currency;

use App\Exceptions\Integrations\ListingTransformationException;
use App\Models\EventMapping;
use Illuminate\Support\Facades\Schema;

/**
 * Converts XS2 ticket prices to the currency required by a mapped SB event.
 *
 * Rates are read from config/currency.php (fixed config). When ticket currency
 * differs from match_info.price_type, listing publish paths convert before the
 * Seller API call while preserving the original XS2 price in the database.
 */
class CurrencyConversionService
{
    public function isEnabled(): bool
    {
        return (bool) config('currency.enabled', true);
    }

    public function normalizeCurrency(?string $code): ?string
    {
        if (! is_string($code)) {
            return null;
        }

        $normalized = strtoupper(trim($code));

        return strlen($normalized) === 3 ? $normalized : null;
    }

    /**
     * Currency required by the mapped Seats Broker event (match_info.price_type).
     */
    public function eventCurrency(EventMapping $mapping): ?string
    {
        if ($mapping->relationLoaded('event')) {
            $fromEvent = $this->normalizeCurrency($mapping->event?->getAttribute('price_type'));
            if ($fromEvent !== null) {
                return $fromEvent;
            }
        }

        if ($mapping->m_id && Schema::hasTable('match_info')) {
            $fromEvent = $this->normalizeCurrency(
                $mapping->relationLoaded('event')
                    ? $mapping->event?->getAttribute('price_type')
                    : $mapping->event()->value('price_type')
            );
            if ($fromEvent === null && $mapping->relationLoaded('event')) {
                $fromEvent = $this->normalizeCurrency(
                    $mapping->event()->value('price_type')
                );
            }
            if ($fromEvent !== null) {
                return $fromEvent;
            }
        }

        $details = is_array($mapping->match_details) ? $mapping->match_details : [];

        return $this->normalizeCurrency(
            data_get($details, 'local_references.price_type')
                ?? data_get($details, 'best_match.price_type')
        );
    }

    public function needsConversion(string $fromCurrency, ?string $toCurrency): bool
    {
        $from = $this->normalizeCurrency($fromCurrency);
        $to = $this->normalizeCurrency($toCurrency);

        return $this->isEnabled()
            && $from !== null
            && $to !== null
            && $from !== $to;
    }

    public function rate(string $fromCurrency, string $toCurrency): float
    {
        $from = $this->normalizeCurrency($fromCurrency);
        $to = $this->normalizeCurrency($toCurrency);

        if ($from === null || $to === null) {
            throw new ListingTransformationException('Currency conversion requires valid ISO currency codes.');
        }

        if ($from === $to) {
            return 1.0;
        }

        $direct = config("currency.rates.{$from}.{$to}");
        if (is_numeric($direct) && (float) $direct > 0) {
            return (float) $direct;
        }

        $inverse = config("currency.rates.{$to}.{$from}");
        if (is_numeric($inverse) && (float) $inverse > 0) {
            return 1 / (float) $inverse;
        }

        throw new ListingTransformationException(
            "No currency conversion rate configured from {$from} to {$to}. "
            .'Add a rate in config/currency.php or set the matching CURRENCY_RATE_* env var.'
        );
    }

    public function convertMajor(float $amount, string $fromCurrency, string $toCurrency): float
    {
        $from = $this->normalizeCurrency($fromCurrency);
        $to = $this->normalizeCurrency($toCurrency);

        if ($from === null || $to === null) {
            throw new ListingTransformationException('Currency conversion requires valid ISO currency codes.');
        }

        if ($from === $to || ! $this->isEnabled()) {
            return round($amount, $this->decimalPlaces($to));
        }

        return round($amount * $this->rate($from, $to), $this->decimalPlaces($to));
    }

    public function convertMinorUnits(int $amount, string $fromCurrency, string $toCurrency): int
    {
        $from = $this->normalizeCurrency($fromCurrency);
        $to = $this->normalizeCurrency($toCurrency);

        if ($from === null || $to === null) {
            throw new ListingTransformationException('Currency conversion requires valid ISO currency codes.');
        }

        if ($from === $to || ! $this->isEnabled()) {
            return $amount;
        }

        $divisor = max(1, (int) config('services.xs2.minor_unit_divisor', 100));
        $major = $this->convertMajor($amount / $divisor, $from, $to);

        return (int) round($major * $divisor);
    }

    /**
     * @return array{
     *     converted: bool,
     *     from_currency: string,
     *     to_currency: string,
     *     rate: float|null,
     *     original_amount_major: float,
     *     converted_amount_major: float
     * }|null
     */
    public function conversionSummary(
        float $amountMajor,
        string $fromCurrency,
        ?string $toCurrency,
    ): ?array {
        $from = $this->normalizeCurrency($fromCurrency);
        $to = $this->normalizeCurrency($toCurrency);

        if ($from === null || $to === null || ! $this->needsConversion($from, $to)) {
            return null;
        }

        return [
            'converted' => true,
            'from_currency' => $from,
            'to_currency' => $to,
            'rate' => $this->rate($from, $to),
            'original_amount_major' => round($amountMajor, $this->decimalPlaces($from)),
            'converted_amount_major' => $this->convertMajor($amountMajor, $from, $to),
        ];
    }

    private function decimalPlaces(string $currency): int
    {
        $places = config("currency.decimal_places.{$currency}")
            ?? config('currency.decimal_places.default', 2);

        return max(0, (int) $places);
    }
}
