<?php

namespace App\Services\Admin;

use App\Models\CronExecutionLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CronExecutionLogService
{
    /** @var array<int, int> */
    private array $activeLogIds = [];

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
                $this->activeLogIds[$eventHash] = $this->start($taskId, 'scheduled');
            });

            $event->onSuccess(function () use ($eventHash): void {
                $logId = $this->activeLogIds[$eventHash] ?? null;
                if ($logId === null) {
                    return;
                }

                $this->finish($logId, 'success', message: 'Scheduled run completed successfully.');
                unset($this->activeLogIds[$eventHash]);
            });

            $event->onFailure(function () use ($eventHash): void {
                $logId = $this->activeLogIds[$eventHash] ?? null;
                if ($logId === null) {
                    return;
                }

                $this->finish($logId, 'failed', errorMessage: 'Scheduled run failed.');
                unset($this->activeLogIds[$eventHash]);
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

        $log->update([
            'status' => $status,
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, (int) $startedAt->diffInMilliseconds($finishedAt)),
            'message' => $message,
            'error_message' => $errorMessage,
            'metadata' => $metadata !== [] ? $metadata : $log->metadata,
        ]);
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

    /** @return array<string, mixed> */
    private function serializeLog(CronExecutionLog $log): array
    {
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
            'metadata' => $log->metadata ?? [],
        ];
    }
}
