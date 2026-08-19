<?php

namespace App\Console\Commands;

use App\Services\SellerApi\SellerVenueCatalogSyncService;
use Illuminate\Console\Command;

class SyncSellerApiVenuesCommand extends Command
{
    protected $signature = 'seller-api:sync-venues
                            {--per-page= : Page size query param (API may cap this)}
                            {--environment= : sandbox or production (defaults to catalog base URL environment)}';

    protected $description = 'Fetch Seatsbrokers GET /api/venues and persist venues, seat categories, and sections into stadium / stadium_seats / stadium_details.';

    public function handle(SellerVenueCatalogSyncService $sync): int
    {
        $perPage = $this->option('per-page');
        $perPage = is_string($perPage) && $perPage !== '' ? (int) $perPage : null;
        $environment = $this->option('environment');
        $environment = is_string($environment) && $environment !== '' ? strtolower(trim($environment)) : null;

        if ($environment !== null && ! in_array($environment, ['sandbox', 'production'], true)) {
            $this->error('The --environment option must be sandbox or production.');

            return self::INVALID;
        }

        $label = $environment ?? 'default catalog host';
        $this->info("Syncing Seatsbrokers venues ({$label}) into the local database...");

        $summary = $sync->sync($perPage, $environment);

        $this->table(array_keys($summary), [array_map(strval(...), array_values($summary))]);

        return self::SUCCESS;
    }
}
