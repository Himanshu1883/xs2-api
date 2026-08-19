<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Xs2StadiumMapping extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'confidence_score' => 'decimal:2',
            'matched_fields' => 'array',
            'candidate_scores' => 'array',
            'manually_confirmed' => 'boolean',
            'mapped_at' => 'datetime',
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Xs2Venue::class, 'xs2_venue_id');
    }

    public function categoryMappings(): HasMany
    {
        return $this->hasMany(Xs2CategoryMapping::class);
    }
}
