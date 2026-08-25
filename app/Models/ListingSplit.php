<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ListingSplit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price' => 'decimal:2',
            'split_order' => 'integer',
            'last_request' => 'array',
            'last_response' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function masterListing(): BelongsTo
    {
        return $this->belongsTo(Xs2Ticket::class, 'master_listing_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ListingSplitActivity::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** XS2 inventory ticket id for this split (master external_ticket_id + split suffix). */
    public function xs2ListingId(): ?string
    {
        $prefix = (string) config('services.seller_api.external_reference_prefix', 'XS2-');
        if ($this->seller_reference && str_starts_with($this->seller_reference, $prefix)) {
            return substr($this->seller_reference, strlen($prefix));
        }

        if ($this->relationLoaded('masterListing') && $this->masterListing?->external_ticket_id) {
            return $this->masterListing->external_ticket_id.'-S'.$this->split_order;
        }

        return null;
    }
}
