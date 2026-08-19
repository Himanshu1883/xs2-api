<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prevents cron commands from flooding the jobs table when queues are already deep.
 */
class QueueBackpressureService
{
    public function __construct(
        private readonly QueueProfileService $profiles,
    ) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $profile = $this->profiles->activeProfile();
        $pending = $this->pendingJobCount();
        $maxPending = max(1, (int) ($profile['max_pending_jobs'] ?? 150));
        $maxDispatch = max(1, (int) ($profile['max_dispatch_per_run'] ?? 30));
        $overloaded = $pending >= $maxPending;
        $loadPercent = min(100, (int) round(($pending / $maxPending) * 100));

        return [
            'pending_jobs' => $pending,
            'max_pending_jobs' => $maxPending,
            'max_dispatch_per_run' => $maxDispatch,
            'overloaded' => $overloaded,
            'load_percent' => $loadPercent,
            'remaining_dispatch_budget' => $overloaded ? 0 : max(0, min($maxDispatch, $maxPending - $pending)),
            'profile' => $this->profiles->activeProfileId(),
        ];
    }

    public function isOverloaded(): bool
    {
        return (bool) ($this->status()['overloaded'] ?? false);
    }

    public function remainingDispatchBudget(): int
    {
        return max(0, (int) ($this->status()['remaining_dispatch_budget'] ?? 0));
    }

    public function shouldSkipScheduledDispatch(): bool
    {
        return $this->isOverloaded();
    }

    private function pendingJobCount(): int
    {
        if (! Schema::hasTable((string) config('queue.connections.database.table', 'jobs'))) {
            return 0;
        }

        $now = now()->getTimestamp();

        return (int) DB::table((string) config('queue.connections.database.table', 'jobs'))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now)
            ->count();
    }
}
