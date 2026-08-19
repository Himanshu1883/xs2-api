<?php

namespace App\Console\Commands;

use App\Services\SplitListings\SplitListingQuantitySyncService;
use Illuminate\Console\Command;

class SyncSplitListingQuantitiesCommand extends Command
{
    protected $signature = 'xs2:sync-split-listing-quantities
                            {--sync : Run SplitListingService inline instead of queueing SyncSplitListings jobs}
                            {--ticket= : Limit to one XS2 ticket id}
                            {--force : Reconcile every eligible ticket even when the local plan already matches}';

    protected $description = 'Reconcile Seats Broker split sublisting quantities with current XS2 master stock.';

    public function handle(SplitListingQuantitySyncService $sync): int
    {
        if (! (bool) config('xs2.sb_listing_inventory.enabled', true)) {
            $this->warn('Split listing quantity sync is disabled (XS2_SB_LISTING_INVENTORY_SYNC_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! (bool) config('services.seller_api.enabled', true)) {
            $this->warn('Seller API integration is disabled (SELLER_API_ENABLED=false).');

            return self::SUCCESS;
        }

        $ticketId = filled($this->option('ticket')) ? (int) $this->option('ticket') : null;

        $this->info('Scanning split-enabled XS2 tickets with active Seats Broker sublistings...');

        $summary = $sync->run(
            inline: (bool) $this->option('sync'),
            ticketId: $ticketId,
            force: (bool) $this->option('force'),
        );

        $this->table(
            ['Metric', 'Value'],
            collect($summary)
                ->except(['errors'])
                ->map(fn (mixed $value, string $key): array => [$key, is_array($value) ? json_encode($value) : (string) $value])
                ->values()
                ->all(),
        );

        foreach ($summary['errors'] ?? [] as $error) {
            $this->error((string) $error);
        }

        return ($summary['status'] ?? 'completed') === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
