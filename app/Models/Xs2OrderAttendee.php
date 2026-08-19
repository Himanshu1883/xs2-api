<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Xs2OrderAttendee extends Model
{
    protected $table = 'xs2_order_attendees';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'raw_payload' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Xs2Order::class, 'xs2_order_id');
    }
}
