<?php

namespace App\Services\Xs2;

use App\Exceptions\Integrations\ListingTransformationException;
use App\Models\Xs2Ticket;
use Illuminate\Support\Facades\Schema;

/**
 * Local publish gate before a Seller API job is queued.
 *
 * Incomplete rows (unmapped event, no price, no stock, empty category name)
 * stay blocked. Pending category/stadium mapping is allowed when the ticket
 * already carries an XS2 category name — the queued job builds the payload.
 */
class ListingPublishReadinessService
{
    public function __construct(
        private readonly Xs2TicketMappingStatusService $mappingStatuses,
        private readonly ListingPublishValidator $validator,
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

        try {
            $mappingState = null;
            if (Schema::hasTable('xs2_ticket_mapping_states')) {
                $mappingState = $this->mappingStatuses->resolveIfStale($ticket);
                $mappingState?->loadMissing('categoryMapping.details');
            }

            $this->validator->validateForPublish($ticket, $mapping, $mappingState, $strictPublish);

            // Payload transform talks to Seller API (dropdown/catalog). Do not
            // require it here — that blocked dispatch for mapped events that
            // publish with the XS2 category name. PushXs2TicketToSellerApi /
            // PublishSplitListings still transform + validatePayload.

            return ['ready' => true, 'error' => null];
        } catch (ListingTransformationException $exception) {
            return ['ready' => false, 'error' => $exception->getMessage()];
        } catch (\Throwable $exception) {
            return ['ready' => false, 'error' => mb_substr($exception->getMessage(), 0, 1000)];
        }
    }
}
