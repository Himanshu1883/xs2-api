<?php

namespace App\Console\Commands;

use App\Services\Xs2\SellerPublishResetService;
use Illuminate\Console\Command;

class ResetSellerPublishCommand extends Command
{
    protected $signature = 'xs2:reset-seller-publish
                            {--local-only : Reset local publish state only; do not call the Seller API}
                            {--queue-remote : Queue Seller disable jobs instead of running them synchronously}
                            {--dry-run : Show how many rows would be affected}
                            {--force : Confirm this destructive operation}';

    protected $description = 'Unpublish all XS2 Seller listings and reset local Seatsbroker publish status so inventory can be pushed from scratch.';

    public function handle(SellerPublishResetService $reset): int
    {
        if (! $this->option('force')) {
            $this->error('This unpublishes Seller listings and clears local publish state. Re-run with --force to continue.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $disableRemote = ! $this->option('local-only');
        $syncRemote = ! $this->option('queue-remote');

        if ($dryRun) {
            $this->warn('Dry run only — no Seller API calls and no database changes.');
        } elseif ($disableRemote && $syncRemote) {
            $this->info('Disabling active Seller listings synchronously, then resetting local publish state…');
        } elseif ($disableRemote) {
            $this->info('Queueing Seller listing disable jobs, then resetting local publish state…');
            $this->warn('Run the seller-api queue worker so remote listings are disabled.');
        } else {
            $this->warn('Local-only mode: Seller API listings may remain live until you disable them manually.');
        }

        $summary = $reset->reset($disableRemote, $syncRemote, $dryRun);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Seller listings disabled (remote)', (string) $summary['remote_disabled']],
                ['Remote disable failures (skipped)', (string) $summary['skipped_remote']],
                ['external_listing_mappings reset', (string) $summary['listings_reset']],
                ['xs2_tickets push status cleared', (string) $summary['tickets_reset']],
                ['xs2_ticket_mapping_states re-resolved', (string) $summary['mapping_states_resolved']],
            ],
        );

        if ($dryRun) {
            $this->comment('Dry run complete. Add --force without --dry-run to apply.');
        } else {
            $this->info('Publish state reset. Mapped tickets will queue again on the next inventory sync or manual retry.');
        }

        return self::SUCCESS;
    }
}
