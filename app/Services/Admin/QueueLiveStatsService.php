<?php

namespace App\Services\Admin;

use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Models\ListingSplit;
use App\Models\Xs2EventInventorySyncState;
use App\Models\Xs2Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class QueueLiveStatsService
{
    private const ACTIVITY_WINDOW_MINUTES = 5;

    public function __construct(
        private readonly QueueManagementService $queues,
        private readonly CronExecutionLogService $executionLogs,
        private readonly CronConfigService $cronConfig,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $since = now()->subMinutes(self::ACTIVITY_WINDOW_MINUTES);
        $queueSnapshot = $this->queues->snapshot();
        $inventory = $this->inventorySyncStats();
        $cronSnapshot = $this->cronConfig->snapshot();
        $taskNames = $this->taskNamesFromSnapshot($cronSnapshot);

        return [
            'generated_at' => now()->toIso8601String(),
            'activity_window_minutes' => self::ACTIVITY_WINDOW_MINUTES,
            'is_active' => $this->isActive($cronSnapshot, $inventory, $queueSnapshot),
            'tickets' => $this->ticketStats($since),
            'inventory_sync' => $inventory,
            'listings' => $this->listingStats($since),
            'queue' => [
                'available' => (bool) ($queueSnapshot['available'] ?? false),
                'pending' => (int) ($queueSnapshot['totals']['pending'] ?? 0),
                'running' => (int) ($queueSnapshot['totals']['running'] ?? 0),
                'delayed' => (int) ($queueSnapshot['totals']['delayed'] ?? 0),
                'total' => (int) ($queueSnapshot['totals']['total'] ?? 0),
                'failed' => (int) ($queueSnapshot['totals']['failed'] ?? 0),
            ],
            'recent_execution_logs' => $this->recentExecutionLogs(10, $taskNames),
        ];
    }

    /**
     * @param  array{tasks: list<array<string, mixed>>}  $cronSnapshot
     * @param  array{running: int}  $inventory
     * @param  array{totals: array{running?: int}}  $queueSnapshot
     */
    private function isActive(array $cronSnapshot, array $inventory, array $queueSnapshot): bool
    {
        if (($inventory['running'] ?? 0) > 0) {
            return true;
        }

        if ((int) ($queueSnapshot['totals']['running'] ?? 0) > 0) {
            return true;
        }

        foreach ($cronSnapshot['tasks'] as $task) {
            if (($task['status'] ?? '') === 'running' || ($task['is_running'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /** @return array{total: int, updated_recent: int} */
    private function ticketStats(Carbon $since): array
    {
        if (! Schema::hasTable('xs2_tickets')) {
            return [
                'total' => 0,
                'updated_recent' => 0,
            ];
        }

        return [
            'total' => (int) Xs2Ticket::query()->count(),
            'updated_recent' => (int) Xs2Ticket::query()
                ->where(function ($query) use ($since): void {
                    $query->where('updated_at', '>=', $since)
                        ->orWhere('last_synced_at', '>=', $since);
                })
                ->count(),
        ];
    }

    /**
     * @return array{
     *     completed: int,
     *     running: int,
     *     failed: int,
     *     pending: int,
     *     total: int
     * }
     */
    private function inventorySyncStats(): array
    {
        $total = (int) EventMapping::query()->whereHas('xs2Event')->count();

        if (! Schema::hasTable('xs2_event_inventory_sync_states')) {
            return [
                'completed' => 0,
                'running' => 0,
                'failed' => 0,
                'pending' => $total,
                'total' => $total,
            ];
        }

        $completed = (int) Xs2EventInventorySyncState::query()
            ->whereIn('tickets_sync_status', ['completed', 'success'])
            ->count();
        $running = (int) Xs2EventInventorySyncState::query()
            ->where('tickets_sync_status', 'running')
            ->count();
        $failed = (int) Xs2EventInventorySyncState::query()
            ->where('tickets_sync_status', 'failed')
            ->count();
        $pending = max(0, $total - $completed - $running - $failed);

        return [
            'completed' => $completed,
            'running' => $running,
            'failed' => $failed,
            'pending' => $pending,
            'total' => $total,
        ];
    }

    /** @return array{updated_recent: int} */
    private function listingStats(Carbon $since): array
    {
        $updatedRecent = 0;

        if (Schema::hasTable('external_listing_mappings')) {
            $updatedRecent += (int) ExternalListingMapping::query()
                ->where(function ($query) use ($since): void {
                    $query->where('updated_at', '>=', $since)
                        ->orWhere('last_pushed_at', '>=', $since);
                })
                ->count();
        }

        if (Schema::hasTable('listing_splits')) {
            $updatedRecent += (int) ListingSplit::query()
                ->where(function ($query) use ($since): void {
                    $query->where('updated_at', '>=', $since)
                        ->orWhere('last_synced_at', '>=', $since);
                })
                ->count();
        }

        return [
            'updated_recent' => $updatedRecent,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentExecutionLogs(int $limit, array $names): array
    {
        $logs = $this->executionLogs->recentGlobal($limit);
        if ($logs === []) {
            return [];
        }

        return array_map(function (array $log) use ($names): array {
            $jobId = (string) ($log['cron_job_id'] ?? '');

            return [
                ...$log,
                'cron_job_name' => $names[$jobId] ?? $jobId,
            ];
        }, $logs);
    }

    /**
     * @param  array{tasks: list<array<string, mixed>>}  $cronSnapshot
     * @return array<string, string>
     */
    private function taskNamesFromSnapshot(array $cronSnapshot): array
    {
        $names = [];
        foreach ($cronSnapshot['tasks'] as $task) {
            $id = (string) ($task['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $names[$id] = (string) ($task['name'] ?? $id);
        }

        return $names;
    }
}
