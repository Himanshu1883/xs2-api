<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SbOrder */
class SbOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_no' => $this->booking_no,
            'booking_status' => $this->booking_status,
            'booking_status_text' => $this->booking_status_text,
            'ticket_amount' => $this->ticket_amount !== null ? (float) $this->ticket_amount : null,
            'currency_type' => $this->currency_type,
            'match_name' => $this->match_name,
            'tournament_name' => $this->tournament_name,
            'stadium_name' => $this->stadium_name,
            'match_date' => $this->match_date?->format('Y-m-d'),
            'match_time' => $this->match_time,
            'match_id' => $this->match_id,
            'ticket_id' => $this->ticket_id,
            'listing_id' => $this->listing_id,
            'xs2_listing_id' => $this->xs2_listing_resolution['xs2_listing_id'] ?? null,
            'xs2_external_ticket_id' => $this->xs2_listing_resolution['external_ticket_id'] ?? null,
            'ticketid' => $this->ticketid,
            'quantity' => $this->quantity,
            'split' => $this->split,
            'seat_category' => $this->seat_category,
            'ticket_block' => $this->ticket_block,
            'row' => $this->row,
            'section' => $this->section,
            'listing_note' => $this->listing_note,
            'ticket_types_name' => $this->ticket_types_name,
            'buyer_first_name' => $this->buyer_first_name,
            'buyer_last_name' => $this->buyer_last_name,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'attendee_fetched_at' => $this->attendee_fetched_at?->toIso8601String(),
            'attendee_fetch_error' => $this->attendee_fetch_error,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'attendees_count' => $this->whenCounted('attendees'),
            'attendees' => SbOrderAttendeeResource::collection($this->whenLoaded('attendees')),
            'xs2_order' => $this->whenLoaded('xs2Order', fn () => $this->xs2Order === null ? null : [
                'id' => $this->xs2Order->id,
                'external_order_id' => $this->xs2Order->external_order_id,
                'is_sandbox' => (bool) $this->xs2Order->is_sandbox,
                'xs2_booking_id' => $this->xs2Order->xs2_booking_id,
            ]),
        ];
    }
}
