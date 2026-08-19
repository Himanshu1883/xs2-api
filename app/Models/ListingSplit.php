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
}
