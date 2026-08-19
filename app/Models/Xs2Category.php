<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Xs2Category extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['party_size_together' => 'boolean', 'raw_payload' => 'array', 'last_synced_at' => 'datetime'];
    }

    public function xs2Event(): BelongsTo
    {
        return $this->belongsTo(Xs2Event::class);
    }

    public function context(): HasOne
    {
        return $this->hasOne(Xs2CategoryContext::class);
    }

    public function mapping(): HasOne
    {
        return $this->hasOne(Xs2CategoryMapping::class);
    }
}
