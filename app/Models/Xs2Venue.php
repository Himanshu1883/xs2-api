<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Xs2Venue extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'raw_payload' => 'array',
            'external_created_at' => 'datetime',
            'external_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function stadiumMapping(): HasOne
    {
        return $this->hasOne(Xs2StadiumMapping::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Xs2Event::class, 'venue_id', 'external_venue_id');
    }
}
