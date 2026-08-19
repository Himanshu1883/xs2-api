<?php

namespace App\Services\SplitListings;

use App\Contracts\MarketplaceListingPublisher;
use App\Services\SellerApi\SellerApiClient;

/**
 * SeatsBroker Seller API adapter for split listing create/update/delete/disable.
 */
class SeatsBrokerListingPublisher implements MarketplaceListingPublisher
{
    public function __construct(private readonly SellerApiClient $client) {}

    public function create(array $payload, string $idempotencyKey): array
    {
        $response = $this->client->createListing($payload, $idempotencyKey);

        return [
            'listing_id' => $this->client->listingId($response),
            'response' => $response,
        ];
    }

    public function update(string $listingId, array $payload): array
    {
        $response = $this->client->updateListing($listingId, $payload);

        return [
            'listing_id' => $listingId,
            'response' => $response,
        ];
    }

    public function delete(string $listingId, array $payload = []): array
    {
        if ($this->client->canDeleteListing()) {
            $response = $this->client->deleteListing($listingId, [
                'ticket_id' => $listingId,
                'match_id' => $payload['match_id'] ?? null,
                'seller_id' => $payload['seller_id'] ?? $this->client->sellerId(),
            ]);

            return ['response' => $response];
        }

        return $this->disable($listingId, $payload);
    }

    public function disable(string $listingId, array $payload = []): array
    {
        $response = $this->client->disableListing($listingId, [
            'ticket_id' => $listingId,
            'status' => '0',
            ...$payload,
        ]);

        return ['response' => $response];
    }
}
