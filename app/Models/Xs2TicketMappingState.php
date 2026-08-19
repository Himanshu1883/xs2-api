<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Xs2TicketMappingState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_resolved_at' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Xs2Ticket::class, 'xs2_ticket_id');
    }

    public function eventMapping(): BelongsTo
    {
        return $this->belongsTo(EventMapping::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Xs2Venue::class, 'xs2_venue_id');
    }

    public function stadiumMapping(): BelongsTo
    {
        return $this->belongsTo(Xs2StadiumMapping::class, 'xs2_stadium_mapping_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Xs2Category::class, 'xs2_category_id');
    }

    public function categoryMapping(): BelongsTo
    {
        return $this->belongsTo(Xs2CategoryMapping::class, 'xs2_category_mapping_id');
    }
}
