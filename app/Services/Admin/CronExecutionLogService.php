<?php

namespace App\Services\Admin;

use App\Models\CronExecutionLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CronExecutionLogService
{
    private const MAX_API_REQUESTS = 100;

    /** @var array<int, int> */
    private array $activeLogIds = [];

    /** @var array<int, string> */
    private array $activeTaskIds = [];

    public function __construct(
        private readonly CronExecutionContext $context,
    ) {}

    public function isAvailable(): bool
    {
        try {
            return Schema::hasTable('cron_execution_logs');
        } catch (Throwable) {
            return false;
        }
    }

    public function attachScheduleHooks(): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        try {
            /** @var Schedule $schedule */
            $schedule = app(Schedule::class);
            $events = $schedule->events();
        } catch (Throwable) {
            return;
        }

        foreach ($events as $event) {
            $taskId = CronTaskIdentifier::forEvent($event);
            if ($taskId === null) {
                continue;
            }

            $eventHash = spl_object_id($event);

            $event->before(function () use ($taskId, $eventHash): void {
                $logId = $this->start($taskId, 'scheduled');
                $this->activeLogIds[$eventHash] = $logId;
                $this->activeTaskIds[$eventHash] = $taskId;
                if ($logId > 0) {
                    $this->context->set($logId, $taskId);
                }
            });

            $event->onSuccess(function () use ($eventHash): void {
                $logId = $this->activeLogIds[$eventHash] ?? null;
                if ($logId === null) {
                    return;
                }

                $this->finish($logId, 'success', message: 'Scheduled run completed successfully.');
                unset($this->activeLogIds[$eventHash], $this->activeTaskIds[$eventHash]);
                $this->context->clear();
            });

            $event->onFailure(function () use ($eventHash): void {
                $logId = $this->activeLogIds[$eventHash] ?? null;
                if ($logId === null) {
                    return;
                }

                $this->finish($logId, 'failed', errorMessage: 'Scheduled run failed.');
                unset($this->activeLogIds[$eventHash], $this->activeTaskIds[$eventHash]);
                $this->context->clear();
            });
        }
    }

    public function start(string $cronJobId, string $trigger = 'scheduled'): int
    {
        if (! $this->isAvailable()) {
            return 0;
        }

        $log = CronExecutionLog::query()->create([
            'cron_job_id' => $cronJobId,
            'trigger' => $trigger,
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [
                'command' => $this->commandForTask($cronJobId),
            ],
        ]);

        return (int) $log->id;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function finish(
        int $logId,
        string $status,
        ?string $message = null,
        ?string $errorMessage = null,
        array $metadata = [],
    ): void {
        if (! $this->isAvailable() || $logId <= 0) {
            return;
        }

        $log = CronExecutionLog::query()->find($logId);
        if (! $log instanceof CronExecutionLog) {
            return;
        }

        $finishedAt = now();
        $startedAt = $log->started_at instanceof Carbon ? $log->started_at : Carbon::parse((string) $log->started_at);
        $mergedMetadata = $this->mergeMetadataArrays($log->metadata ?? [], $metadata);

        $log->update([
            'status' => $status,
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, (int) $startedAt->diffInMilliseconds($finishedAt)),
            'message' => $message,
            'error_message' => $errorMessage,
            'metadata' => $mergedMetadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function mergeMetadata(int $logId, array $metadata): void
    {
        if (! $this->isAvailable() || $logId <= 0 || $metadata === []) {
            return;
        }

        $log = CronExecutionLog::query()->find($logId);
        if (! $log instanceof CronExecutionLog) {
            return;
        }

        $log->update([
            'metadata' => $this->mergeMetadataArrays($log->metadata ?? [], $metadata),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $requests
     */
    public function appendApiRequests(int $logId, array $requests, ?string $source = null): void
    {
        if (! $this->isAvailable() || $logId <= 0 || $requests === []) {
            return;
        }

        $log = CronExecutionLog::query()->find($logId);
        if (! $log instanceof CronExecutionLog) {
            return;
        }

        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $existing = is_array($metadata['api_requests'] ?? null) ? $metadata['api_requests'] : [];
        $recordedAt = now()->toIso8601String();

        foreach ($requests as $request) {
            if (! is_array($request)) {
                continue;
            }

            $existing[] = [
                ...$request,
                'source' => $source ?? ($request['source'] ?? null),
                'recorded_at' => $request['recorded_at'] ?? $recordedAt,
            ];
        }

        $metadata['api_requests'] = array_slice($existing, -self::MAX_API_REQUESTS);

        $log->update(['metadata' => $metadata]);
    }

    /**
     * Append API interactions from queue workers to the most recent inventory cron log.
     *
     * @param  list<array<string, mixed>>  $requests
     */
    public function appendInventoryApiRequests(
        array $requests,
        ?string $externalEventId = null,
        ?string $taskId = null,
    ): void {
        if ($requests === []) {
            return;
        }

        $logId = $this->context->activeLogId();
        if ($logId === null) {
            $logId = $this->latestOpenLogId([
                'xs2-inventory-incremental',
                'xs2-inventory-full',
            ]);
        }

        if ($logId === null) {
            return;
        }

        $enriched = array_map(function (array $request) use ($externalEventId, $taskId): array {
            return [
                ...$request,
                'external_event_id' => $externalEventId,
                'task_id' => $taskId,
            ];
        }, $requests);

        $this->appendApiRequests($logId, $enriched, 'xs2-inventory');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $log = CronExecutionLog::query()->find($id);

        return $log instanceof CronExecutionLog ? $this->serializeLog($log) : null;
    }

    /** @return list<array<string, mixed>> */
    public function recentForJob(string $cronJobId, int $limit = 10): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return CronExecutionLog::query()
            ->where('cron_job_id', $cronJobId)
            ->orderByDesc('started_at')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn (CronExecutionLog $log): array => $this->serializeLog($log))
            ->values()
            ->all();
    }

    public function countForJob(string $cronJobId): int
    {
        if (! $this->isAvailable()) {
            return 0;
        }

        return (int) CronExecutionLog::query()->where('cron_job_id', $cronJobId)->count();
    }

    /**
     * Fetch execution-log counts for a dashboard in one query rather than one
     * query per task. The cron dashboard is polled while work is active.
     *
     * @param  list<string>  $cronJobIds
     * @return array<string, int>
     */
    public function countsForJobs(array $cronJobIds): array
    {
        $cronJobIds = array_values(array_unique(array_filter($cronJobIds, 'filled')));

        if ($cronJobIds === [] || ! $this->isAvailable()) {
            return [];
        }

        return CronExecutionLog::query()
            ->whereIn('cron_job_id', $cronJobIds)
            ->selectRaw('cron_job_id, COUNT(*) as execution_log_count')
            ->groupBy('cron_job_id')
            ->pluck('execution_log_count', 'cron_job_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function recentGlobal(int $limit = 10): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return CronExecutionLog::query()
            ->orderByDesc('started_at')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn (CronExecutionLog $log): array => $this->serializeLog($log))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $cronJobIds
     */
    private function latestOpenLogId(array $cronJobIds): ?int
    {
        $log = CronExecutionLog::query()
            ->whereIn('cron_job_id', $cronJobIds)
            ->where('started_at', '>=', now()->subHours(6))
            ->orderByDesc('started_at')
            ->first();

        return $log instanceof CronExecutionLog ? (int) $log->id : null;
    }

    private function commandForTask(string $cronJobId): ?string
    {
        return match ($cronJobId) {
            'xs2-inventory-incremental' => 'xs2:sync-inventory --mode=incremental',
            'xs2-inventory-full' => 'xs2:sync-inventory --mode=full',
            'xs2-sb-new-listing-publish' => 'xs2:publish-new-sb-listings',
            'xs2-sb-listing-inventory' => 'xs2:sync-sb-listing-inventory',
            'xs2-sb-order-sync' => 'seller-api:sync-bookings',
            'xs2-sb-order-guest-data-sync' => 'xs2:sync-order-guest-data',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeMetadataArrays(array $base, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if ($key === 'api_requests' && isset($base['api_requests']) && is_array($base['api_requests']) && is_array($value)) {
                $base['api_requests'] = array_slice([...$base['api_requests'], ...$value], -self::MAX_API_REQUESTS);

                continue;
            }

            if ($key === 'summary' && isset($base['summary']) && is_array($base['summary']) && is_array($value)) {
                $base['summary'] = [...$base['summary'], ...$value];

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /** @return array<string, mixed> */
    private function serializeLog(CronExecutionLog $log): array
    {
        $metadata = is_array($log->metadata) ? $log->metadata : [];

        return [
            'id' => $log->id,
            'cron_job_id' => $log->cron_job_id,
            'trigger' => $log->trigger,
            'status' => $log->status,
            'started_at' => $log->started_at?->toIso8601String(),
            'finished_at' => $log->finished_at?->toIso8601String(),
            'duration_ms' => $log->duration_ms,
            'message' => $log->message,
            'error_message' => $log->error_message,
            'command' => $metadata['command'] ?? $this->commandForTask((string) $log->cron_job_id),
            'summary' => $metadata['summary'] ?? $this->summaryFromMetadata($metadata),
            'api_requests' => is_array($metadata['api_requests'] ?? null) ? $metadata['api_requests'] : [],
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function summaryFromMetadata(array $metadata): array
    {
        $summary = [];
        foreach (['action', 'exit_code', 'dispatched', 'waves', 'chunk_size', 'delay_per_wave_seconds', 'estimated_completion_seconds', 'correlation_id', 'skipped_backpressure', 'event_count', 'fetched', 'skipped', 'failed'] as $key) {
            if (array_key_exists($key, $metadata)) {
                $summary[$key] = $metadata[$key];
            }
        }

        return $summary;
    }
}
