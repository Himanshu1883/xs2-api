<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingSplitActivity extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function masterListing(): BelongsTo
    {
        return $this->belongsTo(Xs2Ticket::class, 'master_listing_id');
    }

    public function listingSplit(): BelongsTo
    {
        return $this->belongsTo(ListingSplit::class);
    }
}
