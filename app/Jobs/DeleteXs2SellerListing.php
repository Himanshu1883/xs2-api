<?php

namespace App\Jobs;

use App\Models\ExternalListingMapping;
use App\Models\Xs2Ticket;
use App\Services\SellerApi\SellerApiClient;
use App\Services\SplitListings\SplitListingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Deletes (or disables when delete is unavailable) the remote Seatsbrokers listing,
 * then clears the local external listing mapping so a fresh create can run on publish.
 */
class DeleteXs2SellerListing implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $ticketId)
    {
        $this->onQueue(config('services.seller_api.queue'));
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

    public function handle(SellerApiClient $client, SplitListingService $splits): void
    {
        $ticket = Xs2Ticket::findOrFail($this->ticketId);

        if ($ticket->listingSplits()->where('status', 'active')->exists()) {
            $splits->deleteAllListings($ticket->fresh());
            $ticket->refresh();
        }

        $mapping = ExternalListingMapping::query()
            ->where('provider', 'xs2event')
            ->where('xs2_ticket_id', $ticket->id)
            ->first();

        if ($mapping?->seller_listing_id) {
            if ($client->canDeleteListing()) {
                $payload = [
                    'ticket_id' => $mapping->seller_listing_id,
                    'match_id' => $mapping->local_event_id,
                    'seller_id' => $client->sellerId(),
                ];
                $response = $client->deleteListing($mapping->seller_listing_id, $payload);
                $mapping->update([
                    'last_request' => $payload,
                    'last_response' => $response,
                    'last_error' => null,
                ]);
            } else {
                (new DisableSellerListing($this->ticketId))->handle($client);
            }
        }

        $mapping = ExternalListingMapping::query()
            ->where('provider', 'xs2event')
            ->where('xs2_ticket_id', $ticket->id)
            ->first();

        if ($mapping) {
            $mapping->update([
                'seller_listing_id' => null,
                'status' => 'pending',
                'last_payload_hash' => null,
                'last_pushed_quantity' => 0,
                'last_pushed_price' => null,
                'last_request' => null,
                'last_response' => null,
                'last_error' => null,
                'last_pushed_at' => null,
                'disabled_at' => now(),
            ]);
        }

        $ticket->update([
            'sync_status' => 'pending',
            'sync_error' => null,
        ]);
    }
}
