<?php

namespace App\Services\Pipeline;

use App\Jobs\GenerateEventListingsJob;
use App\Jobs\ReconcileEventListingsJob;
use App\Jobs\SyncXs2EventInventory;
use App\Models\PipelineRun;
use App\Models\Xs2Event;
use App\Services\Admin\QueueBackpressureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class InventorySchedulerService
{
    public function __construct(
        private readonly PipelineRunService $pipelineRuns,
        private readonly PipelineJobStepService $pipelineSteps,
        private readonly QueueBackpressureService $backpressure,
    ) {}

    /**
     * @param  callable(Xs2Event): bool|null  $eventFilter
     * @return array{
     *     pipeline_run: PipelineRun|null,
     *     dispatched: int,
     *     skipped_backpressure: bool,
     *     reconciliation_count: int,
     *     waves: int,
     *     chunk_size: int,
     *     delay_per_wave_seconds: int,
     *     estimated_completion_seconds: int
     * }
     */
    public function dispatchDueEvents(
        string $mode,
        string $trigger,
        bool $force = false,
        bool $bulk = false,
        string $scope = 'full',
        ?callable $eventFilter = null,
        ?int $singleEventId = null,
        ?int $mappingId = null,
        bool $onlyWithTickets = false,
    ): array {
        if (! $force && $this->backpressure->shouldSkipScheduledDispatch()) {
            return [
                'pipeline_run' => null,
                'dispatched' => 0,
                'skipped_backpressure' => true,
                'reconciliation_count' => 0,
                'waves' => 0,
                'chunk_size' => 0,
                'delay_per_wave_seconds' => 0,
                'estimated_completion_seconds' => 0,
            ];
        }

        $events = $this->dueEvents($force, $onlyWithTickets, $singleEventId, $mappingId, $eventFilter);
        $budget = $force ? PHP_INT_MAX : max(1, (int) config('pipeline.max_events_per_run', 6000));
        $toDispatch = $events->take($budget);

        $run = $this->pipelineRuns->start($trigger, $mode, $events->count());

        $dispatched = 0;
        $chunkSize = (int) config('pipeline.staggered_dispatch.chunk_size', 10);
        $delayPerWave = (int) config('pipeline.staggered_dispatch.delay_per_wave_seconds', 90);

        foreach ($toDispatch as $index => $event) {
            $wave = intdiv($index, $chunkSize);
            $delaySeconds = $wave * $delayPerWave;

            SyncXs2EventInventory::dispatch(
                $event->id,
                $mode,
                false,
                $scope,
                $run->id,
                $run->correlation_id,
            )->delay(now()->addSeconds($delaySeconds));

            $this->pipelineSteps->queue(
                $run,
                $event->id,
                \App\Models\PipelineJobStep::STAGE_INVENTORY,
                SyncXs2EventInventory::class,
            );

            $dispatched++;
        }

        $this->pipelineRuns->recordDispatch($run, $dispatched);

        $totalWaves = $dispatched > 0 ? intdiv($dispatched - 1, $chunkSize) + 1 : 0;
        $estimatedSeconds = $totalWaves > 1 ? ($totalWaves - 1) * $delayPerWave : 0;

        Log::info('[InventoryScheduler] Staggered dispatch complete', [
            'mode' => $mode,
            'trigger' => $trigger,
            'dispatched' => $dispatched,
            'waves' => $totalWaves,
            'chunk_size' => $chunkSize,
            'delay_per_wave_seconds' => $delayPerWave,
            'estimated_completion_seconds' => $estimatedSeconds,
            'correlation_id' => $run->correlation_id,
        ]);

        return [
            'pipeline_run' => $run,
            'dispatched' => $dispatched,
            'skipped_backpressure' => false,
            'reconciliation_count' => 0,
            'waves' => $totalWaves,
            'chunk_size' => $chunkSize,
            'delay_per_wave_seconds' => $delayPerWave,
            'estimated_completion_seconds' => $estimatedSeconds,
        ];
    }

    public function priorityDelaySeconds(Xs2Event $event, int $index, int $total): int
    {
        if ($total <= 1) {
            return 0;
        }

        $hours = $event->date_start_local
            ? now()->diffInHours($event->date_start_local, false)
            : null;

        if ($hours !== null && $hours <= 24 && $hours >= 0) {
            return min($index, 30);
        }

        if ($hours !== null && $hours <= 24 * 7 && $hours >= 0) {
            return (int) min($index * 2, 300);
        }

        $windowSeconds = max(60, (int) config('pipeline.dispatch_window_minutes', 30) * 60);

        return (int) floor($index * ($windowSeconds / max(1, $total)));
    }

    /**
     * @param  callable(Xs2Event): bool|null  $eventFilter
     * @return Collection<int, Xs2Event>
     */
    private function dueEvents(
        bool $force,
        bool $onlyWithTickets,
        ?int $singleEventId,
        ?int $mappingId,
        ?callable $eventFilter,
    ): Collection {
        $query = Xs2Event::query()
            ->with(['inventorySyncState', 'mapping'])
            ->where('date_start_local', '>=', now());

        if ($onlyWithTickets) {
            $query->where('number_of_tickets', '>', 0);
        }

        if ($mappingId !== null) {
            $query->whereHas('mapping', fn ($mapping) => $mapping->whereKey($mappingId));
        }

        if ($singleEventId !== null) {
            $query->whereKey($singleEventId);
        }

        $events = $query->get()->filter(function (Xs2Event $event) use ($force, $eventFilter): bool {
            if (! $event->isSellable()) {
                return false;
            }

            if ($event->mapping?->status === 'ignored') {
                return false;
            }

            if ($eventFilter !== null && ! $eventFilter($event)) {
                return false;
            }

            if ($force) {
                return true;
            }

            $state = $event->inventorySyncState;
            if ($state?->tickets_next_sync_at?->isFuture()) {
                return false;
            }

            return true;
        });

        return $events->sortBy(fn (Xs2Event $event): int => $this->priorityRank($event))->values();
    }

    private function priorityRank(Xs2Event $event): int
    {
        if (! $event->date_start_local) {
            return 1000;
        }

        $hours = now()->diffInHours($event->date_start_local, false);
        if ($hours <= 24 && $hours >= 0) {
            return 0;
        }
        if ($hours <= 24 * 7 && $hours >= 0) {
            return 100;
        }

        return 500;
    }

    public function scheduleListingGeneration(int $xs2EventId, int $pipelineRunId, string $correlationId): void
    {
        $run = PipelineRun::query()->findOrFail($pipelineRunId);

        GenerateEventListingsJob::dispatch($xs2EventId, $pipelineRunId, $correlationId)
            ->onQueue(config('pipeline.listing_gen_queue', 'xs2-listing-gen'));

        $this->pipelineSteps->queue(
            $run,
            $xs2EventId,
            \App\Models\PipelineJobStep::STAGE_LISTING_GEN,
            GenerateEventListingsJob::class,
        );
    }

    public function scheduleReconciliation(int $xs2EventId, int $pipelineRunId, string $correlationId): void
    {
        $run = PipelineRun::query()->findOrFail($pipelineRunId);
        $delaySeconds = max(0, (int) config('pipeline.reconcile_delay_seconds', 120));

        ReconcileEventListingsJob::dispatch($xs2EventId, $pipelineRunId, $correlationId)
            ->onQueue(config('pipeline.reconcile_queue', 'xs2-reconcile'))
            ->delay(now()->addSeconds($delaySeconds));

        $this->pipelineSteps->queue(
            $run,
            $xs2EventId,
            \App\Models\PipelineJobStep::STAGE_RECONCILE,
            ReconcileEventListingsJob::class,
        );
    }
}
