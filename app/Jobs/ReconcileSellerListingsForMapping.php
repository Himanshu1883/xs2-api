<?php

namespace App\Jobs;

use App\Models\EventMapping;
use App\Models\Xs2Ticket;
use App\Services\Xs2\MappedListingPublishService;
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
        MappedListingPublishService $publisher,
    ): void {
        $mapping = EventMapping::with('xs2Event.tickets')->find($this->mappingId);
        if (! $mapping) {
            return;
        }

        $canPublish = in_array($mapping->status, ['mapped', 'created'], true)
            && $mapping->m_id
            && $mapping->xs2Event->isSellable();

        foreach ($mapping->xs2Event->tickets as $ticket) {
            $state = $mappingStates->resolveIfStale($ticket);
            if ($canPublish && $mappingStates->isAutoPublishable($state->mapping_status) && $this->isAvailable($ticket)) {
                $publisher->publishTicket($ticket->id);
            } else {
                DisableSellerListing::dispatch($ticket->id);
            }
        }
    }

    private function isAvailable(Xs2Ticket $ticket): bool
    {
        return $ticket->ticket_status === 'available' && $ticket->stock > 0;
    }
}
