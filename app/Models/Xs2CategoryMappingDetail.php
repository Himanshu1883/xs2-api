<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Xs2CategoryMappingDetail extends Model
{
    protected $guarded = [];

    public function categoryMapping(): BelongsTo
    {
        return $this->belongsTo(Xs2CategoryMapping::class, 'xs2_category_mapping_id');
    }
}
