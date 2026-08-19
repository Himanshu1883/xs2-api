<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Single-ticket detail shape. Extends the list shape (Xs2TicketAdminResource)
 * with everything else normalized from the stored XS2 payload plus the raw
 * payload itself, so an admin can inspect exactly what XS2 sent without the
 * list endpoint having to carry that weight for every row.
 */
class Xs2TicketDetailAdminResource extends JsonResource
{
    public function __construct($resource, private readonly ?string $categoryType = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $divisor = max(1, (int) config('services.xs2.minor_unit_divisor'));

        return [
            'id' => $this->id,
            'external_ticket_id' => $this->external_ticket_id,
            'external_category_id' => $this->category_id,
            'category_name' => $this->category_name,
            'category_type' => $this->categoryType,
            'sub_category' => $this->sub_category,
            'ticket_title' => $this->ticket_title,
            'ticket_type' => $this->ticket_type,
            'ticket_status' => $this->ticket_status,
            'stock' => $this->stock,
            'min_order' => $this->min_order,
            'currency' => $this->currency_code,
            'net_rate' => $this->minorToMajor($this->net_rate, $divisor),
            'face_value' => $this->minorToMajor($this->face_value, $divisor),
            'ticket_valid_from' => $this->ticket_valid_from,
            'ticket_valid_until' => $this->ticket_valid_until,
            'flags' => $this->flags ?? [],
            'is_package_rate' => (bool) ($this->is_package_rate ?? false),
            'package_quantity' => $this->package_quantity !== null ? (int) $this->package_quantity : null,
            'package_price' => $this->minorToMajor($this->package_price, $divisor),
            'options' => $this->options ?? [],
            'sales_periods' => $this->sales_periods ?? [],
            'guest_data_requirements' => $this->guest_data_requirements ?? [],
            'guest_data_synced_at' => $this->guest_data_synced_at,
            'mapping_status' => $this->mappingState?->mapping_status,
            'mapping_error' => $this->mappingState?->mapping_error,
            'push_status' => $this->sync_status ?? 'pending',
            'push_error' => $this->sync_status === 'failed'
                ? 'The most recent ticket push failed. Retry the listing or review the application logs.'
                : null,
            'listing_status' => $this->listingMapping?->status,
            'seller_listing_id' => $this->listingMapping?->seller_listing_id,
            'last_pushed_at' => $this->listingMapping?->last_pushed_at,
            'last_synced_at' => $this->last_synced_at,
            'external_created_at' => $this->external_created_at,
            'external_updated_at' => $this->external_updated_at,
            'raw_payload' => $this->raw_payload,
        ];
    }

    private function minorToMajor(?int $amount, int $divisor): ?float
    {
        return $amount === null ? null : round($amount / $divisor, 2);
    }
}
