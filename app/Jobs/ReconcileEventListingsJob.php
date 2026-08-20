<?php

namespace App\Jobs;

use App\Models\PipelineJobStep;
use App\Models\PipelineRun;
use App\Services\Pipeline\EventListingReconciliationService;
use App\Services\Pipeline\PipelineJobStepService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReconcileEventListingsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $xs2EventId,
        public int $pipelineRunId,
        public string $correlationId,
    ) {
        $this->onQueue(config('pipeline.reconcile_queue', 'xs2-reconcile'));
    }

    public function uniqueId(): string
    {
        return 'reconcile:event:'.$this->xs2EventId;
    }

    public function handle(
        EventListingReconciliationService $reconciliation,
        PipelineJobStepService $steps,
    ): void {
        $run = PipelineRun::query()->findOrFail($this->pipelineRunId);
        $step = $steps->findOrCreateStep(
            $run,
            $this->xs2EventId,
            PipelineJobStep::STAGE_RECONCILE,
            self::class,
        );
        $steps->start($step);

        $lock = Cache::lock('reconcile:event:'.$this->xs2EventId, 600);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        $started = microtime(true);

        try {
            $summary = $reconciliation->reconcileEvent($this->xs2EventId);

            if (($summary['errors'] ?? []) !== []) {
                $steps->fail($step, implode('; ', $summary['errors']));
            } else {
                $steps->complete($step, (int) ((microtime(true) - $started) * 1000));
            }

            Log::channel(config('pipeline.log_channel', 'stack'))->info('Pipeline event reconciliation completed.', [
                'correlation_id' => $this->correlationId,
                'xs2_event_id' => $this->xs2EventId,
                'summary' => $summary,
            ]);
        } catch (\Throwable $exception) {
            $steps->fail($step, $exception->getMessage());
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
