<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Xs2\TriggerInventorySyncRequest;
use App\Http\Resources\Xs2TicketAdminResource;
use App\Http\Resources\Xs2TicketDetailAdminResource;
use App\Http\Resources\Xs2TicketWithEventAdminResource;
use App\Jobs\DeleteXs2SellerListing;
use App\Jobs\DisableXs2SellerListing;
use App\Jobs\PushXs2TicketToSellerApi;
use App\Jobs\SyncXs2EventInventory;
use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Models\ListingSplit;
use App\Models\Xs2Category;
use App\Models\Xs2Ticket;
use App\Services\SellerApi\ListingSalesService;
use App\Services\SellerApi\SellerApiDebugRecorder;
use App\Services\Xs2\ListingPublishRuleService;
use App\Services\Xs2\MappedListingPublishService;
use App\Services\Xs2\Xs2ApiDebugRecorder;
use App\Services\Xs2\Xs2Client;
use App\Services\Xs2\Xs2EventInventorySyncService;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class Xs2InventoryController extends Controller
{
    public function sync(TriggerInventorySyncRequest $request, EventMapping $mapping)
    {
        $mode = $request->validated('mode', 'incremental');
        SyncXs2EventInventory::dispatch($mapping->id, $mode);

        return response()->json([
            'message' => 'XS2 inventory synchronization queued successfully.',
            'data' => ['mapping_id' => $mapping->id, 'mode' => $mode],
        ], 202);
    }

    public function status(EventMapping $mapping)
    {
        $state = $mapping->xs2Event?->inventorySyncState;

        return response()->json([
            'data' => [
                'mapping_id' => $mapping->id,
                'external_event_id' => $mapping->xs2Event?->external_event_id,
                'status' => $state?->tickets_sync_status ?? 'never_run',
                'last_incremental_sync_at' => $state?->tickets_last_incremental_sync_at,
                'last_full_sync_at' => $state?->tickets_last_full_sync_at,
                'next_sync_at' => $state?->tickets_next_sync_at,
                'error' => $state?->tickets_sync_error ? 'The most recent inventory synchronization failed. Review the application logs.' : null,
            ],
        ]);
    }

    public function tickets(Request $request, EventMapping $mapping)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'mapping_status' => ['nullable', 'string', 'max:50'],
            'push_status' => ['nullable', 'in:pending,processing,synced,failed'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Xs2Ticket::query()
            ->with(['mappingState', 'listingMapping', 'listingSplits' => fn ($q) => $q->orderBy('split_order')])
            ->withCount(['listingSplits as split_listings_count' => fn ($q) => $q->where('status', 'active')])
            ->where('xs2_event_id', $mapping->xs2_event_id)
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('ticket_status', $status))
            ->when($validated['mapping_status'] ?? null, fn ($query, $status) => $query->whereHas('mappingState', fn ($state) => $state->where('mapping_status', $status)))
            ->when($validated['push_status'] ?? null, fn ($query, $status) => $query->where('sync_status', $status));

        $paginator = $query->latest()->paginate($validated['per_page'] ?? 20);
        app(ListingSalesService::class)->attachSalesToTickets($paginator->getCollection());

        return Xs2TicketAdminResource::collection($paginator);
    }

    public function previewXs2Tickets(Request $request, EventMapping $mapping, Xs2Client $client)
    {
        $mapping->loadMissing('xs2Event');
        $externalEventId = $mapping->xs2Event?->external_event_id;
        if (! is_string($externalEventId) || trim($externalEventId) === '') {
            throw ValidationException::withMessages(['mapping' => ['This event mapping has no XS2 external event ID.']]);
        }

        $page = max(1, (int) $request->query('page', 1));
        $preview = $client->previewTicketsForEvent($externalEventId, $page);

        return response()->json([
            'message' => 'XS2 tickets API response retrieved successfully.',
            'data' => [
                'mapping_id' => $mapping->id,
                'external_event_id' => $externalEventId,
                ...$preview,
            ],
        ]);
    }

    /**
     * Live-fetch every XS2 ticket for this event (all pages), record the HTTP
     * trace for the admin viewer, and run a full inventory sync inline so
     * local inventory (and event-card listings_count) update immediately.
     *
     * Queuing alone is not enough: xs2-sync can back up, and ShouldBeUnique
     * silently drops a re-dispatch while an older job for the same mapping
     * is still waiting.
     */
    public function fetchInventory(
        EventMapping $mapping,
        Xs2Client $client,
        Xs2ApiDebugRecorder $recorder,
        Xs2EventInventorySyncService $inventorySync,
    ): JsonResponse {
        $mapping->loadMissing('xs2Event');
        $externalEventId = $mapping->xs2Event?->external_event_id;
        if (! is_string($externalEventId) || trim($externalEventId) === '') {
            throw ValidationException::withMessages(['mapping' => ['This event mapping has no XS2 external event ID.']]);
        }

        $recorder->enable();

        try {
            // Capture the admin-initiated ticket pull for the debug viewer,
            // then persist via the same full sync path the queue job uses.
            $tickets = $client->getTicketsForEvent($externalEventId);
            $interactions = $recorder->flush();
            $debug = $recorder->persist($interactions, $mapping->id, $externalEventId);

            $recorder->disable();
            $summary = $inventorySync->sync($mapping, 'full');
            $ticketsSaved = (int) $summary['tickets_created']
                + (int) $summary['tickets_updated']
                + (int) $summary['tickets_unchanged'];

            return response()->json([
                'message' => 'XS2 inventory fetched and synchronized successfully.',
                'data' => [
                    'mapping_id' => $mapping->id,
                    'external_event_id' => $externalEventId,
                    'tickets_fetched' => count($tickets),
                    'tickets_saved' => $ticketsSaved,
                    'tickets_created' => (int) $summary['tickets_created'],
                    'tickets_updated' => (int) $summary['tickets_updated'],
                    'tickets_unchanged' => (int) $summary['tickets_unchanged'],
                    'sync_queued' => false,
                    'sync_completed' => true,
                    'sync_mode' => 'full',
                    'xs2_api_debug' => $debug,
                ],
            ]);
        } catch (\Throwable $exception) {
            $interactions = $recorder->flush();
            if ($interactions !== []) {
                $recorder->persist($interactions, $mapping->id, $externalEventId);
            }

            throw $exception;
        } finally {
            $recorder->disable();
        }
    }

    /**
     * Last XS2 API debug payload for this mapping when available; otherwise the
     * last global XS2 API call recorded by any admin fetch.
     */
    public function lastApiDebug(EventMapping $mapping, Xs2ApiDebugRecorder $recorder): JsonResponse
    {
        $result = $recorder->lastForMapping($mapping->id);

        return response()->json([
            'message' => $result['payload'] === null
                ? 'No XS2 API debug payload has been recorded yet.'
                : ($result['scope'] === 'event'
                    ? 'Last XS2 API call for this event.'
                    : 'No event-scoped debug found; returning the last global XS2 API call.'),
            'data' => [
                'mapping_id' => $mapping->id,
                'scope' => $result['scope'],
                'debug' => $result['payload'],
            ],
        ]);
    }

    /**
     * Single-ticket detail. Unlike tickets()/allTickets(), this also carries
     * the fields normalized straight from the stored XS2 payload (title,
     * validity window, net/face rate, category type) plus the raw payload
     * itself, so the admin frontend can show a full detail view without
     * bloating the paginated list response.
     */
    public function show(Xs2Ticket $ticket)
    {
        $ticket->load(['mappingState', 'listingMapping', 'listingSplits' => fn ($q) => $q->orderBy('split_order')]);
        app(ListingSalesService::class)->attachSalesToTickets(collect([$ticket]));

        $categoryType = Xs2Category::query()
            ->where('external_category_id', $ticket->category_id)
            ->where('xs2_event_id', $ticket->xs2_event_id)
            ->with('context')
            ->first()
            ?->context
            ?->category_type;

        return new Xs2TicketDetailAdminResource($ticket, $categoryType);
    }

    /**
     * Cross-event ticket listing. Unlike tickets(), this is not scoped to a
     * single event mapping, so each row also carries its parent event's
     * name/venue/city (see Xs2TicketWithEventAdminResource).
     */
    public function allTickets(Request $request)
    {
        $validated = $request->validate([
            'mapping_status' => ['nullable', 'string', 'max:50'],
            'event_mapping_status' => ['nullable', 'in:mapped,unmapped'],
            'push_status' => ['nullable', 'in:pending,processing,synced,failed'],
            'search' => ['nullable', 'string', 'max:255'],
            // League/tournament name — same filter shape as event-mappings.
            'tournament' => ['nullable', 'string', 'max:255'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Paginate by distinct parent events (returns all tickets for each page of events).
            'group_by_event' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('group_by_event')) {
            return $this->allTicketsGroupedByEvent($validated);
        }

        $query = $this->allTicketsFilteredQuery($validated)
            ->with([
                'mappingState',
                'listingMapping',
                'listingSplits' => fn ($q) => $q->orderBy('split_order'),
                'xs2Event.mapping.event',
                'xs2Event.venue.stadiumMapping',
            ])
            ->withCount(['listingSplits as split_listings_count' => fn ($q) => $q->where('status', 'active')]);

        $paginator = $query->latest()->paginate($validated['per_page'] ?? 20);
        $this->attachListingSalesWhenNeeded($paginator->getCollection(), $validated['mapping_status'] ?? null);
        $this->refreshStaleTicketMappingStates($paginator->getCollection());

        return Xs2TicketWithEventAdminResource::collection($paginator);
    }

    /**
     * Returns every ticket belonging to a page of parent events. Pagination meta
     * counts events, not ticket rows — used by the unpublished listings UI.
     *
     * @param  array<string, mixed>  $validated
     */
    private function allTicketsGroupedByEvent(array $validated)
    {
        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = max(1, (int) ($validated['page'] ?? 1));

        $filtered = $this->allTicketsFilteredQuery($validated);
        $eventsTable = (new Xs2Ticket)->xs2Event()->getRelated()->getTable();

        $totalEvents = (clone $filtered)
            ->join($eventsTable, "{$eventsTable}.id", '=', 'xs2_tickets.xs2_event_id')
            ->distinct()
            ->count('xs2_tickets.xs2_event_id');

        $eventIds = (clone $filtered)
            ->join($eventsTable, "{$eventsTable}.id", '=', 'xs2_tickets.xs2_event_id')
            ->select('xs2_tickets.xs2_event_id')
            ->groupBy('xs2_tickets.xs2_event_id', "{$eventsTable}.date_start_local")
            ->orderBy("{$eventsTable}.date_start_local")
            ->orderBy('xs2_tickets.xs2_event_id')
            ->forPage($page, $perPage)
            ->pluck('xs2_event_id');

        if ($eventIds->isEmpty()) {
            $paginator = new LengthAwarePaginator([], $totalEvents, $perPage, $page, [
                'path' => request()->url(),
                'query' => request()->query(),
            ]);

            return $this->eventGroupedTicketsResponse($paginator);
        }

        $tickets = $this->allTicketsFilteredQuery($validated)
            ->with([
                'mappingState',
                'listingMapping',
                'listingSplits' => fn ($q) => $q->orderBy('split_order'),
                'xs2Event.mapping.event',
                'xs2Event.venue.stadiumMapping',
            ])
            ->withCount(['listingSplits as split_listings_count' => fn ($q) => $q->where('status', 'active')])
            ->whereIn('xs2_tickets.xs2_event_id', $eventIds)
            ->join($eventsTable, "{$eventsTable}.id", '=', 'xs2_tickets.xs2_event_id')
            ->select('xs2_tickets.*')
            ->orderBy("{$eventsTable}.date_start_local")
            ->orderBy('xs2_tickets.id')
            ->get();

        $this->attachListingSalesWhenNeeded($tickets, $validated['mapping_status'] ?? null);
        $this->refreshStaleTicketMappingStates($tickets);

        $paginator = new LengthAwarePaginator(
            $tickets,
            $totalEvents,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );

        return $this->eventGroupedTicketsResponse($paginator);
    }

    /**
     * @param  LengthAwarePaginator<int, Xs2Ticket>  $paginator
     */
    private function eventGroupedTicketsResponse(LengthAwarePaginator $paginator)
    {
        $response = Xs2TicketWithEventAdminResource::collection($paginator);
        $payload = $response->response()->getData(true);
        $payload['meta']['group_by'] = 'event';

        return response()->json($payload);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return Builder<Xs2Ticket>
     */
    private function allTicketsFilteredQuery(array $validated): Builder
    {
        return Xs2Ticket::query()
            ->tap(fn ($query) => $this->constrainToFutureEvents($query))
            ->tap(fn ($query) => $this->applyTicketMappingStatusFilter($query, $validated['mapping_status'] ?? null))
            ->tap(fn ($query) => $this->applyEventMappingStatusFilter($query, $validated['event_mapping_status'] ?? null))
            ->when($validated['push_status'] ?? null, fn ($query, $status) => $query->where('sync_status', $status))
            ->when($validated['tournament'] ?? null, fn ($query, $tournament) => $query->whereHas(
                'xs2Event',
                fn ($event) => $event->where('tournament_name', $tournament),
            ))
            ->when($validated['currency_code'] ?? null, function ($query, $currencyCode): void {
                $normalized = strtoupper(trim((string) $currencyCode));
                if ($normalized === '') {
                    return;
                }

                $query->whereRaw(
                    'UPPER(COALESCE(NULLIF(currency_code, \'\'), \'EUR\')) = ?',
                    [$normalized],
                );
            })
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('category_name', 'like', "%{$search}%")
                        ->orWhereHas('xs2Event', function ($query) use ($search): void {
                            $query->where('event_name', 'like', "%{$search}%")
                                ->orWhere('venue_name', 'like', "%{$search}%")
                                ->orWhere('city', 'like', "%{$search}%");
                        });
                });
            });
    }

    /** Unpublished listings do not surface sold/remaining qty — skip the SB orders lookup. */
    private function attachListingSalesWhenNeeded($tickets, ?string $mappingStatus): void
    {
        if ($mappingStatus === 'unpublished') {
            foreach ($tickets as $ticket) {
                $ticket->setAttribute('sold_quantity', 0);
                $ticket->setAttribute('remaining_quantity', max(0, (int) ($ticket->stock ?? 0)));
                $ticket->setAttribute('split_sales', []);
            }

            return;
        }

        app(ListingSalesService::class)->attachSalesToTickets($tickets);
    }

    /**
     * Aggregate ticket counts for listing dashboards. Pass mapping_status=unpublished
     * to scope stock/error metrics to tickets that are not yet published.
     * Counts are limited to future events (`date_start_local >= now`).
     * `published_ticket_qty` is the sum of stock on published listings (not listing rows).
     */
    public function ticketSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mapping_status' => ['nullable', 'string', 'max:50'],
        ]);

        $lowStockMax = max(1, (int) config('xs2.inventory.low_stock_max', 10));
        $scoped = Xs2Ticket::query()
            ->tap(fn ($query) => $this->constrainToFutureEvents($query))
            ->tap(fn ($query) => $this->applyTicketMappingStatusFilter($query, $validated['mapping_status'] ?? null));

        $publishedQuery = (clone $scoped)->whereHas(
            'mappingState',
            fn ($state) => $state->where('mapping_status', 'published'),
        );

        $published = (clone $publishedQuery)->count();
        $publishedTicketQty = (int) (clone $publishedQuery)->sum('stock');

        $total = (clone $scoped)->count();

        $noStock = (clone $scoped)
            ->where(fn ($query) => $query->whereNull('stock')->orWhere('stock', '<=', 0))
            ->count();

        $withStockNotPublished = $this->countWithStockNotPublishedOnSb($scoped);

        $lowStock = (clone $scoped)
            ->where('stock', '>', 0)
            ->where('stock', '<=', $lowStockMax)
            ->count();

        $errors = (clone $scoped)
            ->where(function ($query): void {
                $query->where('sync_status', 'failed')
                    ->orWhereHas('mappingState', function ($state): void {
                        $state->where('mapping_status', 'unsupported_category')
                            ->orWhere(function ($state): void {
                                $state->whereNotNull('mapping_error')
                                    ->where('mapping_error', '!=', '');
                            });
                    });
            })
            ->count();

        $masterSbListings = (clone $scoped)
            ->where('split_enabled', false)
            ->whereHas(
                'listingMapping',
                fn ($mapping) => $mapping
                    ->where('provider', 'xs2event')
                    ->where('status', 'active')
                    ->whereNotNull('seller_listing_id'),
            )
            ->count();

        $splitSbListings = 0;
        if (Schema::hasTable('listing_splits')) {
            $scopedTicketIds = (clone $scoped)->select('xs2_tickets.id');
            $splitSbListings = ListingSplit::query()
                ->where('status', 'active')
                ->whereNotNull('seatsbroker_listing_id')
                ->whereIn('master_listing_id', $scopedTicketIds)
                ->count();
        }

        $sbActiveListings = $masterSbListings + $splitSbListings;

        return response()->json([
            'data' => [
                'total' => $total,
                'published' => $published,
                'published_ticket_qty' => $publishedTicketQty,
                'pending' => max(0, $total - $published),
                'no_stock' => $noStock,
                'with_stock_not_published' => $withStockNotPublished,
                'low_stock' => $lowStock,
                'errors' => $errors,
                'low_stock_max' => $lowStockMax,
                'sb_active_listings' => $sbActiveListings,
                'sb_master_listings' => $masterSbListings,
                'sb_split_listings' => $splitSbListings,
            ],
        ]);
    }

    /**
     * Unpublished XS2 listings that still have stock but are not live on SeatsBroker
     * (no active master or split seller listing id).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Xs2Ticket>  $scoped
     */
    private function countWithStockNotPublishedOnSb($scoped): int
    {
        return (clone $scoped)
            ->where('stock', '>', 0)
            ->where(function ($query): void {
                $query->whereDoesntHave('listingMapping', fn ($mapping) => $mapping
                    ->where('provider', 'xs2event')
                    ->where('status', 'active')
                    ->whereNotNull('seller_listing_id'),
                );

                if (Schema::hasTable('listing_splits')) {
                    $query->whereDoesntHave('listingSplits', fn ($split) => $split
                        ->where('status', 'active')
                        ->whereNotNull('seatsbroker_listing_id'),
                    );
                }
            })
            ->count();
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Xs2Ticket>  $query */
    private function constrainToFutureEvents($query)
    {
        return $query->whereHas(
            'xs2Event',
            fn ($event) => $event->where('date_start_local', '>=', now()),
        );
    }

    /** @param  \Illuminate\Support\Collection<int, \App\Models\Xs2Ticket>|\Illuminate\Database\Eloquent\Collection<int, \App\Models\Xs2Ticket>  $tickets */
    private function refreshStaleTicketMappingStates($tickets): void
    {
        if ($tickets->isEmpty()) {
            return;
        }

        $mappingStates = app(Xs2TicketMappingStatusService::class);
        foreach ($tickets as $ticket) {
            $mappingStates->resolveIfStale($ticket);
            $ticket->load('mappingState');
        }
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Xs2Ticket>  $query */
    private function applyTicketMappingStatusFilter($query, ?string $status)
    {
        if ($status === null || $status === '') {
            return $query;
        }

        if ($status === 'unpublished') {
            // All mapping states except published (pending_*, ready_to_publish, unsupported_category, …).
            return $query->whereHas(
                'mappingState',
                fn ($state) => $state->where('mapping_status', '!=', 'published'),
            );
        }

        return $query->whereHas(
            'mappingState',
            fn ($state) => $state->where('mapping_status', $status),
        );
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Xs2Ticket>  $query */
    private function applyEventMappingStatusFilter($query, ?string $status)
    {
        if ($status === null || $status === '') {
            return $query;
        }

        if ($status === 'mapped') {
            return $query->whereHas(
                'xs2Event.mapping',
                fn ($mapping) => $mapping->whereIn('status', ['mapped', 'created']),
            );
        }

        return $query->where(function ($query): void {
            $query->whereDoesntHave('xs2Event.mapping')
                ->orWhereHas(
                    'xs2Event.mapping',
                    fn ($mapping) => $mapping->whereNotIn('status', ['mapped', 'created']),
                );
        });
    }

    public function retryListing(Request $request, Xs2Ticket $ticket): JsonResponse
    {
        $mappingStates = app(Xs2TicketMappingStatusService::class);
        $status = $mappingStates->manualPublishStatus($ticket);
        if (! $mappingStates->isManualPublishable($status)) {
            throw ValidationException::withMessages(['ticket' => ['The ticket is not ready to publish. Confirm the event and venue mappings first.']]);
        }
        if ($ticket->split_enabled && ! app(ListingPublishRuleService::class)->rulesEnabled()) {
            throw ValidationException::withMessages([
                'ticket' => ['This ticket uses split listings. Use Publish Split Listings instead.'],
            ]);
        }
        $ticket->update(['sync_status' => 'pending', 'sync_error' => null]);

        $publisher = app(MappedListingPublishService::class);

        if ($request->boolean('sync')) {
            return $this->runListingJobSynchronously(
                $ticket,
                fn () => $publisher->publishTicket($ticket->id, strictPublish: true, sync: true),
                'Seller listing published successfully.',
                'Seller listing publish failed.',
            );
        }

        $publisher->publishTicket($ticket->id, strictPublish: true);

        return response()->json([
            'message' => 'Seller listing retry queued successfully.',
            'data' => ['ticket_id' => $ticket->id, 'queued' => true],
        ], 202);
    }

    public function disableListing(Request $request, Xs2Ticket $ticket): JsonResponse
    {
        if ($request->boolean('sync')) {
            return $this->runListingJobSynchronously(
                $ticket,
                fn () => DisableXs2SellerListing::dispatchSync($ticket->id),
                'Seller listing disabled successfully.',
                'Seller listing disable failed.',
            );
        }

        DisableXs2SellerListing::dispatch($ticket->id);

        return response()->json([
            'message' => 'Seller listing disable queued successfully.',
            'data' => ['ticket_id' => $ticket->id, 'queued' => true],
        ], 202);
    }

    public function deleteListing(Request $request, Xs2Ticket $ticket): JsonResponse
    {
        if ($request->boolean('sync')) {
            $capturedSellerResponse = null;

            return $this->runListingJobSynchronously(
                $ticket,
                function () use ($ticket, &$capturedSellerResponse): void {
                    DeleteXs2SellerListing::dispatchSync($ticket->id);
                    $mapping = ExternalListingMapping::query()
                        ->where('provider', 'xs2event')
                        ->where('xs2_ticket_id', $ticket->id)
                        ->first();
                    $capturedSellerResponse = $mapping?->last_response;
                },
                'Seller listing removed successfully.',
                'Seller listing removal failed.',
                $capturedSellerResponse,
            );
        }

        DeleteXs2SellerListing::dispatch($ticket->id);

        return response()->json([
            'message' => 'Seller listing removal queued successfully.',
            'data' => ['ticket_id' => $ticket->id, 'queued' => true],
        ], 202);
    }

    /** @param  callable(): void  $run */
    private function runListingJobSynchronously(
        Xs2Ticket $ticket,
        callable $run,
        string $successMessage,
        string $failureMessage,
        mixed $sellerResponseOverride = null,
    ): JsonResponse {
        $recorder = app(SellerApiDebugRecorder::class);
        $recorder->enable();

        try {
            try {
                $run();
            } catch (\Throwable $e) {
                $ticket->refresh();
                $ticket->load(['mappingState', 'listingMapping']);

                $data = $this->listingActionData($ticket, $sellerResponseOverride, $recorder->flush());
                $detail = trim($e->getMessage());
                if ($detail !== '') {
                    $data['last_error'] = mb_substr($detail, 0, 5000);
                }

                return response()->json([
                    'message' => $failureMessage,
                    'data' => $data,
                ], 422);
            }

            $ticket->refresh();
            $ticket->load(['mappingState', 'listingMapping']);

            return response()->json([
                'message' => $successMessage,
                'data' => $this->listingActionData($ticket, $sellerResponseOverride, $recorder->flush()),
            ]);
        } finally {
            $recorder->disable();
        }
    }

    /** @return array<string, mixed> */
    private function listingActionData(Xs2Ticket $ticket, mixed $sellerResponseOverride = null, ?array $sellerApiDebug = null): array
    {
        $listing = $ticket->listingMapping;

        $data = [
            'ticket_id' => $ticket->id,
            'queued' => false,
            'sync_status' => $ticket->sync_status,
            'mapping_status' => $ticket->mappingState?->mapping_status,
            'seller_listing_id' => $listing?->seller_listing_id,
            'listing_status' => $listing?->status,
            'seller_response' => $sellerResponseOverride ?? $listing?->last_response,
            'last_error' => $listing?->last_error ?? $ticket->sync_error,
        ];

        if ($sellerApiDebug !== null) {
            $data['seller_api_debug'] = $sellerApiDebug;
        }

        return $data;
    }
}
