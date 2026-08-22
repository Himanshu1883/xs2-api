<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Xs2OrderGuestDataLog extends Model
{
    protected $table = 'xs2_order_guest_data_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_body' => 'array',
            'response_status' => 'integer',
            'pushed_at' => 'datetime',
        ];
    }

    public function xs2Order(): BelongsTo
    {
        return $this->belongsTo(Xs2Order::class, 'xs2_order_id');
    }
}
