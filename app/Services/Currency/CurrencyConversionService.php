<?php

namespace App\Services\Currency;

use App\Exceptions\Integrations\ListingTransformationException;
use App\Models\EventMapping;
use App\Services\SellerApi\SellerApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Converts XS2 ticket prices to the currency required by a mapped SB event.
 *
 * Rates are read from config/currency.php (fixed config). Event currency is
 * resolved from the Seller API ticket_dropdown (authoritative for listing
 * publish), then match_info.price_type, then mapping match_details.
 */
class CurrencyConversionService
{
    public function __construct(
        private readonly ?SellerApiClient $sellerApi = null,
    ) {}

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
     * Currency required by the mapped Seats Broker event for listing publish.
     */
    public function eventCurrency(EventMapping $mapping, ?string $ticketCurrency = null): ?string
    {
        if ($mapping->m_id) {
            $fromDropdown = $this->eventCurrencyFromTicketDropdown((int) $mapping->m_id, $ticketCurrency);
            if ($fromDropdown !== null) {
                return $fromDropdown;
            }
        }

        $fromMatchInfo = $this->eventCurrencyFromMatchInfo($mapping);
        if ($fromMatchInfo !== null) {
            return $fromMatchInfo;
        }

        $details = is_array($mapping->match_details) ? $mapping->match_details : [];

        return $this->normalizeCurrency(
            data_get($details, 'local_references.price_type')
                ?? data_get($details, 'best_match.price_type')
        );
    }

    /**
     * @return list<string>
     */
    public function currencyCodesFromTicketDropdown(array $dropdown): array
    {
        $currencies = data_get($dropdown, 'result.currency');
        if (! is_array($currencies)) {
            $currencies = data_get($dropdown, 'currency');
        }

        $codes = [];
        foreach ((array) $currencies as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $code = $this->normalizeCurrency(
                data_get($entry, 'currency_code')
                    ?? data_get($entry, 'price_type')
                    ?? data_get($entry, 'currency')
            );
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function eventCurrencyFromMatchInfo(EventMapping $mapping): ?string
    {
        if ($mapping->relationLoaded('event')) {
            $fromEvent = $this->normalizeCurrency($mapping->event?->getAttribute('price_type'));
            if ($fromEvent !== null) {
                return $fromEvent;
            }
        }

        if (! $mapping->m_id || ! Schema::hasTable('match_info')) {
            return null;
        }

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

        return $fromEvent;
    }

    private function eventCurrencyFromTicketDropdown(int $matchId, ?string $ticketCurrency = null): ?string
    {
        if (! config('services.seller_api.enabled', false)) {
            return null;
        }

        try {
            $dropdown = Cache::remember(
                "seller-api:ticket-dropdown:{$matchId}",
                now()->addHour(),
                fn (): array => $this->sellerApi()->ticketDropdown($matchId),
            );
        } catch (\Throwable $exception) {
            Log::channel(config('services.seller_api.log_channel', 'stack'))->info(
                'Seller API ticket dropdown unavailable while resolving event currency.',
                ['match_id' => $matchId, 'error' => mb_substr($exception->getMessage(), 0, 500)],
            );

            return null;
        }

        $codes = $this->currencyCodesFromTicketDropdown($dropdown);
        if ($codes === []) {
            return null;
        }

        if (count($codes) === 1) {
            return $codes[0];
        }

        $ticket = $this->normalizeCurrency($ticketCurrency);
        if ($ticket !== null && in_array($ticket, $codes, true)) {
            return $ticket;
        }

        return $codes[0];
    }

    private function sellerApi(): SellerApiClient
    {
        return $this->sellerApi ?? app(SellerApiClient::class);
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
