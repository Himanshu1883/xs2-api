<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SbOrderXs2SyncLog */
class SbOrderXs2SyncLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sb_order_id' => $this->sb_order_id,
            'xs2_order_id' => $this->xs2_order_id,
            'status' => $this->status,
            'skip_reason' => $this->skip_reason,
            'reservation_request' => $this->reservation_request,
            'reservation_response' => $this->reservation_response,
            'reservation_response_status' => $this->reservation_response_status,
            'reservation_response_headers' => $this->reservation_response_headers,
            'booking_request' => $this->booking_request,
            'booking_response' => $this->booking_response,
            'booking_response_status' => $this->booking_response_status,
            'booking_response_headers' => $this->booking_response_headers,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
