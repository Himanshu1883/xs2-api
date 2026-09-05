<?php

namespace App\Services\Admin;

use App\Jobs\RunAdminCronJob;
use App\Jobs\SyncXs2EventsJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

class CronJobManagementService
{
    /** @var list<string> */
    private const KNOWN_CRON_JOB_IDS = [
        'xs2-inventory-incremental',
        'xs2-inventory-full',
        'xs2-sb-new-listing-publish',
        'xs2-sb-failed-listing-publish-retry',
        'xs2-sb-listing-inventory',
        'xs2-sb-order-sync',
        'xs2-sb-order-guest-data-sync',
        'xs2-events-sync',
        'sb-events-sync',
        'sanctum-prune-expired',
        'xs2-split-listing-quantities',
    ];

    public function __construct(
        private readonly CronConfigService $cronConfig,
        private readonly CronExecutionLogService $executionLogs,
        private readonly CronIntervalService $intervals,
    ) {}

    /** @return array<string, mixed> */
    public function listJobs(): array
    {
        $snapshot = $this->cronConfig->snapshot();
        $executionLogsAvailable = $this->executionLogs->isAvailable();
        $executionLogCounts = $this->executionLogs->countsForJobs(array_map(
            static fn (array $task): string => (string) ($task['id'] ?? ''),
            $snapshot['tasks'],
        ));

        $tasks = collect($snapshot['tasks'])
            ->map(function (array $task) use ($executionLogsAvailable, $executionLogCounts): array {
                $taskId = (string) $task['id'];
                $task['description'] = $this->descriptionForTask($task);
                $task['supports_run_now'] = $this->supportsRunNow($taskId);
                $task['execution_logs_available'] = $executionLogsAvailable;
                $task['execution_log_count'] = (int) ($executionLogCounts[$taskId] ?? 0);

                return $task;
            })
            ->values()
            ->all();

        $health = $snapshot['scheduler']['schedule_health'] ?? [];
        $queueStats = $snapshot['scheduler']['queue_stats']['totals'] ?? [];

        return [
            'summary' => [
                'total_jobs' => count($tasks),
                'running' => (int) ($health['task_counts']['running'] ?? 0),
                'failed' => (int) ($health['task_counts']['failed'] ?? 0),
                'idle' => (int) ($health['task_counts']['idle'] ?? 0),
                'disabled' => (int) ($health['task_counts']['disabled'] ?? 0),
                'schedule_health' => (string) ($health['status'] ?? 'healthy'),
                'queue_pending' => (int) ($queueStats['pending'] ?? 0),
                'queue_running' => (int) ($queueStats['running'] ?? 0),
                'execution_logs_available' => $executionLogsAvailable,
            ],
            'scheduler' => $snapshot['scheduler'],
            'jobs' => $tasks,
        ];
    }

    /** @return array<string, mixed> */
    public function updateInterval(string $cronJobId, int $minutes): array
    {
        $this->assertKnownJob($cronJobId);

        $updated = $this->intervals->updateMinutes($cronJobId, $minutes);
        $task = $this->presentTask($this->findTask($cronJobId));

        return [
            'cron_job_id' => $cronJobId,
            'interval_minutes' => $updated['interval_minutes'],
            'interval_min_minutes' => $updated['interval_min_minutes'],
            'interval_max_minutes' => $updated['interval_max_minutes'],
            'interval_presets' => $updated['interval_presets'],
            'interval_is_overridden' => $updated['interval_is_overridden'],
            'requires_schedule_work_restart' => true,
            'job' => $task,
            'message' => $this->intervalSavedMessage($cronJobId, $updated['interval_minutes']),
        ];
    }

    /** @return array<string, mixed> */
    public function logsForJob(string $cronJobId, int $limit = 10): array
    {
        $this->assertKnownJob($cronJobId);

        return [
            'cron_job_id' => $cronJobId,
            'logs' => $this->executionLogs->recentForJob($cronJobId, $limit),
            'available' => $this->executionLogs->isAvailable(),
        ];
    }

    /** @return array<string, mixed> */
    public function executionLog(int $logId): array
    {
        $log = $this->executionLogs->find($logId);
        if ($log === null) {
            throw ValidationException::withMessages([
                'log_id' => ["Unknown cron execution log #{$logId}."],
            ]);
        }

        $this->assertKnownJob((string) $log['cron_job_id']);

        return $log;
    }

