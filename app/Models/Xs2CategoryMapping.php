<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Xs2CategoryMapping extends Model
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Xs2Category::class, 'xs2_category_id');
    }

    public function stadiumMapping(): BelongsTo
    {
        return $this->belongsTo(Xs2StadiumMapping::class, 'xs2_stadium_mapping_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(Xs2CategoryMappingDetail::class, 'xs2_category_mapping_id')->orderBy('id');
    }
}
