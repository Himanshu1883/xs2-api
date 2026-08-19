<?php

namespace App\Jobs;

use App\Models\ExternalListingMapping;
use App\Models\Xs2Ticket;
use App\Services\SellerApi\SellerApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class DeleteSellerListing implements ShouldQueue
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
        $mapping = ExternalListingMapping::where('provider', 'xs2event')->where('xs2_ticket_id', $this->ticketId)->first();
        if (! $mapping) {
            return;
        }

        if (! $mapping->seller_listing_id) {
            $mapping->delete();

            return;
        }

        $payload = [
            'ticket_id' => $mapping->seller_listing_id,
            'match_id' => $mapping->local_event_id,
            'seller_id' => $client->sellerId(),
        ];
        try {
            $response = $client->deleteListing($mapping->seller_listing_id, $payload);
            $sellerListingId = $mapping->seller_listing_id;
            $mapping->delete();
            Xs2Ticket::where('id', $this->ticketId)->update(['sync_status' => 'pending', 'sync_error' => null]);
            Log::channel(config('services.seller_api.log_channel', 'stack'))->info('Seller listing deleted.', [
                'ticket_id' => $this->ticketId,
                'seller_listing_id' => $sellerListingId,
                'request' => $payload,
                'response' => $response,
            ]);
        } catch (\Throwable $e) {
            $mapping->update(['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 5000)]);
            Log::channel(config('services.seller_api.log_channel', 'stack'))->error('Seller listing delete failed.', [
                'ticket_id' => $this->ticketId,
                'seller_listing_id' => $mapping->seller_listing_id,
                'request' => $payload,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