    /** @return array<string, mixed> */
    public function runNow(string $cronJobId): array
    {
        // Manual "Run now" is always allowed regardless of enabled/disabled state.
        // This lets users test individual crons while the scheduler is stopped.
        if (! $this->supportsRunNow($cronJobId)) {
            $this->assertKnownJob($cronJobId);

            throw ValidationException::withMessages([
                'cron_job_id' => ['This cron job cannot be triggered manually from the admin console.'],
            ]);
        }

        $logId = $this->executionLogs->start($cronJobId, 'manual');

        RunAdminCronJob::dispatch($cronJobId, $logId, force: true, trigger: 'manual')
            ->afterResponse();

        return [
            'cron_job_id' => $cronJobId,
            'status' => 'queued',
            'log_id' => $logId > 0 ? $logId : null,
            'action' => 'queued',
            'message' => 'Cron job queued. Track progress in execution logs — the dashboard stays responsive while it runs.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function executeRun(string $cronJobId, bool $force = false, string $trigger = 'scheduled'): array
    {
        return $this->dispatchRun($cronJobId, $force, $trigger);
    }

    public function supportsRunNow(string $cronJobId): bool
    {
        return match ($cronJobId) {
            'xs2-inventory-incremental',
            'xs2-inventory-full',
            'xs2-events-sync',
            'xs2-sb-new-listing-publish',
            'xs2-sb-failed-listing-publish-retry',
            'xs2-sb-listing-inventory',
            'xs2-sb-order-sync',
            'xs2-sb-order-guest-data-sync',
            'sb-events-sync',
            'sanctum-prune-expired' => true,
            default => false,
        };
    }

    /** @param  array<string, mixed>  $task */
    private function descriptionForTask(array $task): string
    {
        if (is_string($task['schedule_detail'] ?? null) && trim((string) $task['schedule_detail']) !== '') {
            return trim((string) $task['schedule_detail']);
        }

        $extra = is_array($task['extra'] ?? null) ? $task['extra'] : [];
        if (is_string($extra['what_it_does'] ?? null) && trim((string) $extra['what_it_does']) !== '') {
            return trim((string) $extra['what_it_does']);
        }

        return (string) ($task['name'] ?? $task['id'] ?? 'Scheduled task');
    }

    /** @return array<string, mixed> */
    private function dispatchRun(string $cronJobId, bool $force = false, string $trigger = 'scheduled'): array
    {
        if ($cronJobId === 'xs2-inventory-incremental') {
            $params = ['--mode' => 'incremental'];
            if ($force) {
                $params['--force'] = true;
            }
            $exitCode = Artisan::call('xs2:sync-inventory', $params);
            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'Incremental inventory sync command failed.');
            }

            return [
                'action' => 'command',
                'command' => 'xs2:sync-inventory --mode=incremental',
                'exit_code' => $exitCode,
                'message' => trim(Artisan::output()) ?: 'Queued incremental inventory sync jobs.',
            ];
        }

        if ($cronJobId === 'xs2-inventory-full') {
            $params = ['--mode' => 'full'];
            if ($force) {
                $params['--force'] = true;
            }
            $exitCode = Artisan::call('xs2:sync-inventory', $params);
            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'Full inventory sync command failed.');
            }

