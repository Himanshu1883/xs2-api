<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventMapping extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'match_score' => 'decimal:2',
            'match_details' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function xs2Event(): BelongsTo
    {
        return $this->belongsTo(Xs2Event::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(
            MatchInfo::class,
            'm_id',
            'm_id'
        );
    }

    public function localEvent(): BelongsTo
    {
        return $this->event();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
