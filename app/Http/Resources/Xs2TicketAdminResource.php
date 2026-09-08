<?php

namespace App\Http\Resources;

use App\Services\Currency\CurrencyConversionService;
use App\Services\SplitListings\SplitListingService;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class Xs2TicketAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $divisor = max(1, (int) config('services.xs2.minor_unit_divisor'));
        $mappingStatus = $this->mappingState?->mapping_status;
        $mappingStates = app(Xs2TicketMappingStatusService::class);
        if ($mappingStates->isStale($this->resource)) {
            $mappingStatus = $mappingStates->previewStatus($this->resource);
        }

        return [
            'id' => $this->id,
            'external_ticket_id' => $this->external_ticket_id,
            'external_category_id' => $this->category_id,
            'category_name' => $this->category_name,
            'sub_category' => $this->sub_category,
            'ticket_type' => $this->ticket_type,
            'ticket_status' => $this->ticket_status,
            'stock' => $this->stock,
            'sold_quantity' => (int) ($this->sold_quantity ?? 0),
            'remaining_quantity' => (int) ($this->remaining_quantity
                ?? max(0, (int) ($this->stock ?? 0) - (int) ($this->sold_quantity ?? 0))),
            'min_order' => $this->min_order,
            'flags' => $this->flags ?? [],
            'is_package_rate' => (bool) ($this->is_package_rate ?? false),
            'package_quantity' => $this->package_quantity !== null ? (int) $this->package_quantity : null,
            'package_price' => $this->minorToMajor($this->package_price, $divisor),
            'currency' => $this->currency_code,
            'net_rate' => $this->minorToMajor($this->net_rate, $divisor),
            'seller_listing' => $this->sellerListingPreview($divisor),
            'mapping_status' => $mappingStatus,
            'mapping_error' => $this->mappingState?->mapping_error,
            'can_publish' => $mappingStates->isManualPublishable($mappingStatus)
                && $this->listingMapping?->status !== 'active',
            'publish_uses_category_name_fallback' => $mappingStates->usesCategoryNameFallback($mappingStatus),
            'push_status' => $this->sync_status ?? 'pending',
            'push_error' => $this->sync_status === 'failed'
                ? 'The most recent ticket push failed. Retry the listing or review the application logs.'
                : null,
            'listing_status' => $this->listingMapping?->status,
            'seller_listing_id' => $this->listingMapping?->seller_listing_id,
            'last_pushed_at' => $this->listingMapping?->last_pushed_at,
            'last_synced_at' => $this->last_synced_at,
            'split_enabled' => (bool) ($this->split_enabled ?? false),
            'split_quantity' => $this->split_quantity,
            'price_increment_type' => $this->price_increment_type,
            'price_increment_value' => $this->price_increment_value !== null
                ? (float) $this->price_increment_value
                : null,
            'split_sync_status' => $this->split_sync_status ?? 'idle',
            'split_listings_count' => (int) ($this->split_listings_count
                ?? $this->listingSplits?->where('status', 'active')->count()
                ?? 0),
            'split_listings' => $this->when(
                $this->relationLoaded('listingSplits'),
                fn () => $this->splitListingsPayload($divisor) ?? [],
            ),
            'split_sales' => $this->split_sales ?? [],
        ];
    }

    private function minorToMajor(?int $amount, int $divisor): ?float
    {
        return $amount === null ? null : round($amount / $divisor, 2);
    }

    /** @return array<string, mixed>|null */
    private function sellerListingPreview(int $divisor): ?array
    {
        $mapping = $this->xs2Event?->mapping;
        if ($mapping === null) {
            return null;
        }

        $converter = app(CurrencyConversionService::class);
        $ticketCurrency = (string) ($this->currency_code ?? '');
        $eventCurrency = $converter->eventCurrency($mapping);
        $priceMajor = $this->minorToMajor(
            $this->package_price ?? $this->net_rate ?? $this->face_value,
            $divisor,
        );

        if ($priceMajor === null) {
            return null;
        }

        $summary = $converter->conversionSummary($priceMajor, $ticketCurrency, $eventCurrency);
        if ($summary === null) {
            return [
                'currency' => strtoupper(trim($ticketCurrency)),
                'price' => $priceMajor,
                'converted' => false,
            ];
        }

        return [
            'currency' => $summary['to_currency'],
            'price' => $summary['converted_amount_major'],
            'converted' => true,
            'source' => [
                'currency' => $summary['from_currency'],
                'price' => $summary['original_amount_major'],
                'rate' => $summary['rate'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>>|null */
    private function splitListingsPayload(int $divisor): ?array
    {
        if (! $this->relationLoaded('listingSplits')) {
            return null;
        }

        $splits = $this->listingSplits->sortBy('split_order')->values();
        if ($splits->isEmpty()) {
            return [];
        }

        $splitService = app(SplitListingService::class);

        return $splits->map(function ($split) use ($splitService): array {
            $price = $split->price !== null ? (float) $split->price : null;
            $sellerPreview = $price !== null
                ? $splitService->sellerPricePreview($this->resource, $price)
                : null;

            return [
                'id' => $split->id,
                'split_order' => $split->split_order,
                'quantity' => (int) $split->quantity,
                'price' => $price,
                'seller_price' => $sellerPreview['seller_price'] ?? $price,
                'seller_currency' => $sellerPreview['seller_currency'] ?? null,
                'seatsbroker_listing_id' => $split->seatsbroker_listing_id,
                'xs2_listing_id' => $split->xs2ListingId(),
                'seller_reference' => $split->seller_reference,
                'status' => $split->status,
                'sync_status' => $split->sync_status,
                'last_synced_at' => $split->last_synced_at,
                'last_error' => $split->last_error,
            ];
        })->all();
    }
}
