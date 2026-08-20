<?php

namespace App\Jobs;

use App\Exceptions\Integrations\Xs2RateLimitException;
use App\Models\EventMapping;
use App\Models\Xs2Event;
use App\Services\Xs2\Xs2ApiDebugRecorder;
use App\Models\PipelineJobStep;
use App\Models\PipelineRun;
use App\Services\Pipeline\InventorySchedulerService;
use App\Services\Pipeline\PipelineJobStepService;
use App\Services\Pipeline\PipelineRunService;
use App\Services\Xs2\Xs2EventInventorySyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncXs2EventInventory implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 5;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public string $scope = 'full';

    public function __construct(
        public int|string $reference,
        public string $mode = 'incremental',
        public bool $referenceIsMappingId = true,
        ?string $scope = null,
        public ?int $pipelineRunId = null,
        public ?string $correlationId = null,
    ) {
        if ($scope !== null) {
            $this->scope = $scope;
        }
        $this->onQueue(config('xs2.queue', config('services.xs2.queue')));
    }

    public function uniqueId(): string
    {
        // Full and incremental syncs must share a lock. A full sync already
        // includes the incremental data, so running both only multiplies the
        // number of XS2 requests for the same event.
        return 'xs2-event-inventory:'.($this->referenceIsMappingId ? 'mapping:' : 'event:').$this->reference;
    }

    public function handle(
        Xs2EventInventorySyncService $service,
        Xs2ApiDebugRecorder $recorder,
        PipelineJobStepService $pipelineSteps,
        InventorySchedulerService $scheduler,
        PipelineRunService $pipelineRuns,
    ): void {
        $event = $this->event();
        if ((bool) config('pipeline.strict', true) && $this->pipelineRunId === null) {
            $run = $pipelineRuns->start('manual', $this->mode, 1);
            $this->pipelineRunId = $run->id;
            $this->correlationId = $run->correlation_id;
            $pipelineRuns->recordDispatch($run, 1);
        }

        $step = $this->pipelineStep($pipelineSteps, $event->id);
        if ($step !== null) {
            $pipelineSteps->start($step);
        }

        $lock = Cache::lock(
            'xs2-event-inventory:event:'.$event->id,
            max(1, (int) config('xs2.sync.event_lock_minutes', 10)) * 60,
        );
        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        $recorder->enable();
        $started = microtime(true);
        try {
            $summary = $service->sync($event, $this->mode, $this->scope);
            if ($step !== null) {
                $pipelineSteps->complete($step, (int) ((microtime(true) - $started) * 1000));
            }

            if (
                (bool) config('pipeline.strict', true)
                && $this->pipelineRunId !== null
                && filled($this->correlationId)
            ) {
                $scheduler->scheduleListingGeneration($event->id, $this->pipelineRunId, $this->correlationId);
            }

            Log::channel(config('xs2.log_channel', 'stack'))->info('XS2 event inventory synchronized.', [
                'provider' => 'xs2event',
                'job' => self::class,
                'external_event_id' => $summary['external_event_id'] ?? null,
                'sync_mode' => $this->mode,
                'correlation_id' => $this->correlationId,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
        } catch (Xs2RateLimitException $exception) {
            if ($step !== null) {
                $pipelineSteps->fail($step, $exception->getMessage());
            }
            $this->release(max(1, $exception->retryAfter));
        } catch (\Throwable $exception) {
            if ($step !== null) {
                $pipelineSteps->fail($step, $exception->getMessage());
            }
            throw $exception;
        } finally {
            $interactions = $recorder->flush();
            if ($interactions !== []) {
                $recorder->appendCronInteractions(
                    $interactions,
                    'xs2-inventory',
                    'xs2-event-inventory',
                    $event->external_event_id,
                );
            }
            $lock->release();
        }
    }

    private function event(): Xs2Event
    {
        if ($this->referenceIsMappingId) {
            return EventMapping::query()
                ->with('xs2Event')
                ->findOrFail($this->reference)
                ->xs2Event;
        }

        if (is_int($this->reference) || (is_string($this->reference) && ctype_digit($this->reference))) {
            return Xs2Event::query()->findOrFail((int) $this->reference);
        }

        return Xs2Event::query()->where('external_event_id', (string) $this->reference)->firstOrFail();
    }

    private function pipelineStep(PipelineJobStepService $pipelineSteps, int $xs2EventId): ?\App\Models\PipelineJobStep
    {
        if ($this->pipelineRunId === null) {
            return null;
        }

        $run = PipelineRun::query()->find($this->pipelineRunId);
        if ($run === null) {
            return null;
        }

        return $pipelineSteps->findOrCreateStep(
            $run,
            $xs2EventId,
            PipelineJobStep::STAGE_INVENTORY,
            self::class,
        );
    }
}
