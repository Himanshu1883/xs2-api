<?php

namespace App\Jobs;

use App\Services\Admin\CronExecutionContext;
use App\Services\Admin\CronExecutionLogService;
use App\Services\Admin\CronJobManagementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs an admin-triggered cron command outside the HTTP request so the API stays responsive.
 */
class RunAdminCronJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(
        public readonly string $cronJobId,
        public readonly int $executionLogId = 0,
        public readonly bool $force = false,
        public readonly string $trigger = 'manual',
    ) {
        $this->onQueue(config('xs2.admin_cron_queue', 'default'));
    }

    public function handle(
        CronJobManagementService $cronJobs,
        CronExecutionLogService $executionLogs,
        CronExecutionContext $context,
    ): void {
        if ($this->executionLogId > 0) {
            $context->set($this->executionLogId, $this->cronJobId);
        }

        try {
            $result = $cronJobs->executeRun($this->cronJobId, $this->force);

            if ($this->executionLogId > 0) {
                $executionLogs->finish(
                    $this->executionLogId,
                    'success',
                    message: (string) ($result['message'] ?? 'Manual run completed.'),
                    metadata: [
                        'summary' => $result,
                        ...$result,
                        'trigger' => $this->trigger,
                    ],
                );
            }
        } catch (Throwable $exception) {
            if ($this->executionLogId > 0) {
                $executionLogs->finish(
                    $this->executionLogId,
                    'failed',
                    errorMessage: $exception->getMessage(),
                    metadata: ['trigger' => $this->trigger],
                );
            }

            throw $exception;
        } finally {
            $context->clear();
        }
    }
}
