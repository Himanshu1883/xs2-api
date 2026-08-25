<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SbOrderXs2SyncLog extends Model
{
    public const STATUS_NOT_QUEUED = 'not_queued';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'sb_order_xs2_sync_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reservation_request' => 'array',
            'reservation_response' => 'array',
            'reservation_response_status' => 'integer',
            'reservation_response_headers' => 'array',
            'booking_request' => 'array',
            'booking_response' => 'array',
            'booking_response_status' => 'integer',
            'booking_response_headers' => 'array',
        ];
    }

    public function sbOrder(): BelongsTo
    {
        return $this->belongsTo(SbOrder::class, 'sb_order_id');
    }

    public function xs2Order(): BelongsTo
    {
        return $this->belongsTo(Xs2Order::class, 'xs2_order_id');
    }
}
