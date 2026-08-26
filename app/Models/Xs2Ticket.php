<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Xs2Ticket extends Model
{
    public const FLAG_PAIRS_ONLY = 'pairs_only';

    public const FLAG_NO_MAX_MINUS_1 = 'no_max_minus_1';

    public const FLAG_PACKAGE_RATE = 'package_rate';

    public const FLAG_NO_AWAYTEAM_NATIONALITY = 'no_awayteam_nationality_allowed';

    public const FLAG_NO_AWAYTEAM_PROVINCE = 'no_awayteam_province_allowed';

    public const FLAG_NO_AWAY_FANS = 'no_away_fans';

    /** @var list<string> */
    public const KNOWN_FLAGS = [
        self::FLAG_PAIRS_ONLY,
        self::FLAG_NO_MAX_MINUS_1,
        self::FLAG_PACKAGE_RATE,
        self::FLAG_NO_AWAYTEAM_NATIONALITY,
        self::FLAG_NO_AWAYTEAM_PROVINCE,
        self::FLAG_NO_AWAY_FANS,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'flags' => 'array',
            'is_package_rate' => 'boolean',
            'package_quantity' => 'integer',
            'options' => 'array',
            'sales_periods' => 'array',
            'guest_data_requirements' => 'array',
            'guest_data_synced_at' => 'datetime',
            'is_sandbox' => 'boolean',
            'raw_payload' => 'array',
            'ticket_valid_from' => 'datetime',
            'ticket_valid_until' => 'datetime',
            'external_created_at' => 'datetime',
            'external_updated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'split_enabled' => 'boolean',
            'split_quantity' => 'integer',
            'price_increment_value' => 'decimal:2',
        ];
    }

    public function xs2Event(): BelongsTo
    {
        return $this->belongsTo(Xs2Event::class);
    }

    public function listingMapping(): HasOne
    {
        return $this->hasOne(ExternalListingMapping::class);
    }

    public function mappingState(): HasOne
    {
        return $this->hasOne(Xs2TicketMappingState::class);
    }

    public function listingSplits(): HasMany
    {
        return $this->hasMany(ListingSplit::class, 'master_listing_id');
    }

    public function listingSplitActivities(): HasMany
    {
        return $this->hasMany(ListingSplitActivity::class, 'master_listing_id');
    }

    public function scopeAvailable($q)
    {
        return $q->where('ticket_status', 'available')->where('stock', '>', 0);
    }

    public function scopeUnavailable($q)
    {
        return $q->where(fn ($q) => $q->where('ticket_status', '!=', 'available')->orWhere('stock', 0));
    }

    public function scopeFailed($q)
    {
        return $q->where('sync_status', 'failed');
    }

    public function scopePending($q)
    {
        return $q->where('sync_status', 'pending');
    }

    /**
     * Tickets whose XS2 flags array contains at least one entry.
     */
    public function scopeWithNonEmptyFlags($q)
    {
        return $q->whereNotNull('flags')->whereJsonLength('flags', '>', 0);
    }

    /** @param  list<string>  $flags */
    public function scopeWithAnyFlags($q, array $flags)
    {
        $flags = array_values(array_unique(array_filter($flags)));

        if ($flags === []) {
            return $q;
        }

        return $q->where(function ($query) use ($flags): void {
            foreach ($flags as $index => $flag) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $query->{$method}(function ($ticketQuery) use ($flag): void {
                    if ($flag === self::FLAG_PACKAGE_RATE) {
                        $ticketQuery
                            ->whereJsonContains('flags', self::FLAG_PACKAGE_RATE)
                            ->orWhere('is_package_rate', true);
                    } else {
                        $ticketQuery->whereJsonContains('flags', $flag);
                    }
                });
            }
        });
    }

    /**
     * Tickets with away-team guest restrictions or synced guest data requirements.
     */
    public function scopeWithGuestValidation($q)
    {
        return $q->where(function ($query): void {
            $query
                ->whereJsonContains('flags', 'no_awayteam_nationality_allowed')
                ->orWhereJsonContains('flags', 'no_awayteam_province_allowed')
                ->orWhereNotNull('guest_data_requirements');
        });
    }
}
