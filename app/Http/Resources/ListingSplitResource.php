<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ListingSplitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'split_order' => $this->split_order,
            'quantity' => (int) $this->quantity,
            'price' => $this->price !== null ? (float) $this->price : null,
            'seatsbroker_listing_id' => $this->seatsbroker_listing_id,
            'seller_reference' => $this->seller_reference,
            'status' => $this->status,
            'sync_status' => $this->sync_status,
            'last_synced_at' => $this->last_synced_at,
            'last_error' => $this->last_error,
        ];
    }
}
