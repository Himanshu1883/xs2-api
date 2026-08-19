<?php

namespace App\Console\Commands;

use App\Services\Admin\QueueManagementService;
use Illuminate\Console\Command;

class PromoteDelayedQueueJobsCommand extends Command
{
    protected $signature = 'queue:promote-delayed {--queue= : Only promote jobs on this queue name}';

    protected $description = 'Make delayed queue jobs available immediately (useful after large xs2:sync-inventory dispatches).';

    public function handle(QueueManagementService $queues): int
    {
        $queue = $this->option('queue');
        $queue = is_string($queue) && trim($queue) !== '' ? trim($queue) : null;

        try {
            $result = $queues->promoteDelayed($queue);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $label = $queue ?? 'all queues';
        $this->info("Promoted {$result['promoted']} delayed job(s) on {$label}.");

        return self::SUCCESS;
    }
}
