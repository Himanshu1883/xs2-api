<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MatchInfo extends Model
{
    protected $guarded = [];

    protected $table = 'match_info';

    protected $primaryKey = 'm_id';

    public $timestamps = false;

    /**
     * The local event ID is the only identifier accepted by /api/events/{event}.
     */
    public function getRouteKeyName(): string
    {
        return 'm_id';
    }

    protected function casts(): array
    {
        return [
            'match_date' => 'datetime',
        ];
    }

    public function eventMappings(): HasMany
    {
        return $this->hasMany(EventMapping::class, 'm_id', 'm_id');
    }

    /**
     * Mappings which can enrich a local canonical event for public clients.
     * Ignored and pending mappings never represent a public event link.
     */
    public function publicXs2Mappings(): HasMany
    {
        return $this->hasMany(EventMapping::class, 'm_id', 'm_id')
            ->whereIn('status', ['mapped', 'created'])
            ->latest('updated_at');
    }
}
