<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookLog extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNAUTHORIZED = 'unauthorized';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response' => 'array',
            'http_status' => 'integer',
            'processing_ms' => 'integer',
        ];
    }

    public function sbOrder(): BelongsTo
    {
        return $this->belongsTo(SbOrder::class);
    }
}
