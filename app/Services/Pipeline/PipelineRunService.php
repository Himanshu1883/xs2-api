<?php

namespace App\Services\Pipeline;

use App\Models\PipelineRun;
use Illuminate\Support\Str;

class PipelineRunService
{
    public function start(string $trigger, string $mode, int $eventsDue = 0): PipelineRun
    {
        return PipelineRun::query()->create([
            'correlation_id' => (string) Str::uuid(),
            'trigger' => $trigger,
            'mode' => $mode,
            'scheduled_at' => now(),
            'started_at' => now(),
            'events_due' => max(0, $eventsDue),
            'status' => 'running',
        ]);
    }

    public function recordDispatch(PipelineRun $run, int $count): void
    {
        $run->increment('events_dispatched', max(0, $count));
    }

    public function recordCompletion(PipelineRun $run, bool $failed = false): void
    {
        if ($failed) {
            $run->increment('events_failed');
        } else {
            $run->increment('events_completed');
        }

        $this->refreshStatus($run);
    }

    public function finish(PipelineRun $run): PipelineRun
    {
        $run->refresh();
        $this->refreshStatus($run, forceFinish: true);

        return $run->fresh();
    }

    private function refreshStatus(PipelineRun $run, bool $forceFinish = false): void
    {
        $run->refresh();
        $dispatched = (int) $run->events_dispatched;
        $completed = (int) $run->events_completed;
        $failed = (int) $run->events_failed;
        $processed = $completed + $failed;

        $status = 'running';
        if ($forceFinish || ($dispatched > 0 && $processed >= $dispatched)) {
            if ($failed > 0 && $completed > 0) {
                $status = 'partial';
            } elseif ($failed > 0) {
                $status = 'failed';
            } else {
                $status = 'completed';
            }
        }

        $run->update([
            'status' => $status,
            'finished_at' => in_array($status, ['completed', 'partial', 'failed'], true) ? now() : null,
        ]);
    }
}
