<?php

namespace App\Contracts;

/**
 * Marketplace-agnostic publisher for a single remote listing.
 * SplitListingService orchestrates N listings; adapters own create/update/delete.
 */
interface MarketplaceListingPublisher
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{listing_id: string, response: array<string, mixed>}
     */
    public function create(array $payload, string $idempotencyKey): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{listing_id: string, response: array<string, mixed>}
     */
    public function update(string $listingId, array $payload): array;

    /**
     * Soft-delete / disable on marketplaces that lack hard delete.
     *
     * @param  array<string, mixed>  $payload
     * @return array{response: array<string, mixed>}
     */
    public function delete(string $listingId, array $payload = []): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{response: array<string, mixed>}
     */
    public function disable(string $listingId, array $payload = []): array;
}
