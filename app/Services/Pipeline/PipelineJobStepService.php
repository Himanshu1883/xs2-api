<?php

namespace App\Services\Pipeline;

use App\Models\PipelineJobStep;
use App\Models\PipelineRun;
use App\Models\Xs2EventInventorySyncState;
use Illuminate\Support\Facades\Schema;

class PipelineJobStepService
{
    public function queue(
        PipelineRun $run,
        int $xs2EventId,
        string $stage,
        string $jobClass,
        ?int $laravelJobId = null,
    ): PipelineJobStep {
        $step = PipelineJobStep::query()->updateOrCreate(
            [
                'pipeline_run_id' => $run->id,
                'xs2_event_id' => $xs2EventId,
                'stage' => $stage,
            ],
            [
                'status' => 'queued',
                'job_class' => $jobClass,
                'laravel_job_id' => $laravelJobId,
                'error_message' => null,
            ],
        );

        $this->touchEventStage($xs2EventId, $run, $stage, 'queued');

        return $step;
    }

    public function start(PipelineJobStep $step): PipelineJobStep
    {
        $step->update([
            'status' => 'running',
            'started_at' => now(),
            'attempts' => (int) $step->attempts + 1,
        ]);

        $this->touchEventStage(
            (int) $step->xs2_event_id,
            $step->pipelineRun,
            (string) $step->stage,
            'running',
        );

        return $step->fresh();
    }

    public function complete(PipelineJobStep $step, ?int $durationMs = null): PipelineJobStep
    {
        $startedAt = $step->started_at ?? now();
        $step->update([
            'status' => 'completed',
            'finished_at' => now(),
            'duration_ms' => $durationMs ?? (int) $startedAt->diffInMilliseconds(now()),
            'error_message' => null,
        ]);

        $this->touchEventStage(
            (int) $step->xs2_event_id,
            $step->pipelineRun,
            (string) $step->stage,
            'completed',
        );

        $this->maybeRecordRunCompletion($step, failed: false);

        return $step->fresh();
    }

    public function fail(PipelineJobStep $step, string $error): PipelineJobStep
    {
        $startedAt = $step->started_at ?? now();
        $step->update([
            'status' => 'failed',
            'finished_at' => now(),
            'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
            'error_message' => mb_substr($error, 0, 5000),
        ]);

        $this->touchEventStage(
            (int) $step->xs2_event_id,
            $step->pipelineRun,
            (string) $step->stage,
            'failed',
        );

        $this->maybeRecordRunCompletion($step, failed: true);

        return $step->fresh();
    }

    public function skip(PipelineRun $run, int $xs2EventId, string $stage, string $reason = ''): PipelineJobStep
    {
        $step = PipelineJobStep::query()->updateOrCreate(
            [
                'pipeline_run_id' => $run->id,
                'xs2_event_id' => $xs2EventId,
                'stage' => $stage,
            ],
            [
                'status' => 'skipped',
                'finished_at' => now(),
                'error_message' => $reason !== '' ? mb_substr($reason, 0, 5000) : null,
            ],
        );

        $this->touchEventStage($xs2EventId, $run, $stage, 'skipped');

        return $step;
    }

    public function findOrCreateStep(PipelineRun $run, int $xs2EventId, string $stage, string $jobClass): PipelineJobStep
    {
        return PipelineJobStep::query()->firstOrCreate(
            [
                'pipeline_run_id' => $run->id,
                'xs2_event_id' => $xs2EventId,
                'stage' => $stage,
            ],
            [
                'status' => 'queued',
                'job_class' => $jobClass,
            ],
        );
    }

    private function touchEventStage(int $xs2EventId, ?PipelineRun $run, string $stage, string $status): void
    {
        if (! Schema::hasTable('xs2_event_inventory_sync_states')) {
            return;
        }

        $column = match ($stage) {
            PipelineJobStep::STAGE_LISTING_GEN => 'listing_gen_status',
            PipelineJobStep::STAGE_PUBLISH => 'publish_status',
            PipelineJobStep::STAGE_RECONCILE => 'reconcile_status',
            default => null,
        };

        $payload = [
            'last_pipeline_stage_at' => now(),
        ];

        if ($run !== null) {
            $payload['pipeline_run_id'] = $run->id;
            $payload['pipeline_correlation_id'] = $run->correlation_id;
        }

        if ($column !== null) {
            $payload[$column] = $status;
        }

        Xs2EventInventorySyncState::query()->updateOrCreate(
            ['xs2_event_id' => $xs2EventId],
            $payload,
        );
    }

    private function maybeRecordRunCompletion(PipelineJobStep $step, bool $failed): void
    {
        if ($step->stage !== PipelineJobStep::STAGE_RECONCILE) {
            return;
        }

        if ($step->pipelineRun instanceof PipelineRun) {
            app(PipelineRunService::class)->recordCompletion($step->pipelineRun, failed: $failed);
        }
    }
}
