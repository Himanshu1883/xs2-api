<?php

namespace App\Jobs;

use App\Exceptions\Integrations\ListingTransformationException;
use App\Exceptions\Integrations\SellerApiRequestException;
use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Models\Xs2Ticket;
use App\Services\SellerApi\SellerApiClient;
use App\Services\Xs2\ListingPublishValidator;
use App\Services\Xs2\Xs2SellerListingTransformer;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PushXs2TicketToSellerApi implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    /**
     * @param  bool  $strictPublish  When true (admin Publish Listing), abort with an
     *                               error instead of silently queueing disable work.
     */
    public function __construct(
        public int $ticketId,
        public bool $strictPublish = false,
        public ?int $quantityOverride = null,
        public ?bool $pairsOnlyOverride = null,
    ) {
        $this->onQueue(config('services.seller_api.queue'));
    }

    public function uniqueId(): string
    {
        return 'xs2-listing:'.$this->ticketId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('xs2-seller-listing:'.$this->ticketId))
                ->shared()
                ->releaseAfter(70)
                ->expireAfter(130),
        ];
    }

    public function handle(SellerApiClient $client, Xs2SellerListingTransformer $transformer, ListingPublishValidator $validator): void
    {
        $query = Xs2Ticket::with('xs2Event.mapping');
        if (Schema::hasTable('xs2_ticket_mapping_states')) {
            $query->with('mappingState.categoryMapping.details');
        }
        $ticket = $query->findOrFail($this->ticketId);

        // Split-enabled masters publish via SplitListingService, not the 1:1 path.
        if ($ticket->split_enabled) {
            if ($this->strictPublish) {
                throw new ListingTransformationException(
                    'This ticket uses split listings. Use Publish Split Listings instead.',
                );
            }

            SyncSplitListings::dispatch($this->ticketId);

            return;
        }

        $mapping = $ticket->xs2Event->mapping;
        if (! $mapping || ! in_array($mapping->status, ['mapped', 'created'], true) || ! $mapping->m_id) {
            $this->abortPublish(
                $ticket,
                'A confirmed local event mapping is required before publishing.',
            );

            return;
        }
        // A queued publish can outlive the event that made it eligible. Do not
        // create a Seller record after cancellation or kickoff; retire any
        // existing record through the idempotent disable path instead.
        if (! $ticket->xs2Event->isSellable()) {
            $this->abortPublish(
                $ticket,
                'This event is no longer sellable, so the listing cannot be published.',
                retireListing: true,
            );

            return;
        }
        $mappingState = null;
        if (Schema::hasTable('xs2_ticket_mapping_states')) {
            // Mapping rows can change after this publish was queued. Resolve
            // against current event/stadium/category state instead of trusting
            // a previously "published" snapshot.
            $mappingState = app(Xs2TicketMappingStatusService::class)
                ->resolveIfStale($ticket)
                ->loadMissing('categoryMapping.details');
        }
        $mappingStatusService = app(Xs2TicketMappingStatusService::class);
        $canPublish = $mappingState && (
            $this->strictPublish
                ? $mappingStatusService->isManualPublishable($mappingState->mapping_status)
                : $mappingStatusService->isAutoPublishable($mappingState->mapping_status)
        );
        if (Schema::hasTable('xs2_ticket_mapping_states') && ! $canPublish) {
            $this->abortPublish(
                $ticket,
                $mappingState?->mapping_error
                    ?? 'Confirm the event, stadium, and category mappings before publishing.',
            );

            return;
        }
        $ticket->update(['sync_status' => 'processing', 'sync_error' => null]);

        $transformOverrides = $this->transformOverrides();

        try {
            $validator->validateForPublish($ticket, $mapping, $mappingState, $this->strictPublish);

            $payload = $mappingState
                ? $transformer->transform($ticket, $mapping, $mappingState, $transformOverrides)
                : $transformer->transform($ticket, $mapping, null, $transformOverrides);

            $validator->validatePayload($payload);
        } catch (\Throwable $e) {
            Log::error('XS2 listing transformation failed', [
                'match_id' => $mapping->m_id,
                'event_mapping_id' => $mapping->id,
                'xs2_ticket_id' => $ticket->id,
                'external_ticket_id' => $ticket->external_ticket_id,
                'exception' => $e,
            ]);
            $ticket->update(['sync_status' => 'failed', 'sync_error' => mb_substr($e->getMessage(), 0, 5000)]);

            $this->failWithoutRetry($e);
        }

        $hash = hash('sha256', json_encode($this->stable($payload), JSON_THROW_ON_ERROR));
        $listing = ExternalListingMapping::firstOrCreate(['provider' => 'xs2event', 'xs2_ticket_id' => $ticket->id], ['local_event_id' => $mapping->m_id, 'event_mapping_id' => $mapping->id, 'seller_reference' => $payload['seller_reference']]);

        try {
            if ($this->belongsToDifferentMapping($listing, $mapping)) {
                $this->retireStaleListing($listing, $client);
                $listing->update(['seller_reference' => $payload['seller_reference']]);
            }

            if ($listing->last_payload_hash === $hash && $listing->status === 'active' && $listing->seller_listing_id) {
                $ticket->update(['sync_status' => 'synced', 'sync_error' => null]);

                return;
            }

            // The Seller API stores seller_reference and deduplicates creates
            // with the same Idempotency-Key header. Keep both values stable so
            // a timeout after an external success cannot create a duplicate.
            $sellerPayload = $payload;
            $creating = ! $listing->seller_listing_id;
            try {
                $response = $creating
                    ? $client->createListing($sellerPayload, $listing->seller_reference)
                    : $client->updateListing($listing->seller_listing_id, $sellerPayload);
                $sellerListingId = $creating
                    ? $client->listingId($response)
                    : $listing->seller_listing_id;
            } catch (\Throwable $exception) {
                $recovered = $creating ? $this->recoverAmbiguousCreate($client, $listing->seller_reference, $exception) : null;
                if (! $recovered) {
                    throw $exception;
                }
                $response = $recovered;
                $sellerListingId = $client->listingId($response);
            }
            $active = $payload['status'] === '1';

            $listing->update([
                'local_event_id' => $mapping->m_id,
                'event_mapping_id' => $mapping->id,
                'seller_reference' => $payload['seller_reference'],
                'seller_listing_id' => $sellerListingId,
                'status' => $active ? 'active' : 'inactive',
                'last_payload_hash' => $hash,
                'last_pushed_quantity' => $payload['quantity'],
                'last_pushed_price' => $payload['price'],
                'last_request' => $sellerPayload,
                'last_response' => $response,
                'last_error' => null,
                'last_pushed_at' => now(),
                'disabled_at' => $active ? null : now(),
            ]);
            $ticket->update(['sync_status' => 'synced', 'sync_error' => null]);
            if ($mappingState) {
                $mappingState->update(['mapping_status' => 'published', 'mapping_error' => null]);
            }
        } catch (\Throwable $e) {
            $listing->update(['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 5000), 'last_request' => $payload]);
            $ticket->update(['sync_status' => 'failed', 'sync_error' => mb_substr($e->getMessage(), 0, 5000)]);

            $this->failWithoutRetry($e);
        }
    }

    /** @return array<string,mixed>|null */
    private function recoverAmbiguousCreate(SellerApiClient $client, string $reference, \Throwable $exception): ?array
    {
        // Lookup is only a safe recovery path for a timeout/temporary upstream
        // failure. Validation failures must never be converted into a lookup.
        $status = $exception instanceof SellerApiRequestException
            ? $exception->status
            : ($exception->getCode() ?: null);

        if (! $client->canFindListingByExternalReference()
            || ($status !== null && $status > 0 && $status < 500 && $status !== 408 && $status !== 429)) {
            return null;
        }

        $existing = $client->findListingByExternalReference($reference);
        if (! $existing) {
            return null;
        }

        return $existing;
    }

    /**
     * All errors are permanent — the admin must manually retry from the UI.
     * Direct handle() calls in tests still throw so assertions keep working.
     */
    private function failWithoutRetry(\Throwable $exception): never
    {
        if ($this->job !== null) {
            $this->fail($exception);
        }

        throw $exception;
    }

    private function abortPublish(Xs2Ticket $ticket, string $message, bool $retireListing = false): void
    {
        if ($this->strictPublish) {
            throw new ListingTransformationException($message);
        }

        $ticket->update([
            'sync_status' => 'pending',
            'sync_error' => mb_substr($message, 0, 5000),
        ]);

        if ($retireListing) {
            DisableSellerListing::dispatch($ticket->id);
        }
    }

    /** @return array{quantity?: int, pairs_only?: bool}|null */
    private function transformOverrides(): ?array
    {
        if ($this->quantityOverride === null && $this->pairsOnlyOverride === null) {
            return null;
        }

        $overrides = [];
        if ($this->quantityOverride !== null) {
            $overrides['quantity'] = max(0, $this->quantityOverride);
        }
        if ($this->pairsOnlyOverride !== null) {
            $overrides['pairs_only'] = $this->pairsOnlyOverride;
        }

        return $overrides;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function stable(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->stable($value);
            }
        }
        ksort($payload);

        return $payload;
    }

    private function belongsToDifferentMapping(ExternalListingMapping $listing, EventMapping $mapping): bool
    {
        return $listing->seller_listing_id
            && ((int) $listing->event_mapping_id !== (int) $mapping->id
                || (int) $listing->local_event_id !== (int) $mapping->m_id);
    }

    private function retireStaleListing(ExternalListingMapping $listing, SellerApiClient $client): void
    {
        if ($listing->status !== 'inactive' || $listing->last_pushed_quantity !== 0) {
            $payload = [
                'match_id' => $listing->local_event_id,
                'seller_id' => $client->sellerId(),
                'status' => '0',
            ];
            $response = $client->disableListing($listing->seller_listing_id, $payload);

            $listing->update([
                'status' => 'inactive',
                'last_pushed_quantity' => 0,
                'last_request' => $payload,
                'last_response' => $response,
                'last_error' => null,
                'disabled_at' => now(),
            ]);
        }

        // A supplier listing belongs to one local event. Once its previous
        // event is disabled, force a fresh create for the replacement event.
        $listing->update([
            'seller_listing_id' => null,
            'last_payload_hash' => null,
        ]);
    }
}