            return [
                'action' => 'command',
                'command' => 'xs2:sync-inventory --mode=full',
                'exit_code' => $exitCode,
                'message' => trim(Artisan::output()) ?: 'Queued full inventory sync jobs.',
            ];
        }

        if ($cronJobId === 'xs2-events-sync') {
            $params = $force ? ['--force' => true] : [];
            $exitCode = Artisan::call('xs2:sync-events', $params);
            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'XS2 events sync command failed.');
            }

            return [
                'action' => 'command',
                'command' => 'xs2:sync-events',
                'exit_code' => $exitCode,
                'message' => trim(Artisan::output()) ?: 'Queued XS2 events sync for all configured sports.',
            ];
        }

        if ($cronJobId === 'xs2-sb-new-listing-publish') {
            $params = [];
            if ($force) {
                $params['--force'] = true;
            }
            if ($trigger === 'manual') {
                $params['--manual'] = true;
            }
            $exitCode = Artisan::call('xs2:publish-new-sb-listings', $params);
            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'Seats Broker new listing publish failed.');
            }

            return [
                'action' => 'command',
                'command' => 'xs2:publish-new-sb-listings',
                'exit_code' => $exitCode,
                'message' => trim(Artisan::output()) ?: 'Seats Broker new listing publish completed.',
            ];
        }

        if ($cronJobId === 'xs2-sb-failed-listing-publish-retry') {
            $params = [];
            if ($force) {
                $params['--force'] = true;
            }
            $exitCode = Artisan::call('xs2:retry-failed-listing-publish', $params);
            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'Seats Broker failed listing publish retry failed.');
            }

            return [
                'action' => 'command',
                'command' => 'xs2:retry-failed-listing-publish',
                'exit_code' => $exitCode,
                'message' => trim(Artisan::output()) ?: 'Seats Broker failed listing publish retry completed.',
            ];
        }

        if ($cronJobId === 'xs2-sb-listing-inventory') {
            $params = $force ? ['--force' => true] : [];
            $exitCode = Artisan::call('xs2:sync-sb-listing-inventory', $params);
            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'Seats Broker listing inventory sync failed.');
            }

            return [
                'action' => 'command',
                'command' => 'xs2:sync-sb-listing-inventory',
                'exit_code' => $exitCode,
                'message' => trim(Artisan::output()) ?: 'Seats Broker listing inventory sync completed.',
            ];
        }

        if ($cronJobId === 'xs2-sb-order-sync') {
            $exitCode = Artisan::call('seller-api:sync-bookings');
            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'SB order → XS2 sandbox order sync failed.');
            }

            return [
                'action' => 'command',
                'command' => 'seller-api:sync-bookings',
                'exit_code' => $exitCode,
                'message' => trim(Artisan::output()) ?: 'SB order → XS2 sandbox order sync completed.',
            ];
        }

        if ($cronJobId === 'xs2-sb-order-guest-data-sync') {
            $exitCode = Artisan::call('xs2:sync-order-guest-data');
            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'SB order guest data → XS2 sync failed.');
            }

            return [
                'action' => 'command',
                'command' => 'xs2:sync-order-guest-data',
                'exit_code' => $exitCode,
                'message' => trim(Artisan::output()) ?: 'SB order guest data → XS2 sync completed.',
            ];
        }

        if ($cronJobId === 'xs2-split-listing-quantities') {
            $exitCode = Artisan::call('xs2:sync-split-listing-quantities');
            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'Split listing quantity sync failed.');
            }

            return [
                'action' => 'command',
                'command' => 'xs2:sync-split-listing-quantities',
                'exit_code' => $exitCode,
                'message' => trim(Artisan::output()) ?: 'Split listing quantity sync completed.',
            ];
        }

        if ($cronJobId === 'sb-events-sync') {
            $result = app(\App\Services\SellerApi\SellerEventImportService::class)->refreshCatalog('sandbox');

            return [
                'action' => 'catalog_refresh',
                'command' => 'seller-api:fetch-events (catalog cache)',
                'message' => sprintf(
                    'Refreshed Seats Broker catalog cache (%d events, %s).',
                    $result['event_count'],
                    $result['environment'],
                ),
                'event_count' => $result['event_count'],
                'environment' => $result['environment'],
            ];
        }

        if ($cronJobId === 'sanctum-prune-expired') {
            $exitCode = Artisan::call('sanctum:prune-expired', ['--hours' => 24]);
            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'Sanctum prune command failed.');
            }

            return [
                'action' => 'command',
                'command' => 'sanctum:prune-expired --hours=24',
                'exit_code' => $exitCode,
                'message' => trim(Artisan::output()) ?: 'Expired Sanctum tokens pruned.',
            ];
        }

        if (preg_match('/^xs2-events-([a-z0-9_-]+)-(incremental|full)$/', $cronJobId, $matches) === 1) {
            $sport = $matches[1];
            $full = $matches[2] === 'full';
            SyncXs2EventsJob::dispatch($sport, $full);

            return [
                'action' => 'queued_job',
                'command' => sprintf('SyncXs2EventsJob(sport: %s, full: %s)', $sport, $full ? 'true' : 'false'),
                'message' => sprintf('Queued XS2 %s event sync (%s).', $sport, $full ? 'full' : 'incremental'),
            ];
        }

        throw new \RuntimeException("No run handler registered for {$cronJobId}.");
    }

    private function assertKnownJob(string $cronJobId): void
    {
        if (! $this->isKnownJobId($cronJobId)) {
            throw ValidationException::withMessages([
                'cron_job_id' => ["Unknown cron job “{$cronJobId}”."],
            ]);
        }
    }

    private function isKnownJobId(string $cronJobId): bool
    {
        if (in_array($cronJobId, self::KNOWN_CRON_JOB_IDS, true)) {
            return true;
        }

        return preg_match('/^xs2-events-[a-z0-9_-]+-(incremental|full)$/', $cronJobId) === 1;
    }

    /** @return array<string, mixed>|null */
    private function findTask(string $cronJobId): ?array
    {
        foreach ($this->cronConfig->snapshot()['tasks'] as $task) {
            if (($task['id'] ?? null) === $cronJobId) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $task
     * @return array<string, mixed>|null
     */
    private function presentTask(?array $task): ?array
    {
        if ($task === null) {
            return null;
        }

        $task['description'] = $this->descriptionForTask($task);
        $task['supports_run_now'] = $this->supportsRunNow((string) $task['id']);
        $task['execution_logs_available'] = $this->executionLogs->isAvailable();
        $task['execution_log_count'] = $this->executionLogs->countForJob((string) $task['id']);

        return $task;
    }

    private function intervalSavedMessage(string $cronJobId, int $minutes): string
    {
        $label = $minutes === 1 ? 'every minute' : "every {$minutes} minutes";

        return "Cron duration for {$cronJobId} saved ({$label}). OS cron (`php artisan schedule:run` every minute) picks this up on the next tick. Restart `php artisan schedule:work` if you use that long-running process.";
    }
}
