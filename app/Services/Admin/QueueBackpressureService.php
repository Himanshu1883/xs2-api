<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prevents cron commands from flooding the jobs table when queues are already deep.
 *
 * Global backpressure counts all queues except seller-api (dedicated worker, separate
 * from XS2 rate limits). Publish and SB inventory crons use seller-api scoped checks
 * so xs2-sync backlogs do not block Seats Broker dispatches.
 */
class QueueBackpressureService
{
    public function __construct(
        private readonly QueueProfileService $profiles,
    ) {}

    /** @return array<string, mixed> */
    public function status(?string $queue = null): array
    {
        $profile = $this->profiles->activeProfile();
        $maxPending = max(1, (int) ($profile['max_pending_jobs'] ?? 150));
        $maxDispatch = max(1, (int) ($profile['max_dispatch_per_run'] ?? 30));
        $pending = $queue !== null
            ? $this->pendingJobCount($queue)
            : $this->pendingJobCountExcluding($this->globalExcludedQueues());
        $overloaded = $pending >= $maxPending;
        $loadPercent = min(100, (int) round(($pending / $maxPending) * 100));

        return [
            'scope' => $queue ?? 'global',
            'queue' => $queue,
            'pending_jobs' => $pending,
            'max_pending_jobs' => $maxPending,
            'max_dispatch_per_run' => $maxDispatch,
            'overloaded' => $overloaded,
            'load_percent' => $loadPercent,
            'remaining_dispatch_budget' => $overloaded ? 0 : max(0, min($maxDispatch, $maxPending - $pending)),
            'profile' => $this->profiles->activeProfileId(),
        ];
    }

    /**
     * Dashboard snapshot: global backpressure plus seller-api scope used by publish crons.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $global = $this->status();
        $sellerApiQueue = $this->sellerApiQueue();
        $sellerApi = $this->status($sellerApiQueue);

        return [
            ...$global,
            'global' => $global,
            'seller_api' => $sellerApi,
            'publish_queue' => $sellerApiQueue,
            'publish_overloaded' => (bool) ($sellerApi['overloaded'] ?? false),
            'publish_blocked' => (bool) ($sellerApi['overloaded'] ?? false),
        ];
    }

    public function isOverloaded(?string $queue = null): bool
    {
        return (bool) ($this->status($queue)['overloaded'] ?? false);
    }

    public function remainingDispatchBudget(?string $queue = null): int
    {
        return max(0, (int) ($this->status($queue)['remaining_dispatch_budget'] ?? 0));
    }

    public function shouldSkipScheduledDispatch(?string $queue = null): bool
    {
        return $this->isOverloaded($queue);
    }

    public function sellerApiQueue(): string
    {
        return (string) config('services.seller_api.queue', 'seller-api');
    }

    /** @return list<string> */
    private function globalExcludedQueues(): array
    {
        $configured = config('xs2.queue_backpressure.exclude_from_global', []);

        return array_values(array_unique(array_filter([
            $this->sellerApiQueue(),
            ...(is_array($configured) ? $configured : []),
        ])));
    }

    private function pendingJobCount(?string $queue = null): int
    {
        if (! Schema::hasTable((string) config('queue.connections.database.table', 'jobs'))) {
            return 0;
        }

        $now = now()->getTimestamp();

        $query = DB::table((string) config('queue.connections.database.table', 'jobs'))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now);

        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        return (int) $query->count();
    }

    /** @param  list<string>  $excludeQueues */
    private function pendingJobCountExcluding(array $excludeQueues): int
    {
        if (! Schema::hasTable((string) config('queue.connections.database.table', 'jobs'))) {
            return 0;
        }

        if ($excludeQueues === []) {
            return $this->pendingJobCount();
        }

        $now = now()->getTimestamp();

        return (int) DB::table((string) config('queue.connections.database.table', 'jobs'))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now)
            ->whereNotIn('queue', $excludeQueues)
            ->count();
    }
}
