<?php

namespace App\Console\Concerns;

use App\Services\Admin\QueueBackpressureService;

trait RespectsQueueBackpressure
{
    protected function respectsQueueBackpressure(): bool
    {
        if (! method_exists($this, 'option')) {
            return true;
        }

        return ! (bool) $this->option('force');
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

        $backpressure = $this->queueBackpressure();
        if (! $backpressure->shouldSkipScheduledDispatch()) {
            return false;
        }

        $status = $backpressure->status();
        $this->warn(sprintf(
            'Skipping dispatch — queue backpressure active (%d/%d pending jobs, profile %s). Use --force to override.',
            $status['pending_jobs'],
            $status['max_pending_jobs'],
            $status['profile'],
        ));

        return true;
    }

    protected function queueDispatchBudget(): int
    {
        if (! $this->respectsQueueBackpressure()) {
            return PHP_INT_MAX;
        }

        return $this->queueBackpressure()->remainingDispatchBudget();
    }
}
