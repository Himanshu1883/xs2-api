<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Xs2EventInventorySyncState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tickets_last_incremental_sync_at' => 'datetime',
            'tickets_last_full_sync_at' => 'datetime',
            'tickets_next_sync_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Xs2Event::class, 'xs2_event_id');
    }
}
