<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Log;

class QueueAuditLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $action, ?int $actorId = null, array $context = []): void
    {
        Log::info('queue_management.'.$action, array_merge([
            'actor_id' => $actorId,
            'action' => $action,
            'at' => now()->toIso8601String(),
        ], $context));
    }
}
