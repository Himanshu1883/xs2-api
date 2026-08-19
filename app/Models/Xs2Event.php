<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Xs2Event extends Model
{
    /** @var list<string> */
    public const UNAVAILABLE_STATUSES = [
        'cancelled',
        'canceled',
        'closed',
        'deleted',
        'hidden',
        'inactive',
        'nosale',
        'postponed',
        'soldout',
    ];

    /** @var list<string> */
    public const PUBLIC_HIDDEN_STATUSES = [
        'cancelled',
        'canceled',
        'deleted',
        'hidden',
        'inactive',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date_start_local' => 'datetime',
            'date_stop_local' => 'datetime',
            'date_start_main_event' => 'datetime',
            'date_stop_main_event' => 'datetime',
            'date_confirmed' => 'boolean',
            'is_popular' => 'boolean',
            'raw_payload' => 'array',
            'external_created_at' => 'datetime',
            'external_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'missing_since' => 'datetime',
            'tickets_last_incremental_sync_at' => 'datetime',
            'tickets_last_full_sync_at' => 'datetime',
            'tickets_next_sync_at' => 'datetime',
        ];
    }

    public function mapping(): HasOne
    {
        return $this->hasOne(EventMapping::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Xs2Venue::class, 'venue_id', 'external_venue_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Xs2Ticket::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Xs2Category::class);
    }

    public function inventorySyncState(): HasOne
    {
        return $this->hasOne(Xs2EventInventorySyncState::class);
    }

    /**
     * Missing supplier metadata remains an unknown rather than a reason to
     * retire a legacy listing. Explicitly unavailable, missing, and started
     * events must never remain sellable.
     */
    public function isSellable(): bool
    {
        $status = Str::lower(trim((string) $this->event_status));

        return ! $this->missing_since
            && ! in_array($status, self::UNAVAILABLE_STATUSES, true)
            && (! $this->date_start_local || $this->date_start_local->isFuture());
    }

    public function scopeUnsellable(Builder $query): Builder
    {
        $placeholders = implode(', ', array_fill(0, count(self::UNAVAILABLE_STATUSES), '?'));

        return $query->where(fn (Builder $query) => $query
            ->whereNotNull('missing_since')
            ->orWhere('date_start_local', '<=', now())
            ->orWhereRaw("LOWER(TRIM(event_status)) IN ({$placeholders})", self::UNAVAILABLE_STATUSES));
    }
}
