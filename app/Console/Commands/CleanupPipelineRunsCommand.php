<?php

namespace App\Console\Commands;

use App\Models\PipelineJobStep;
use App\Models\PipelineRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CleanupPipelineRunsCommand extends Command
{
    protected $signature = 'pipeline:cleanup
        {--days= : Retention window in days (defaults to PIPELINE_RETENTION_DAYS)}
        {--dry-run : Show counts without deleting}';

    protected $description = 'Delete pipeline runs and job steps older than the retention window.';

    public function handle(): int
    {
        if (! Schema::hasTable('pipeline_runs')) {
            $this->warn('Pipeline tables are not available.');

            return self::SUCCESS;
        }

        $days = max(1, (int) ($this->option('days') ?: config('pipeline.retention_days', 90)));
        $cutoff = now()->subDays($days);

        $runQuery = PipelineRun::query()->where('created_at', '<', $cutoff);
        $runCount = (int) $runQuery->count();
        $stepCount = Schema::hasTable('pipeline_job_steps')
            ? (int) PipelineJobStep::query()->where('created_at', '<', $cutoff)->count()
            : 0;

        if ((bool) $this->option('dry-run')) {
            $this->info("Would delete {$runCount} pipeline run(s) and {$stepCount} step(s) older than {$days} day(s).");

            return self::SUCCESS;
        }

        if ($runCount === 0) {
            $this->info('No pipeline records to delete.');

            return self::SUCCESS;
        }

        PipelineRun::query()->where('created_at', '<', $cutoff)->delete();
        $this->info("Deleted {$runCount} pipeline run(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
