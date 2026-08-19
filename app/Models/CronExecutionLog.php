<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CronExecutionLog extends Model
{
    protected $fillable = [
        'cron_job_id',
        'trigger',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'message',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
