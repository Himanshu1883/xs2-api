<?php

namespace App\Services\Xs2;

use App\DTOs\Xs2\Xs2CheckoutValidationResult;
use App\DTOs\Xs2\Xs2GuestValidationResult;
use App\Models\ExternalListingMapping;
use Carbon\Carbon;

class Xs2CheckoutValidationService
{
    public function __construct(
        private readonly Xs2Client $client,
        private readonly Xs2TicketNormalizer $normalizer,
        private readonly Xs2TicketRuleService $rules,
        private readonly Xs2GuestValidationService $guestValidation,
    ) {}

    public function validate(string $sellerReference, int $quantity, int $expectedPrice, ?string $expectedCurrency = null): array
    {
        return $this->validateResult($sellerReference, $quantity, $expectedPrice, $expectedCurrency)->toArray();
    }

    /**
     * @param  list<array<string, mixed>>  $guests
     */
    public function validateWithGuests(
        string $sellerReference,
        int $quantity,
        int $expectedPrice,
        array $guests,
        ?string $expectedCurrency = null,
    ): array {
        return $this->validateWithGuestsResult($sellerReference, $quantity, $expectedPrice, $guests, $expectedCurrency)->toArray();
    }

    /**
     * @param  list<array<string, mixed>>  $guests
     */
    public function validateWithGuestsResult(
        string $sellerReference,
        int $quantity,
        int $expectedPrice,
        array $guests,
        ?string $expectedCurrency = null,
    ): Xs2CheckoutValidationResult {
        $result = $this->validateResult($sellerReference, $quantity, $expectedPrice, $expectedCurrency);
        if (! $result->valid) {
            return $result;
        }

        $mapping = ExternalListingMapping::query()->with('ticket.xs2Event')->where('seller_reference', $sellerReference)->first();
        if ($mapping?->ticket === null) {
            return $this->result(false, 'ticket_not_found');
        }

        $guestResult = $this->guestValidation->validateTicketGuests($mapping->ticket, $guests);
        if (! $guestResult->valid) {
            return $this->guestCheckoutResult($guestResult, $result);
        }

        return $result;
    }

    public function validateResult(string $sellerReference, int $quantity, int $expectedPrice, ?string $expectedCurrency = null): Xs2CheckoutValidationResult
    {
        $mapping = ExternalListingMapping::query()->with('ticket')->where('seller_reference', $sellerReference)->first();

        return $this->validateMapping($mapping, $quantity, $expectedPrice, $expectedCurrency);
    }

    public function validateListing(string $sellerListingId, int $quantity, int $expectedPrice, ?string $expectedCurrency = null): Xs2CheckoutValidationResult
    {
        $mapping = ExternalListingMapping::query()->with('ticket')->where('seller_listing_id', $sellerListingId)->first();

        return $this->validateMapping($mapping, $quantity, $expectedPrice, $expectedCurrency);
    }

    private function validateMapping(?ExternalListingMapping $mapping, int $quantity, int $expectedPrice, ?string $expectedCurrency): Xs2CheckoutValidationResult
    {
        if (! $mapping?->ticket) {
            return $this->result(false, 'ticket_not_found');
        }

        try {
            $raw = $this->client->getTicket($mapping->ticket->external_ticket_id);
            if ($raw === [] || data_get($raw, 'deleted') === true) {
                return $this->result(false, 'ticket_not_found');
            }
            $data = $this->normalizer->normalize($raw);
            $ticket = $mapping->ticket;
            $ticket->fill($data);
            $allowed = $this->rules->allowedQuantities($ticket);

            if ($data['ticket_status'] !== 'available') {
                return $this->result(false, 'ticket_unavailable', $data, $allowed);
            }
            if ($this->expired($ticket, $data)) {
                return $this->result(false, 'ticket_expired', $data, $allowed);
            }
            if ((int) $data['stock'] < $quantity) {
                return $this->result(false, 'insufficient_stock', $data, $allowed);
            }
            if (! in_array($quantity, $allowed, true)) {
                return $this->result(false, 'invalid_quantity', $data, $allowed);
            }
            $expectedListingPrice = (int) ($data['package_price'] ?? $data['net_rate'] ?? -1);
            if ($expectedListingPrice !== $expectedPrice) {
                return $this->result(false, 'price_changed', $data, $allowed);
            }
            if ($expectedCurrency && ($data['currency_code'] ?? null) !== strtoupper($expectedCurrency)) {
                return $this->result(false, 'currency_changed', $data, $allowed);
            }

            return $this->result(true, 'valid', $data, $allowed);
        } catch (\InvalidArgumentException) {
            return $this->result(false, 'ticket_not_found');
        } catch (\Throwable) {
            return $this->result(false, 'xs2_temporarily_unavailable');
        }
    }

    /** @param array<string,mixed> $data */
    private function expired($ticket, array $data): bool
    {
        if ($ticket->ticket_valid_until?->isPast()) {
            return true;
        }

        foreach ($data['sales_periods'] ?? [] as $period) {
            if (! is_array($period)) {
                continue;
            }
            $until = $period['valid_until'] ?? $period['until'] ?? $period['end'] ?? null;
            if (is_string($until) && $until !== '') {
                try {
                    if (now()->greaterThan(Carbon::parse($until))) {
                        return true;
                    }
                } catch (\Throwable) {
                    
                }
            }
        }

        return false;
    }

    /** @param array<string,mixed> $ticket @param list<int> $allowed */
    private function result(bool $valid, string $code, array $ticket = [], array $allowed = []): Xs2CheckoutValidationResult
    {
        return new Xs2CheckoutValidationResult(
            valid: $valid,
            reasonCode: $code,
            message: str_replace('_', ' ', $code),
            latestStock: isset($ticket['stock']) ? (int) $ticket['stock'] : null,
            latestPrice: isset($ticket['net_rate']) ? (int) $ticket['net_rate'] : null,
            latestCurrency: $ticket['currency_code'] ?? null,
            allowedQuantities: $allowed,
            rawTicket: $ticket['raw_payload'] ?? null,
        );
    }

    private function guestCheckoutResult(
        Xs2GuestValidationResult $guestResult,
        Xs2CheckoutValidationResult $base,
    ): Xs2CheckoutValidationResult {
        return new Xs2CheckoutValidationResult(
            valid: false,
            reasonCode: $guestResult->reasonCode,
            message: $guestResult->message,
            latestStock: $base->latestStock,
            latestPrice: $base->latestPrice,
            latestCurrency: $base->latestCurrency,
            allowedQuantities: $base->allowedQuantities,
            rawTicket: $base->rawTicket,
            guestViolations: $guestResult->violations,
        );
    }
}
