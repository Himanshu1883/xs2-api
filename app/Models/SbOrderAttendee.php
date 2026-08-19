<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SbOrderAttendee extends Model
{
    protected $table = 'sb_order_attendees';

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
        return $this->belongsTo(SbOrder::class, 'sb_order_id');
    }
}
