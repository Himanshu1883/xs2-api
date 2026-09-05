<?php

namespace App\Services\Pipeline;

use App\Models\PipelineJobStep;
use App\Models\PipelineRun;
use App\Models\Xs2Event;
use App\Models\Xs2EventInventorySyncState;
use App\Services\Admin\QueueManagementService;
use Illuminate\Support\Facades\Schema;

class PipelineWorkloadService
{
    public function __construct(
        private readonly QueueManagementService $queues,
        private readonly PipelineStaleStateService $staleStates,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        if (! Schema::hasTable('pipeline_runs')) {
            return $this->emptySnapshot();
        }

        $this->staleStates->reconcile();

        $currentRun = PipelineRun::query()->where('status', 'running')->latest('started_at')->first();
        $lastRun = PipelineRun::query()
            ->whereIn('status', ['completed', 'partial', 'failed'])
            ->latest('finished_at')
            ->first();

        $stages = $this->stageMetrics();
        $summary = $this->aggregateSummary($stages);
        $sla = $this->slaStatus();

        return [
            'generated_at' => now()->toIso8601String(),
            'config' => [
                'strict' => (bool) config('pipeline.strict', true),
                'max_events_per_run' => (int) config('pipeline.max_events_per_run', 6000),
                'dispatch_window_minutes' => (int) config('pipeline.dispatch_window_minutes', 30),
                'sla_hours_before_event' => (int) config('pipeline.sla_hours_before_event', 48),
                'stall_minutes' => (int) config('pipeline.stall_minutes', 15),
            ],
            'summary' => $summary,
            'stages' => $stages,
            'sla' => $sla,
            'stalled_events' => $this->stalledEvents(),
            'current_run' => $currentRun ? $this->formatRun($currentRun) : null,
            'last_run' => $lastRun ? $this->formatRun($lastRun) : null,
            'queues' => $this->queueBreakdown(),
        ];
    }

