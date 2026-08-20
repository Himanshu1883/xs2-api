<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueManagementService
{
    public function __construct(
        private readonly QueueProfileService $profiles,
        private readonly QueueBackpressureService $backpressure,
        private readonly QueueWorkerDetectionService $workerDetection,
        private readonly QueueFailedJobsService $failedJobs,
    ) {}

    private function jobsTable(): string
    {
        return (string) config('queue.connections.database.table', 'jobs');
    }

    private function jobsAvailable(): bool
    {
        return Schema::hasTable($this->jobsTable());
    }

    /** @return list<array{name: string, env: string, value: string, workers: int, worker_hint: string}> */
    public function configuredWorkers(): array
    {
        $options = $this->workerOptionsSuffix();
        $workers = [
            [
                'name' => 'XS2 mapping queue',
                'env' => 'XS2_MAPPING_QUEUE',
                'value' => (string) config('xs2.mapping_queue', 'xs2-mapping'),
                'workers' => max(0, (int) config('xs2.queue_workers.xs2_mapping', 1)),
                'worker_hint' => 'php artisan queue:work --queue='.(string) config('xs2.mapping_queue', 'xs2-mapping').$options,
            ],
            [
                'name' => 'XS2 listing generation queue',
                'env' => 'XS2_LISTING_GEN_QUEUE',
                'value' => (string) config('pipeline.listing_gen_queue', 'xs2-listing-gen'),
                'workers' => max(0, (int) config('pipeline.queue_workers.xs2_listing_gen', 1)),
                'worker_hint' => 'php artisan queue:work --queue='.(string) config('pipeline.listing_gen_queue', 'xs2-listing-gen').$options,
            ],
            [
                'name' => 'XS2 reconcile queue',
                'env' => 'XS2_RECONCILE_QUEUE',
                'value' => (string) config('pipeline.reconcile_queue', 'xs2-reconcile'),
                'workers' => max(0, (int) config('pipeline.queue_workers.xs2_reconcile', 1)),
                'worker_hint' => 'php artisan queue:work --queue='.(string) config('pipeline.reconcile_queue', 'xs2-reconcile').$options,
            ],
            [
                'name' => 'XS2 sync queue',
                'env' => 'XS2_QUEUE',
                'value' => (string) config('xs2.queue', 'xs2-sync'),
                'workers' => max(0, (int) config('xs2.queue_workers.xs2_sync', 2)),
                'worker_hint' => 'php artisan queue:work --queue='.(string) config('xs2.queue', 'xs2-sync').$options,
            ],
            [
                'name' => 'XS2 guest-data queue',
                'env' => 'XS2_GUEST_QUEUE',
                'value' => (string) config('xs2.guest_queue', 'xs2-guest'),
                'workers' => max(0, (int) config('xs2.queue_workers.xs2_guest', 1)),
                'worker_hint' => 'php artisan queue:work --queue='.(string) config('xs2.guest_queue', 'xs2-guest').$options,
            ],
            [
                'name' => 'Seller API queue',
                'env' => 'SELLER_API_QUEUE',
                'value' => (string) config('services.seller_api.queue', 'seller-api'),
                'workers' => max(0, (int) config('xs2.queue_workers.seller_api', 1)),
                'worker_hint' => 'php artisan queue:work --queue='.(string) config('services.seller_api.queue', 'seller-api').$options,
            ],
            [
                'name' => 'Default queue',
                'env' => 'QUEUE_DEFAULT',
                'value' => 'default',
                'workers' => max(0, (int) config('xs2.queue_workers.default', 1)),
                'worker_hint' => 'php artisan queue:work --queue=default'.$options,
            ],
        ];

        return $workers;
    }

    /** @return list<string> */
    public function recommendedWorkerCommands(): array
    {
        $commands = [];
        foreach ($this->configuredWorkers() as $worker) {
            $count = max(0, (int) ($worker['workers'] ?? 0));
            if ($count === 0) {
                continue;
            }
            for ($index = 0; $index < $count; $index++) {
                $commands[] = (string) $worker['worker_hint'];
            }
        }

        return $commands;
    }

    /** @return list<string> */
    public function configuredQueueNames(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $worker): string => $worker['value'],
            $this->configuredWorkers(),
        )));
    }

    /**
     * @return array{
     *     available: bool,
     *     connection: string,
     *     totals: array{pending: int, running: int, delayed: int, total: int, failed: int},
     *     queues: list<array{
     *         name: string,
     *         env: string,
     *         value: string,
     *         worker_hint: string,
     *         pending: int,
     *         running: int,
     *         delayed: int,
     *         total: int
     *     }>,
     *     other_queues: list<array{queue: string, pending: int, running: int, delayed: int, total: int}>
     * }
     */
    public function snapshot(): array
    {
        $workers = $this->configuredWorkers();
        $byQueue = $this->countsByQueue();
        $failedTotal = $this->failedJobsCount();

        $queues = [];
        $totals = [
            'pending' => 0,
            'running' => 0,
            'delayed' => 0,
            'total' => 0,
            'failed' => $failedTotal,
        ];

        foreach ($workers as $worker) {
            $counts = $byQueue[$worker['value']] ?? $this->emptyCounts();
            $queues[] = [
                ...$worker,
                ...$counts,
            ];

            foreach (['pending', 'running', 'delayed', 'total'] as $key) {
                $totals[$key] += $counts[$key];
            }
        }

        $known = $this->configuredQueueNames();
        $otherQueues = [];
        foreach ($byQueue as $queueName => $counts) {
            if (in_array($queueName, $known, true)) {
                continue;
            }

            $otherQueues[] = [
                'queue' => $queueName,
                ...$counts,
            ];

            foreach (['pending', 'running', 'delayed', 'total'] as $key) {
                $totals[$key] += $counts[$key];
            }
        }

        usort($otherQueues, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'available' => $this->jobsAvailable(),
            'jobs_table' => $this->jobsTable(),
            'connection' => (string) config('queue.default', 'database'),
            'rate_limit_per_minute' => max(1, (int) config('services.xs2.rate_limit_per_minute', config('xs2.rate_limit_per_minute', 30))),
            'worker_sleep_seconds' => max(1, (int) config('xs2.queue_worker_options.sleep', 3)),
            'profile' => $this->profiles->snapshot(),
            'backpressure' => $this->backpressure->status(),
            'supervisor_config' => $this->profiles->supervisorConfig(),
            'recommended_worker_commands' => $this->recommendedWorkerCommands(),
            'worker_script' => 'bash scripts/run-queue-workers.sh',
            'promote_delayed_command' => 'php artisan queue:promote-delayed --queue='.(string) config('xs2.queue', 'xs2-sync'),
            'health' => $this->healthMetrics($failedTotal),
            'failed_jobs_summary' => [
                'available' => $this->failedJobs->available(),
                'total' => $failedTotal,
            ],
            'totals' => $totals,
            'queues' => $queues,
            'other_queues' => $otherQueues,
        ];
    }

    /**
     * Remove pending (not reserved) jobs. Optionally include delayed jobs waiting for available_at.
     *
     * @return array{deleted: int, queue: string|null}
     */
    public function clearPending(?string $queue = null): array
    {
        $this->assertJobsTable();

        $query = DB::table($this->jobsTable())->whereNull('reserved_at');
        if ($queue !== null && $queue !== '') {
            $query->where('queue', $queue);
        }

        $deleted = (int) $query->delete();

        return [
            'deleted' => $deleted,
            'queue' => $queue,
        ];
    }

    /**
     * Signal workers to restart after their current job, then delete all queued/reserved jobs.
     *
     * @return array{
     *     jobs_deleted: int,
     *     failed_deleted: int,
     *     workers_restarted: bool,
     *     queue: string|null
     * }
     */
    public function stopAll(?string $queue = null): array
    {
        $this->assertJobsTable();

        try {
            Artisan::call('queue:restart');
            $workersRestarted = true;
        } catch (\Throwable) {
            $workersRestarted = false;
        }

        $jobsQuery = DB::table($this->jobsTable());
        if ($queue !== null && $queue !== '') {
            $jobsQuery->where('queue', $queue);
        }
        $jobsDeleted = (int) $jobsQuery->delete();

        $failedDeleted = 0;
        if (Schema::hasTable('failed_jobs')) {
            $failedQuery = DB::table('failed_jobs');
            if ($queue !== null && $queue !== '') {
                $failedQuery->where('queue', $queue);
            }
            $failedDeleted = (int) $failedQuery->delete();
        }

        return [
            'jobs_deleted' => $jobsDeleted,
            'failed_deleted' => $failedDeleted,
            'workers_restarted' => $workersRestarted,
            'queue' => $queue,
        ];
    }

    /** @return array<string, array{pending: int, running: int, delayed: int, total: int}> */
    private function countsByQueue(): array
    {
        if (! $this->jobsAvailable()) {
            return [];
        }

        $now = now()->getTimestamp();
        $rows = DB::table($this->jobsTable())
            ->select('queue')
            ->selectRaw(
                'SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) as pending',
                [$now],
            )
            ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) as running')
            ->selectRaw(
                'SUM(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 ELSE 0 END) as `delayed`',
                [$now],
            )
            ->selectRaw('COUNT(*) as total')
            ->groupBy('queue')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $queue = (string) ($row->queue ?? '');
            if ($queue === '') {
                continue;
            }

            $result[$queue] = [
                'pending' => (int) ($row->pending ?? 0),
                'running' => (int) ($row->running ?? 0),
                'delayed' => (int) ($row->delayed ?? 0),
                'total' => (int) ($row->total ?? 0),
            ];
        }

        return $result;
    }

    private function failedJobsCount(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return (int) DB::table('failed_jobs')->count();
    }

    /**
     * Make delayed jobs available immediately. Safe when downstream throttling
     * (for example the shared XS2 client rate limiter) paces actual work.
     *
     * @return array{promoted: int, queue: string|null}
     */
    public function promoteDelayed(?string $queue = null): array
    {
        $this->assertJobsTable();

        $now = now()->getTimestamp();
        $query = DB::table($this->jobsTable())
            ->whereNull('reserved_at')
            ->where('available_at', '>', $now);

        if ($queue !== null && $queue !== '') {
            $query->where('queue', $queue);
        }

        $promoted = (int) $query->update(['available_at' => $now]);

        return [
            'promoted' => $promoted,
            'queue' => $queue,
        ];
    }

    /** @return array<string, mixed> */
    private function healthMetrics(int $failedTotal): array
    {
        $workerStatus = $this->workerDetection->detect();
        $oldestPendingSeconds = $this->oldestPendingJobAgeSeconds();

        return [
            'oldest_pending_job_seconds' => $oldestPendingSeconds,
            'failed_jobs_count' => $failedTotal,
            'workers' => $workerStatus,
        ];
    }

    private function oldestPendingJobAgeSeconds(): ?int
    {
        if (! $this->jobsAvailable()) {
            return null;
        }

        $now = now()->getTimestamp();
        $oldestCreatedAt = DB::table($this->jobsTable())
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now)
            ->min('created_at');

        if ($oldestCreatedAt === null) {
            return null;
        }

        return max(0, $now - (int) $oldestCreatedAt);
    }

    private function workerOptionsSuffix(): string
    {
        $tries = max(1, (int) config('xs2.queue_worker_options.tries', 5));
        $timeout = max(60, (int) config('xs2.queue_worker_options.timeout', 300));
        $sleep = max(1, (int) config('xs2.queue_worker_options.sleep', 1));

        return " --tries={$tries} --timeout={$timeout} --sleep={$sleep}";
    }

    /** @return array{pending: int, running: int, delayed: int, total: int} */
    private function emptyCounts(): array
    {
        return [
            'pending' => 0,
            'running' => 0,
            'delayed' => 0,
            'total' => 0,
        ];
    }

    private function assertJobsTable(): void
    {
        if (! $this->jobsAvailable()) {
            throw new \RuntimeException(
                'The '.$this->jobsTable().' table is missing. Run php artisan queue:table && php artisan migrate.',
            );
        }
    }
}
