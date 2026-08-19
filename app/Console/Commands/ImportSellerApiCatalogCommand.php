<?php

namespace App\Console\Commands;

use App\Services\SellerApi\SellerApiClient;
use App\Services\SellerApi\SellerEventImportService;
use App\Services\SellerApi\SellerVenueCatalogSyncService;
use Illuminate\Console\Command;

class ImportSellerApiCatalogCommand extends Command
{
    protected $signature = 'seller-api:import-catalog
                            {--environment=production : sandbox or production}
                            {--tournament-id= : Optional local tournament id; omit to import the full catalog}
                            {--venues : Sync the full venue catalog (blocks/sections) before importing events}
                            {--skip-venues : Skip the venue catalog sync}
                            {--per-page= : Page size for catalog pagination}';

    protected $description = 'Import Seats Broker catalog events into match_info and optionally sync venue blocks/sections from production or sandbox.';

    public function handle(
        SellerApiClient $client,
        SellerEventImportService $import,
        SellerVenueCatalogSyncService $venues,
    ): int {
        $environment = strtolower(trim((string) $this->option('environment')));
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            $this->error('The --environment option must be sandbox or production.');

            return self::INVALID;
        }

        $perPage = $this->option('per-page');
        $perPage = is_string($perPage) && $perPage !== '' ? (int) $perPage : null;
        $tournamentId = $this->option('tournament-id');
        $tournamentId = is_string($tournamentId) && $tournamentId !== '' ? (int) $tournamentId : null;

        $this->info("Probing Seats Broker {$environment} catalog API...");
        try {
            $client->fetchEventsPage(1, 1, [], $environment);
        } catch (\Throwable $exception) {
            $this->error("Catalog API probe failed: {$exception->getMessage()}");
            if ($environment === 'production') {
                $this->line('Set a valid production Bearer token via Admin → API Config → Get events → Production API token,');
                $this->line('or add SELLER_API_CATALOG_PRODUCTION_API_KEY to .env.local and run php artisan config:clear.');
            }

            return self::FAILURE;
        }

        $this->info('Catalog API is reachable.');

        if ($this->option('venues') || ! $this->option('skip-venues')) {
            $this->info("Syncing venue catalog (stadium / stadium_seats / stadium_details) from {$environment}...");
            $venueSummary = $venues->sync($perPage, $environment);
            $this->table(array_keys($venueSummary), [array_map(strval(...), array_values($venueSummary))]);
        }

        $this->info($tournamentId !== null
            ? "Importing events for tournament #{$tournamentId}..."
            : 'Importing full event catalog...');

        $onProgress = function (array $summary, int $page, int $lastPage): void {
            $this->output->write("\r  Page {$page}/{$lastPage} — fetched {$summary['fetched']}, created {$summary['created']}, skipped {$summary['skipped']}, failed {$summary['failed']}");
        };

        $summary = $tournamentId !== null
            ? $import->syncByTournament($tournamentId, $environment, $onProgress)
            : $import->syncAll($environment, $onProgress);

        $this->newLine(2);
        $this->table(
            ['Metric', 'Count'],
            collect($summary)
                ->only(['fetched', 'created', 'skipped', 'failed'])
                ->map(fn ($value, $key): array => [$key, (string) $value])
                ->values()
                ->all(),
        );

        if (($summary['errors'] ?? []) !== []) {
            $this->warn('Some events failed to import:');
            foreach (array_slice($summary['errors'], 0, 10) as $error) {
                $this->line('- '.($error['event_id'] ?: 'unknown').': '.($error['message'] ?? 'Unknown error'));
            }
            if (count($summary['errors']) > 10) {
                $this->line('... and '.(count($summary['errors']) - 10).' more.');
            }
        }

        if ((int) ($summary['failed'] ?? 0) > 0 && (int) ($summary['created'] ?? 0) === 0 && (int) ($summary['skipped'] ?? 0) === 0) {
            return self::FAILURE;
        }

        $this->info('Catalog import completed.');

        return self::SUCCESS;
    }
}