    /** @return array<string, mixed> */
    public function formatRun(PipelineRun $run): array
    {
        return [
            'id' => $run->id,
            'correlation_id' => $run->correlation_id,
            'trigger' => $run->trigger,
            'mode' => $run->mode,
            'status' => $run->status,
            'events_due' => (int) $run->events_due,
            'events_dispatched' => (int) $run->events_dispatched,
            'events_completed' => (int) $run->events_completed,
            'events_failed' => (int) $run->events_failed,
            'scheduled_at' => $run->scheduled_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function runDetail(string $correlationId): ?array
    {
        if (! Schema::hasTable('pipeline_runs')) {
            return null;
        }

        $run = PipelineRun::query()
            ->where('correlation_id', $correlationId)
            ->with(['steps.event:id,external_event_id,event_name,date_start_local'])
            ->first();

        if ($run === null) {
            return null;
        }

        return [
            'run' => $this->formatRun($run),
            'steps' => $run->steps->map(fn (PipelineJobStep $step): array => [
                'id' => $step->id,
                'xs2_event_id' => $step->xs2_event_id,
                'external_event_id' => $step->event?->external_event_id,
                'event_name' => $step->event?->event_name,
                'stage' => $step->stage,
                'status' => $step->status,
                'job_class' => $step->job_class,
                'attempts' => (int) $step->attempts,
                'error_message' => $step->error_message,
                'started_at' => $step->started_at?->toIso8601String(),
                'finished_at' => $step->finished_at?->toIso8601String(),
                'duration_ms' => $step->duration_ms,
            ])->values()->all(),
        ];
    }

    /** @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int} */
    public function paginatedRuns(int $page = 1, int $perPage = 20): array
    {
        if (! Schema::hasTable('pipeline_runs')) {
            return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }

        $paginator = PipelineRun::query()
            ->latest('started_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (PipelineRun $run) => $this->formatRun($run))->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ];
    }

    /** @return array<string, mixed> */
    private function stageMetrics(): array
    {
        $queueMap = [
            'xs2-sync' => PipelineJobStep::STAGE_INVENTORY,
            config('pipeline.listing_gen_queue', 'xs2-listing-gen') => PipelineJobStep::STAGE_LISTING_GEN,
            config('services.seller_api.queue', 'seller-api') => PipelineJobStep::STAGE_PUBLISH,
            config('pipeline.reconcile_queue', 'xs2-reconcile') => PipelineJobStep::STAGE_RECONCILE,
        ];

        $queueSnapshot = $this->queues->snapshot();
        $byQueue = [];
        foreach ($queueSnapshot['queues'] ?? [] as $row) {
            $byQueue[(string) ($row['value'] ?? '')] = $row;
        }
        foreach ($queueSnapshot['other_queues'] ?? [] as $row) {
            $byQueue[(string) ($row['queue'] ?? '')] = $row;
        }

        $stepsByStage = Schema::hasTable('pipeline_job_steps')
            ? PipelineJobStep::query()
                ->selectRaw('stage, status, count(*) as total')
                ->groupBy('stage', 'status')
                ->get()
                ->groupBy('stage')
            : collect();

        $completedRates = $this->completionRates();

        $stages = [];
        foreach ($queueMap as $queueName => $stage) {
            $counts = $byQueue[$queueName] ?? ['pending' => 0, 'running' => 0, 'delayed' => 0, 'total' => 0];
            $stepCounts = $stepsByStage->get($stage, collect());
            $pendingSteps = (int) $stepCounts->whereIn('status', ['queued', 'running'])->sum('total');
            $completedSteps = (int) $stepCounts->where('status', 'completed')->sum('total');
            $failedSteps = (int) $stepCounts->where('status', 'failed')->sum('total');
            $rate = (float) ($completedRates[$stage] ?? 0);
            $pending = max((int) ($counts['pending'] ?? 0), $pendingSteps);
            $processing = (int) ($counts['running'] ?? 0);
            $etaMinutes = $rate > 0 ? round($pending / $rate, 1) : null;

            $stages[$stage] = [
                'queue' => $queueName,
                'pending' => $pending,
                'processing' => $processing,
                'delayed' => (int) ($counts['delayed'] ?? 0),
                'completed' => $completedSteps,
                'failed' => $failedSteps,
                'rate_per_minute' => round($rate, 2),
                'eta_minutes' => $etaMinutes,
            ];
        }

        return $stages;
    }

    /** @param  array<string, array<string, mixed>>  $stages */
    private function aggregateSummary(array $stages): array
    {
        $pending = 0;
        $processing = 0;
        $completed = 0;
        $failed = 0;
        $etaMinutes = 0.0;

        foreach ($stages as $stage) {
            $pending += (int) ($stage['pending'] ?? 0);
            $processing += (int) ($stage['processing'] ?? 0);
            $completed += (int) ($stage['completed'] ?? 0);
            $failed += (int) ($stage['failed'] ?? 0);
            $etaMinutes += (float) ($stage['eta_minutes'] ?? 0);
        }

        $rates = array_filter(array_map(
            static fn (array $stage): float => (float) ($stage['rate_per_minute'] ?? 0),
            $stages,
        ));
        $avgRate = $rates !== [] ? array_sum($rates) / count($rates) : 0.0;

        return [
            'pending' => $pending,
            'processing' => $processing,
            'completed' => $completed,
            'failed' => $failed,
            'rate_per_minute' => round($avgRate, 2),
            'eta_minutes' => $etaMinutes > 0 ? round($etaMinutes, 1) : null,
            'sla_status' => $this->slaStatus()['status'],
        ];
    }

    /** @return array<string, float> */
    private function completionRates(): array
    {
        if (! Schema::hasTable('pipeline_job_steps')) {
            return [];
        }

        $since = now()->subMinutes(5);

        return PipelineJobStep::query()
            ->selectRaw('stage, count(*) as total')
            ->where('status', 'completed')
            ->where('finished_at', '>=', $since)
            ->groupBy('stage')
            ->pluck('total', 'stage')
            ->map(fn (int $count): float => $count / 5)
            ->all();
    }

    /** @return array{status: string, at_risk: int, breached: int} */
    private function slaStatus(): array
    {
        if (! Schema::hasTable('xs2_event_inventory_sync_states')) {
            return ['status' => 'unknown', 'at_risk' => 0, 'breached' => 0];
        }

        $slaHours = (int) config('pipeline.sla_hours_before_event', 48);
        $atRiskCutoff = now()->addHours($slaHours);

        $atRisk = (int) Xs2Event::query()
            ->where('date_start_local', '<=', $atRiskCutoff)
            ->where('date_start_local', '>=', now())
            ->whereHas('inventorySyncState', fn ($state) => $state
                ->where(fn ($q) => $q
                    ->whereNull('publish_status')
                    ->orWhereNotIn('publish_status', ['completed', 'skipped'])))
            ->count();

        $breached = (int) Xs2Event::query()
            ->where('date_start_local', '<', now())
            ->whereHas('inventorySyncState', fn ($state) => $state
                ->where(fn ($q) => $q
                    ->whereNull('reconcile_status')
                    ->orWhereNotIn('reconcile_status', ['completed', 'skipped'])))
            ->count();

        $status = 'healthy';
        if ($breached > 0) {
            $status = 'breached';
        } elseif ($atRisk > 0) {
            $status = 'at_risk';
        }

        return [
            'status' => $status,
            'at_risk' => $atRisk,
            'breached' => $breached,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function stalledEvents(): array
    {
        if (! Schema::hasTable('xs2_event_inventory_sync_states')) {
            return [];
        }

        $stallMinutes = (int) config('pipeline.stall_minutes', 15);
        $cutoff = now()->subMinutes($stallMinutes);

        return Xs2EventInventorySyncState::query()
            ->with('event:id,external_event_id,event_name,date_start_local')
            ->where('updated_at', '<', $cutoff)
            ->where(function ($query): void {
                $query->where('tickets_sync_status', 'running')
                    ->orWhere('listing_gen_status', 'running')
                    ->orWhere('publish_status', 'running')
                    ->orWhere('reconcile_status', 'running');
            })
            ->limit(25)
            ->get()
            ->map(fn (Xs2EventInventorySyncState $state): array => [
                'xs2_event_id' => $state->xs2_event_id,
                'external_event_id' => $state->event?->external_event_id,
                'event_name' => $state->event?->event_name,
                'tickets_sync_status' => $state->tickets_sync_status,
                'listing_gen_status' => $state->listing_gen_status,
                'publish_status' => $state->publish_status,
                'reconcile_status' => $state->reconcile_status,
                'last_pipeline_stage_at' => $state->last_pipeline_stage_at?->toIso8601String(),
                'updated_at' => $state->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function queueBreakdown(): array
    {
        $snapshot = $this->queues->snapshot();
        $known = [
            (string) config('xs2.queue', 'xs2-sync'),
            (string) config('pipeline.listing_gen_queue', 'xs2-listing-gen'),
            (string) config('services.seller_api.queue', 'seller-api'),
            (string) config('pipeline.reconcile_queue', 'xs2-reconcile'),
        ];

        $rows = [];
        foreach ($snapshot['queues'] ?? [] as $row) {
            if (in_array((string) ($row['value'] ?? ''), $known, true)) {
                $rows[] = $row;
            }
        }
        foreach ($snapshot['other_queues'] ?? [] as $row) {
            if (in_array((string) ($row['queue'] ?? ''), $known, true)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function emptySnapshot(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'config' => [],
            'summary' => [
                'pending' => 0,
                'processing' => 0,
                'completed' => 0,
                'failed' => 0,
                'rate_per_minute' => 0,
                'eta_minutes' => null,
                'sla_status' => 'unknown',
            ],
            'stages' => [],
            'sla' => ['status' => 'unknown', 'at_risk' => 0, 'breached' => 0],
            'stalled_events' => [],
            'current_run' => null,
            'last_run' => null,
            'queues' => [],
        ];
    }
}
