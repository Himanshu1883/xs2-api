<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
