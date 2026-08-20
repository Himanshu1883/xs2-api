<?php

namespace App\Services\Xs2;

use App\Exceptions\Integrations\ListingTransformationException;
use App\Models\EventMapping;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;

/**
 * Gates every Seats Broker listing publish path. Validation failures must throw
 * before any Seller API HTTP call is made.
 */
class ListingPublishValidator
{
    public function __construct(
        private readonly Xs2TicketMappingStatusService $mappingStatuses,
    ) {}

    /**
     * Validate mapping and ticket fields required before building an SB payload.
     *
     * @throws ListingTransformationException
     */
    public function validateForPublish(
        Xs2Ticket $ticket,
        EventMapping $mapping,
        ?Xs2TicketMappingState $mappingState = null,
        bool $strictPublish = false,
    ): void {
        if (! $mapping->m_id) {
            throw new ListingTransformationException('A confirmed local event mapping (match_id) is required before publishing.');
        }

        if (! in_array($mapping->status, ['mapped', 'created'], true)) {
            throw new ListingTransformationException('The event mapping must be confirmed before publishing.');
        }

        if (! ($ticket->xs2Event?->isSellable() ?? false)) {
            throw new ListingTransformationException('This event is no longer sellable, so the listing cannot be published.');
        }

        if ($mappingState !== null) {
            $status = $mappingState->mapping_status;
            $allowed = $strictPublish
                ? $this->mappingStatuses->isManualPublishable($status)
                : $this->mappingStatuses->canAutoPublish($ticket, $status);

            if (! $allowed) {
                throw new ListingTransformationException(
                    $mappingState->mapping_error
                        ?? 'Confirm the event, stadium, and category mappings before publishing.'
                );
            }
        }

        $this->required(trim((string) ($ticket->category_name ?? '')), 'XS2 ticket category');
        $this->required(trim((string) ($ticket->currency_code ?? '')), 'XS2 ticket currency');

        $price = (int) ($ticket->package_price ?? $ticket->net_rate ?? $ticket->face_value ?? 0);
        if ($price <= 0) {
            throw new ListingTransformationException('XS2 ticket price is missing or zero.');
        }
    }

    /**
     * Validate the transformed Seller API create/update payload.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ListingTransformationException
     */
    public function validatePayload(array $payload): void
    {
        $matchId = $payload['match_id'] ?? null;
        if (! is_numeric($matchId) || (int) $matchId < 1) {
            throw new ListingTransformationException('Seller API payload is missing a valid match_id.');
        }

        $this->required(trim((string) ($payload['seller_reference'] ?? '')), 'seller_reference');

        $ticketCategory = $payload['ticket_category'] ?? null;
        $categoryName = trim((string) ($payload['category_name'] ?? ''));
        $hasTicketCategory = (is_int($ticketCategory) || (is_string($ticketCategory) && ctype_digit($ticketCategory)))
            && (int) $ticketCategory >= 1;

        if (! $hasTicketCategory && $categoryName === '') {
            throw new ListingTransformationException(
                'Seller API payload is missing ticket_category or category_name.'
            );
        }

        foreach (['ticket_type', 'split_type', 'seller_id'] as $field) {
            if (! isset($payload[$field]) || ! is_numeric($payload[$field]) || (int) $payload[$field] < 1) {
                throw new ListingTransformationException("Seller API payload is missing a valid {$field}.");
            }
        }

        if (! isset($payload['quantity']) || ! is_numeric($payload['quantity']) || (int) $payload['quantity'] < 0) {
            throw new ListingTransformationException('Seller API payload is missing a valid quantity.');
        }

        $price = $payload['price'] ?? null;
        if ($price === null || $price === '' || (is_numeric($price) && (float) $price <= 0 && ($payload['status'] ?? '1') === '1')) {
            throw new ListingTransformationException('Seller API payload is missing a valid price.');
        }
    }

    private function required(string $value, string $description): string
    {
        if ($value === '') {
            throw new ListingTransformationException("{$description} is missing.");
        }

        return $value;
    }
}
