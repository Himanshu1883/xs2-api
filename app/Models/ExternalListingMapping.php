<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalListingMapping extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_request' => 'array', 'last_response' => 'array', 'last_pushed_at' => 'datetime', 'disabled_at' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Xs2Ticket::class, 'xs2_ticket_id');
    }

    public function eventMapping(): BelongsTo
    {
        return $this->belongsTo(EventMapping::class);
    }
}
