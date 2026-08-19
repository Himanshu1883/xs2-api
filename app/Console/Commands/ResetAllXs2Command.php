<?php

namespace App\Console\Commands;

use App\Services\Xs2\SellerPublishResetService;
use App\Services\Xs2\Xs2ResetService;
use Illuminate\Console\Command;

class ResetAllXs2Command extends Command
{
    protected $signature = 'xs2:reset-all
                            {--skip-remote : Do not disable active Seller listings before wiping local data}
                            {--preserve-orders : Keep sb_orders, xs2_orders, and sandbox test orders}
                            {--clear-cache-locks : Remove ShouldBeUnique cache locks for SyncXs2EventInventory}
                            {--dry-run : Show row counts that would be deleted without making changes}
                            {--force : Confirm this destructive operation}';

    protected $description = 'Wipe all XS2 integration data (events, mappings, tickets, venues, orders) while preserving users, jobs, and legacy match_info catalogue.';

    public function handle(Xs2ResetService $reset, SellerPublishResetService $publishReset): int
    {
        if (! $this->option('force')) {
            $this->error('This deletes all XS2 events, mappings, tickets, and related integration data.');
            $this->error('Legacy match_info and admin users are preserved. Re-run with --force to continue.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run only — no Seller API calls and no database changes.');
        } elseif (! $this->option('skip-remote')) {
            $this->info('Disabling active Seller listings before wiping local XS2 data…');
            $remoteSummary = $publishReset->reset(disableRemote: true, syncRemote: true, dryRun: false);
            $this->table(
                ['Remote action', 'Count'],
                [
                    ['Seller listings disabled', (string) $remoteSummary['remote_disabled']],
                    ['Remote disable failures (skipped)', (string) $remoteSummary['skipped_remote']],
                ],
            );
        } else {
            $this->warn('Skipping remote Seller listing disable — remote listings may remain live.');
        }

        $preserveOrders = (bool) $this->option('preserve-orders');
        $summary = $preserveOrders
            ? $reset->resetCatalog($dryRun, (bool) $this->option('clear-cache-locks'))
            : $reset->reset($dryRun);

        $tables = $preserveOrders
            ? Xs2ResetService::CATALOG_TABLES
            : Xs2ResetService::TABLES;

        $rows = [];
        foreach ($tables as $table) {
            $before = $summary['before'][$table] ?? 0;
            $after = $summary['after'][$table] ?? 0;

            if ($before === 0 && ! $dryRun && $after === 0) {
                continue;
            }

            $rows[] = [$table, (string) $before, (string) $after];
        }

        if ($rows === []) {
            $this->info('No XS2 integration rows found — database is already clean.');
        } else {
            $this->table(['Table', 'Before', 'After'], $rows);
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

        if ($this->option('clear-cache-locks') && $preserveOrders) {
            $this->table(
                ['Cache action', 'Count'],
                [
                    ['cache_locks cleared', (string) ($summary['cache_locks_cleared'] ?? 0)],
                    ['cache_entries cleared', (string) ($summary['cache_entries_cleared'] ?? 0)],
                ],
            );
        }

        if ($dryRun) {
            $this->comment('Dry run complete. Add --force without --dry-run to apply.');
        } elseif ($preserveOrders) {
            $this->info('XS2 catalogue wiped (orders preserved). Run xs2:sync-events to start fresh.');
        } else {
            $this->info('XS2 integration data wiped. Run xs2:sync-events to start fresh.');
        }

        return self::SUCCESS;
    }
}
