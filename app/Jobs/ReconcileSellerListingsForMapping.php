<?php

namespace App\Jobs;

use App\Models\EventMapping;
use App\Models\Xs2CategoryMapping;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\SellerApi\SbNewListingPublishService;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileSellerListingsForMapping implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public int $mappingId)
    {
        $this->onQueue(config('services.seller_api.queue'));
    }

    public function uniqueId(): string
    {
        return 'xs2-listing-reconciliation:'.$this->mappingId;
    }

    public function handle(
        Xs2TicketMappingStatusService $mappingStates,
        ?SbNewListingPublishService $sbPublish = null,
    ): void {
        $sbPublish ??= app(SbNewListingPublishService::class);

        $mapping = EventMapping::with('xs2Event.tickets')->find($this->mappingId);
        if (! $mapping?->xs2Event) {
            return;
        }

        $eventSellable = $mapping->xs2Event->isSellable();
        $eventMappingReady = in_array($mapping->status, ['mapped', 'created'], true) && filled($mapping->m_id);

        foreach ($mapping->xs2Event->tickets as $ticket) {
            $this->reconcileTicket(
                $ticket,
                $mappingStates,
                $sbPublish,
                $eventSellable,
                $eventMappingReady,
            );
        }
    }

    private function reconcileTicket(
        Xs2Ticket $ticket,
        Xs2TicketMappingStatusService $mappingStates,
        SbNewListingPublishService $sbPublish,
        bool $eventSellable,
        bool $eventMappingReady,
    ): void {
        $available = $this->isAvailable($ticket) && $eventSellable;

        if (! $eventSellable || ! $available) {
            if ($sbPublish->isPublishedOnSb($ticket)) {
                DisableSellerListing::dispatch($ticket->id);
            }

            return;
        }

        if (! $eventMappingReady) {
            if ($sbPublish->isPublishedOnSb($ticket)) {
                DisableSellerListing::dispatch($ticket->id);
            }

            return;
        }

        $state = $mappingStates->resolve($ticket);

        if ($this->shouldRetireListing($ticket, $state)) {
            if ($sbPublish->isPublishedOnSb($ticket)) {
                DisableSellerListing::dispatch($ticket->id);
            }

            return;
        }

        // Pending stadium/category mapping alone must not publish or retire listings.
        // First-time publish is handled only by xs2:publish-new-sb-listings.
    }

    private function shouldRetireListing(Xs2Ticket $ticket, Xs2TicketMappingState $state): bool
    {
        if ($state->mapping_status === 'unsupported_category') {
            return true;
        }

        $state->loadMissing('categoryMapping');
        if ($state->categoryMapping?->status === 'ignored') {
            return true;
        }

        $liveCategoryMapping = Xs2CategoryMapping::query()
            ->whereHas('category', function ($query) use ($ticket): void {
                $query->where('xs2_event_id', $ticket->xs2_event_id)
                    ->where('external_category_id', (string) ($ticket->category_id ?? ''));
            })
            ->first();
        if ($liveCategoryMapping?->status === 'ignored') {
            return true;
        }

        $ticket->loadMissing('xs2Event.venue.stadiumMapping');
        if ($ticket->xs2Event?->venue?->stadiumMapping?->status === 'ignored') {
            return true;
        }

        return false;
    }

    private function isAvailable(Xs2Ticket $ticket): bool
    {
        return $ticket->ticket_status === 'available' && $ticket->stock > 0;
    }
}
