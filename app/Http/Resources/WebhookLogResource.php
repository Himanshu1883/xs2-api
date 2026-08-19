<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WebhookLog */
class WebhookLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_no' => $this->booking_no,
            'http_status' => $this->http_status,
            'status' => $this->status,
            'payload' => $this->payload,
            'response' => $this->response,
            'error_message' => $this->error_message,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'processing_ms' => $this->processing_ms,
            'sb_order_id' => $this->sb_order_id,
            'sb_order' => $this->whenLoaded('sbOrder', fn () => [
                'id' => $this->sbOrder?->id,
                'booking_no' => $this->sbOrder?->booking_no,
                'booking_status_text' => $this->sbOrder?->booking_status_text,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
