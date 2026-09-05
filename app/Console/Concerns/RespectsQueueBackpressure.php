<?php

namespace App\Console\Concerns;

use App\Services\Admin\QueueBackpressureService;
use Illuminate\Support\Facades\Log;

trait RespectsQueueBackpressure
{
    protected function respectsQueueBackpressure(): bool
    {
        if (! method_exists($this, 'option')) {
            return true;
        }

        return ! (bool) $this->option('force');
    }

    /**
     * Override in seller-api publish/inventory crons to scope backpressure to that queue.
     */
    protected function queueBackpressureScope(): ?string
    {
        return null;
    }

    protected function queueBackpressure(): QueueBackpressureService
    {
        return app(QueueBackpressureService::class);
    }

    protected function skipIfQueueBackpressureActive(): bool
    {
        if (! $this->respectsQueueBackpressure()) {
            return false;
        }

        $scope = $this->queueBackpressureScope();
        $backpressure = $this->queueBackpressure();
        if (! $backpressure->shouldSkipScheduledDispatch($scope)) {
            return false;
        }

        $status = $backpressure->status($scope);
        $scopeLabel = $scope ?? 'global (excl. seller-api)';
        $message = sprintf(
            'Skipping dispatch — queue backpressure active for %s (%d/%d pending jobs, profile %s). Use --force to override.',
            $scopeLabel,
            $status['pending_jobs'],
            $status['max_pending_jobs'],
            $status['profile'],
        );
        $this->warn($message);

        Log::info('[QueueBackpressure] Cron dispatch skipped', [
            'command' => method_exists($this, 'getName') ? $this->getName() : static::class,
            'scope' => $scope ?? 'global',
            'pending_jobs' => $status['pending_jobs'],
            'max_pending_jobs' => $status['max_pending_jobs'],
            'profile' => $status['profile'],
        ]);

        return true;
    }

    protected function queueDispatchBudget(): int
    {
        if (! $this->respectsQueueBackpressure()) {
            return PHP_INT_MAX;
        }

        return $this->queueBackpressure()->remainingDispatchBudget($this->queueBackpressureScope());
    }
}
