<?php

namespace App\Jobs;

use App\Models\ExternalListingMapping;
use App\Models\Xs2Ticket;
use App\Services\SellerApi\SellerApiClient;
use App\Services\SplitListings\SplitListingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class DisableSellerListing implements ShouldQueue
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

    public function handle(SellerApiClient $client): void
    {
        $ticket = Xs2Ticket::findOrFail($this->ticketId);

        if ($ticket->split_enabled && $ticket->listingSplits()->where('status', 'active')->exists()) {
            $splits = app(SplitListingService::class);
            if ((int) $ticket->stock <= 0) {
                $splits->deleteAllListings($ticket);
            } else {
                $splits->disableAllSplitListings($ticket);
            }

            return;
        }

        $mapping = ExternalListingMapping::where('provider', 'xs2event')->where('xs2_ticket_id', $ticket->id)->first();
        if (! $mapping || ! $mapping->seller_listing_id || ($mapping->status === 'inactive' && $mapping->last_pushed_quantity === 0)) {
            return;
        }

        $payload = [
            'ticket_id' => $mapping->seller_listing_id,
            'match_id' => $mapping->local_event_id,
            'seller_id' => $client->sellerId(),
            'status' => '0',
        ];
        try {
            $response = $client->disableListing($mapping->seller_listing_id, $payload);
            $mapping->update(['status' => 'inactive', 'last_pushed_quantity' => 0, 'last_request' => $payload, 'last_response' => $response, 'last_error' => null, 'disabled_at' => now()]);
            Log::channel(config('services.seller_api.log_channel', 'stack'))->info('Seller listing disabled.', [
                'ticket_id' => $ticket->id,
                'seller_listing_id' => $mapping->seller_listing_id,
                'request' => $payload,
                'response' => $response,
            ]);
        } catch (\Throwable $e) {
            $mapping->update(['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 5000)]);
            Log::channel(config('services.seller_api.log_channel', 'stack'))->error('Seller listing disable failed.', [
                'ticket_id' => $ticket->id,
                'seller_listing_id' => $mapping->seller_listing_id,
                'request' => $payload,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
