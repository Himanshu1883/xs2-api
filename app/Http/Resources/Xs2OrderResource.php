<?php

namespace App\Http\Resources;

use App\Support\Xs2BookingOrderIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Xs2Order */
class Xs2OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_order_id' => $this->external_order_id,
            'is_sandbox' => (bool) $this->is_sandbox,
            'sb_order_id' => $this->sb_order_id,
            'sb_booking_no' => $this->whenLoaded('sbOrder', fn () => $this->sbOrder?->booking_no),
            'xs2_reservation_id' => $this->xs2_reservation_id,
            'xs2_booking_id' => $this->xs2_booking_id,
            'xs2_bookingorder_id' => $this->xs2_bookingorder_id,
            'bookingorder_id_pending' => Xs2BookingOrderIdentity::orderHasPendingBookingOrderId($this->resource),
            'sandbox_sync_error' => $this->sandbox_sync_error,
            'order_status' => $this->order_status,
            'order_status_text' => $this->order_status_text,
            'ticket_amount' => $this->ticket_amount !== null ? (float) $this->ticket_amount : null,
            'currency_type' => $this->currency_type,
            'event_name' => $this->event_name,
            'venue_name' => $this->venue_name,
            'event_date' => $this->event_date?->format('Y-m-d'),
            'event_time' => $this->event_time,
            'external_event_id' => $this->external_event_id,
            'external_ticket_id' => $this->external_ticket_id,
            'quantity' => $this->quantity,
            'seat_category' => $this->seat_category,
            'ticket_block' => $this->ticket_block,
            'row' => $this->row,
            'section' => $this->section,
            'buyer_first_name' => $this->buyer_first_name,
            'buyer_last_name' => $this->buyer_last_name,
            'buyer_email' => $this->buyer_email,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'guest_data_synced_at' => $this->guest_data_synced_at?->toIso8601String(),
            'guest_data_sync_error' => $this->guest_data_sync_error,
            'attendees_copied_from_sb_at' => $this->attendees_copied_from_sb_at?->toIso8601String(),
            'eticket_fetched_at' => $this->eticket_fetched_at?->toIso8601String(),
            'eticket_error' => $this->eticket_error,
            'xs2_eticket_response' => $this->xs2_eticket_response,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'attendees_count' => $this->whenCounted('attendees'),
            'attendees' => Xs2OrderAttendeeResource::collection($this->whenLoaded('attendees')),
            'latest_guest_data_log' => $this->whenLoaded(
                'latestGuestDataLog',
                fn () => $this->latestGuestDataLog === null
                    ? null
                    : new Xs2OrderGuestDataLogResource($this->latestGuestDataLog),
            ),
            'guest_data_logs' => Xs2OrderGuestDataLogResource::collection($this->whenLoaded('guestDataLogs')),
            'sb_order' => $this->whenLoaded('sbOrder', fn () => $this->sbOrder === null ? null : [
                'id' => $this->sbOrder->id,
                'booking_no' => $this->sbOrder->booking_no,
            ]),
        ];
    }
}
