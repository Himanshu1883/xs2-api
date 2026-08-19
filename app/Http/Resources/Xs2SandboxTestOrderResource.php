<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Xs2SandboxTestOrder */
class Xs2SandboxTestOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'seatsbroker_order_id' => $this->seatsbroker_order_id,
            'environment' => $this->environment,
            'is_sandbox' => $this->is_sandbox,
            'status' => $this->status,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'quantity' => $this->quantity,
            'xs2_event_id' => $this->xs2_event_id,
            'xs2_event' => $this->xs2_event_payload,
            'xs2_ticket_id' => $this->xs2_ticket_id,
            'xs2_listing' => $this->xs2_ticket_payload,
            'xs2_reservation_id' => $this->xs2_reservation_id,
            'xs2_booking_id' => $this->xs2_booking_id,
            'xs2_bookingorder_id' => $this->xs2_bookingorder_id,
            'xs2_booking_code' => $this->xs2_booking_code,
            'xs2_reservation_request' => $this->xs2_reservation_request,
            'xs2_reservation_response' => $this->xs2_reservation_response,
            'xs2_booking_request' => $this->xs2_booking_request,
            'xs2_booking_response' => $this->xs2_booking_response,
            'xs2_guest_data_request' => $this->xs2_guest_data_request,
            'xs2_guest_data_response' => $this->xs2_guest_data_response,
            'xs2_eticket_request' => $this->xs2_eticket_request,
            'xs2_eticket_response' => $this->xs2_eticket_response,
            'last_error' => $this->last_error,
            'sb_order_created_at' => $this->sb_order_created_at?->toIso8601String(),
            'xs2_order_created_at' => $this->xs2_order_created_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'xs2_order_already_created' => $this->hasXs2Order(),
        ];
    }
}
