<?php

namespace App\Services\Xs2;

use App\Exceptions\Integrations\ListingTransformationException;
use App\Models\Xs2Ticket;
use Illuminate\Support\Facades\Schema;

/**
 * Runs the full publish gate (mapping + transform + payload) before any SB HTTP call
 * is queued. Auto-publish paths must pass this check first.
 */
class ListingPublishReadinessService
{
    public function __construct(
        private readonly Xs2TicketMappingStatusService $mappingStatuses,
        private readonly ListingPublishValidator $validator,
        private readonly Xs2SellerListingTransformer $transformer,
    ) {}

    /**
     * @param  array{quantity?: int, pairs_only?: bool}|null  $transformOverrides
     * @return array{ready: bool, error: ?string}
     */
    public function assess(
        Xs2Ticket $ticket,
        bool $strictPublish = false,
        ?array $transformOverrides = null,
    ): array {
        $ticket->loadMissing(['xs2Event.mapping']);

        $mapping = $ticket->xs2Event?->mapping;
        if (! $mapping) {
            return [
                'ready' => false,
                'error' => 'A confirmed local event mapping is required before publishing.',
            ];
        }

        $mappingState = null;
        if (Schema::hasTable('xs2_ticket_mapping_states')) {
            $mappingState = $this->mappingStatuses
                ->resolveIfStale($ticket)
                ->loadMissing('categoryMapping.details');
        }

        try {
            $this->validator->validateForPublish($ticket, $mapping, $mappingState, $strictPublish);

            $payload = $mappingState
                ? $this->transformer->transform($ticket, $mapping, $mappingState, $transformOverrides)
                : $this->transformer->transform($ticket, $mapping, null, $transformOverrides);

            $this->validator->validatePayload($payload);

            return ['ready' => true, 'error' => null];
        } catch (ListingTransformationException $exception) {
            return ['ready' => false, 'error' => $exception->getMessage()];
        } catch (\Throwable $exception) {
            return ['ready' => false, 'error' => mb_substr($exception->getMessage(), 0, 1000)];
        }
    }
}
