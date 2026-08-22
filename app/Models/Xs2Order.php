<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Xs2Order extends Model
{
    protected $table = 'xs2_orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_sandbox' => 'boolean',
            'ticket_amount' => 'decimal:2',
            'event_date' => 'date',
            'quantity' => 'integer',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
            'guest_data_synced_at' => 'datetime',
            'attendees_copied_from_sb_at' => 'datetime',
            'xs2_eticket_request' => 'array',
            'xs2_eticket_response' => 'array',
            'eticket_fetched_at' => 'datetime',
        ];
    }

    public function sbOrder(): BelongsTo
    {
        return $this->belongsTo(SbOrder::class, 'sb_order_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(Xs2OrderAttendee::class)->orderBy('position');
    }

    public function guestDataLogs(): HasMany
    {
        return $this->hasMany(Xs2OrderGuestDataLog::class)->orderByDesc('id');
    }

    public function latestGuestDataLog(): HasOne
    {
        return $this->hasOne(Xs2OrderGuestDataLog::class)->latestOfMany();
    }
}
