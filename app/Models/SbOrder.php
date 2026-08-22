<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SbOrder extends Model
{
    public const STATUS_CONFIRMED = 1;

    public const STATUS_PENDING = 2;

    public const STATUS_CANCELLED = 3;

    public const STATUS_COMPLETED = 4;

    protected $table = 'sb_orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'booking_status' => 'integer',
            'ticket_amount' => 'decimal:2',
            'match_date' => 'date',
            'match_id' => 'integer',
            'ticket_id' => 'integer',
            'quantity' => 'integer',
            'split' => 'integer',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
            'attendee_fetched_at' => 'datetime',
        ];
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(SbOrderAttendee::class)->orderBy('position');
    }

    public function xs2Order(): HasOne
    {
        return $this->hasOne(Xs2Order::class, 'sb_order_id');
    }

    /**
     * Bookings that consume inventory (everything except cancelled).
     * Status meanings from Seller API: 1 Confirmed, 2 Pending, 3 Cancelled, 4 Completed.
     */
    public function scopeActiveSold($query)
    {
        return $query
            ->where(function ($q): void {
                $q->whereNull('booking_status')
                    ->orWhere('booking_status', '!=', self::STATUS_CANCELLED);
            })
            ->where(function ($q): void {
                $q->whereNull('booking_status_text')
                    ->orWhereRaw('LOWER(booking_status_text) not like ?', ['%cancelled%']);
            });
    }
}
