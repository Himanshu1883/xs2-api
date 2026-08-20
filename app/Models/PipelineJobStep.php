<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineJobStep extends Model
{
    public const STAGE_INVENTORY = 'inventory';

    public const STAGE_LISTING_GEN = 'listing_gen';

    public const STAGE_PUBLISH = 'publish';

    public const STAGE_RECONCILE = 'reconcile';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Xs2Event::class, 'xs2_event_id');
    }
}
