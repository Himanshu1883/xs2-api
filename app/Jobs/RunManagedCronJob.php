<?php

namespace App\Jobs;

use App\Services\Admin\CronJobManagementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunManagedCronJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $cronJobId) {}

    public function handle(CronJobManagementService $cronJobs): void
    {
        $cronJobs->runQueued($this->cronJobId);
    }
}
