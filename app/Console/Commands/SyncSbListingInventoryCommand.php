<?php

namespace App\Console\Commands;

use App\Services\SellerApi\SbListingInventorySyncService;
use Illuminate\Console\Command;

class SyncSbListingInventoryCommand extends Command
{
    protected $signature = 'xs2:sync-sb-listing-inventory
                            {--sync : Run Seller API jobs inline instead of queueing}
                            {--ticket= : Limit to one XS2 ticket id}
                            {--force : Reconcile every eligible listing even when local state already matches}';

    protected $description = 'Reconcile qty on existing Seats Broker listings with XS2 stock (skips tickets not yet published on SB).';

    public function handle(SbListingInventorySyncService $sync): int
    {
        if (! (bool) config('xs2.sb_listing_inventory.enabled', true)) {
            $this->warn('Seats Broker listing inventory sync is disabled (XS2_SB_LISTING_INVENTORY_SYNC_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! (bool) config('services.seller_api.enabled', true)) {
            $this->warn('Seller API integration is disabled (SELLER_API_ENABLED=false).');

            return self::SUCCESS;
        }

        $ticketId = filled($this->option('ticket')) ? (int) $this->option('ticket') : null;

        $this->info('Scanning published XS2 listings on Seats Broker for quantity drift...');

        $summary = $sync->run(
            inline: (bool) $this->option('sync'),
            ticketId: $ticketId,
            force: (bool) $this->option('force'),
        );

        $this->table(
            ['Metric', 'Value'],
            collect($summary)
                ->except(['errors', 'masters', 'splits'])
                ->map(fn (mixed $value, string $key): array => [$key, is_array($value) ? json_encode($value) : (string) $value])
                ->values()
                ->all(),
        );

        foreach (['masters', 'splits'] as $section) {
            if (! is_array($summary[$section] ?? null)) {
                continue;
            }

            $this->newLine();
            $this->info(ucfirst($section).' breakdown:');
            $this->table(
                ['Metric', 'Value'],
                collect($summary[$section])
                    ->except(['errors'])
                    ->map(fn (mixed $value, string $key): array => [$key, is_array($value) ? json_encode($value) : (string) $value])
                    ->values()
                    ->all(),
            );
        }

        foreach ($summary['errors'] ?? [] as $error) {
            $this->error((string) $error);
        }

        return ($summary['status'] ?? 'completed') === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
