<?php

namespace App\Console\Commands;

use App\Services\SellerApi\SellerApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class FetchSellerApiEventsCommand extends Command
{
    protected $signature = 'seller-api:fetch-events
                            {--environment= : sandbox or production (defaults to catalog base URL environment)}
                            {--per-page= : Page size query param (API may cap this)}
                            {--save : Write full JSON to storage/app/exports}
                            {--json : Print full JSON to stdout instead of a summary}';

    protected $description = 'Fetch all Seatsbrokers external catalog events (GET /api/events, Bearer). No database writes.';

    public function handle(SellerApiClient $client): int
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
        $this->info("Fetching events from Seatsbrokers {$label} (read-only, no DB)...");

        try {
            $events = $client->fetchAllEvents($perPage, $environment);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            if ($environment === 'production') {
                $this->line('Set SELLER_API_CATALOG_PRODUCTION_API_KEY in .env.local or Admin → API Config → Get events → Production API token.');
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $summary = [
            'event_count' => count($events),
            'sample_match_names' => array_values(array_filter(array_map(
                static fn (array $event): ?string => isset($event['match_name']) ? (string) $event['match_name'] : null,
                array_slice($events, 0, 5),
            ))),
        ];

        if ($this->option('save')) {
            $suffix = $environment ? "-{$environment}" : '';
            $path = 'exports/seller-api-events'.$suffix.'-'.now()->format('Ymd-His').'.json';
            Storage::disk('local')->put($path, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $summary['export_path'] = storage_path('app/'.$path);
        }

        $this->table(array_keys($summary), [array_map(
            static fn ($value): string => is_array($value) ? implode(', ', $value) : (string) $value,
            array_values($summary),
        )]);

        if (! $this->option('save')) {
            $this->comment('Use --save to write storage/app/exports/seller-api-events-<timestamp>.json');
        }

        return self::SUCCESS;
    }
}
