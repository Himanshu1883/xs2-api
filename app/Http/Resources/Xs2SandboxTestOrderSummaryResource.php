<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Xs2SandboxTestOrder */
class Xs2SandboxTestOrderSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $eventPayload = is_array($this->xs2_event_payload) ? $this->xs2_event_payload : [];

        return [
            'id' => $this->id,
            'seatsbroker_order_id' => $this->seatsbroker_order_id,
            'environment' => $this->environment,
            'is_sandbox' => $this->is_sandbox,
            'status' => $this->status,
            'xs2_event_id' => $this->xs2_event_id,
            'event_name' => $eventPayload['event_name'] ?? null,
            'xs2_ticket_id' => $this->xs2_ticket_id,
            'xs2_booking_id' => $this->xs2_booking_id,
            'xs2_bookingorder_id' => $this->xs2_bookingorder_id,
            'xs2_booking_code' => $this->xs2_booking_code,
            'quantity' => $this->quantity,
            'xs2_reservation_id' => $this->xs2_reservation_id,
            'xs2_order_already_created' => $this->hasXs2Order(),
            'last_error' => $this->last_error,
            'created_at' => $this->created_at?->toIso8601String(),
            'xs2_order_created_at' => $this->xs2_order_created_at?->toIso8601String(),
        ];
    }
}
