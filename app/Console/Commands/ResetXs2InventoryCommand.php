<?php

namespace App\Console\Commands;

use App\Services\Admin\QueueManagementService;
use App\Services\Xs2\Xs2ResetService;
use Illuminate\Console\Command;

class ResetXs2InventoryCommand extends Command
{
    protected $signature = 'xs2:reset-inventory
                            {--dry-run : Show row counts that would be deleted without making changes}
                            {--force : Confirm this destructive operation}
                            {--clear-queues : Delete all pending, delayed, and running jobs from the jobs table}
                            {--clear-cache-locks : Remove ShouldBeUnique cache locks for SyncXs2EventInventory}';

    protected $description = 'Wipe XS2 inventory data (tickets, categories, mappings, listings) while preserving events, event mappings, venues, and orders.';

    public function handle(Xs2ResetService $reset, QueueManagementService $queues): int
    {
        if (! $this->option('force')) {
            $this->error('This deletes all XS2 tickets, categories, listing mappings, and sync state.');
            $this->error('xs2_events, event_mappings, xs2_venues, and sb_orders are preserved. Re-run with --force to continue.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run only — no database changes.');
        }

        $queueSummary = null;
        if ($this->option('clear-queues')) {
            if ($dryRun) {
                $snapshot = $queues->snapshot();
                $queueSummary = [
                    'jobs_deleted' => $snapshot['totals']['total'] ?? 0,
                    'failed_deleted' => $snapshot['totals']['failed'] ?? 0,
                    'workers_restarted' => false,
                    'queue' => null,
                ];
            } else {
                $queueSummary = $queues->stopAll();
            }

            $this->table(
                ['Queue action', 'Count'],
                [
                    ['Jobs deleted', (string) ($queueSummary['jobs_deleted'] ?? 0)],
                    ['Failed jobs deleted', (string) ($queueSummary['failed_deleted'] ?? 0)],
                    ['Workers restarted', ($queueSummary['workers_restarted'] ?? false) ? 'yes' : 'no'],
                ],
            );
        }

        $summary = $reset->resetInventory($dryRun, (bool) $this->option('clear-cache-locks'));

        $rows = [];
        foreach (Xs2ResetService::INVENTORY_TABLES as $table) {
            $before = $summary['before'][$table] ?? 0;
            $after = $summary['after'][$table] ?? 0;

            if ($before === 0 && ! $dryRun && $after === 0) {
                continue;
            }

            $rows[] = [$table, (string) $before, (string) $after];
        }

        if ($rows === []) {
            $this->info('No XS2 inventory rows found — inventory tables are already clean.');
        } else {
            $this->table(['Table', 'Before', 'After'], $rows);
        }

        if ($this->option('clear-cache-locks')) {
            $this->table(
                ['Cache action', 'Count'],
                [
                    ['cache_locks cleared', (string) ($summary['cache_locks_cleared'] ?? 0)],
                    ['cache entries cleared', (string) ($summary['cache_entries_cleared'] ?? 0)],
                ],
            );
        }

        $preservedRows = [];
        foreach ($summary['preserved'] as $table => $count) {
            if ($count > 0) {
                $preservedRows[] = [$table, (string) $count];
            }
        }

        if ($preservedRows !== []) {
            $this->newLine();
            $this->comment('Preserved (unchanged):');
            $this->table(['Table', 'Rows'], $preservedRows);
        }

        if ($dryRun) {
            $this->comment('Dry run complete. Add --force without --dry-run to apply.');
        } else {
            $this->info('XS2 inventory wiped. Run xs2:sync-inventory --mode=full to re-sync.');
        }

        return self::SUCCESS;
    }
}
