<?php

namespace App\Console\Commands;

use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2Venue;
use App\Services\Xs2\Xs2EventSyncService;
use App\Services\Xs2\Xs2SandboxInventorySyncService;
use App\Services\Xs2\Xs2SandboxService;
use Illuminate\Console\Command;

class ImportXs2SandboxEventCommand extends Command
{
    protected $signature = 'xs2:import-sandbox-event
                            {event_ids?* : One or more XS2 sandbox external event ids (defaults to XS2_SANDBOX_TEST_EVENT_ID when omitted)}
                            {--force : Import even when the event is already stored locally}
                            {--with-inventory : Sync venues and ticket listings from the sandbox API}
                            {--search= : Search sandbox catalog by event_name and import events with available tickets}
                            {--limit=2 : Max events to import when using --search}
                            {--hometeam= : When searching, require hometeam_name to contain this value}';

    protected $description = 'Import XS2 sandbox catalog events into xs2_events and event_mappings without touching production sync credentials.';

    public function handle(
        Xs2SandboxService $sandbox,
        Xs2EventSyncService $eventSync,
        Xs2SandboxInventorySyncService $inventorySync,
    ): int {
        if (! $sandbox->isConfigured()) {
            $this->error('XS2 sandbox credentials are not configured. Set XS2_SANDBOX_API_URL and XS2_SANDBOX_API_KEY in .env or .env.local.');

            return self::FAILURE;
        }

        $eventIds = $this->resolveEventIds($sandbox);
        if ($eventIds === []) {
            $this->error('Provide event_ids, --search, or set XS2_SANDBOX_TEST_EVENT_ID.');

            return self::INVALID;
        }

        $failures = 0;
        $rows = [];

        foreach ($eventIds as $eventId) {
            $result = $this->importOne($sandbox, $eventSync, $inventorySync, $eventId);
            if ($result === null) {
                $failures++;

                continue;
            }

            $rows[] = $result;
        }

        if ($rows !== []) {
            $this->table(
                ['external_event_id', 'event_name', 'venue', 'tickets', 'mapping', 'inventory'],
                $rows,
            );
            $this->info('Imported sandbox events. View them at /admin/xs2/xs2-events — search for "Barcelona".');
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string> */
    private function resolveEventIds(Xs2SandboxService $sandbox): array
    {
        $explicit = array_values(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $this->argument('event_ids') ?? [],
        ), static fn (string $id): bool => $id !== ''));

        if ($explicit !== []) {
            return $explicit;
        }

        $search = trim((string) $this->option('search'));
        if ($search === '') {
            $fallback = trim((string) config('xs2.sandbox.test_event_id', ''));

            return $fallback !== '' ? [$fallback] : [];
        }

        $limit = max(1, (int) $this->option('limit'));
        $filters = ['event_name' => $search, 'sport_type' => 'soccer'];
        $hometeam = trim((string) $this->option('hometeam'));

        $this->info("Searching sandbox catalog for \"{$search}\" with available tickets (limit {$limit})...");

        $discovered = $sandbox->searchEventsWithAvailableTickets($filters, $limit * 3);
        if ($hometeam !== '') {
            $discovered = array_values(array_filter(
                $discovered,
                static fn (array $event): bool => str_contains(
                    strtolower((string) ($event['hometeam_name'] ?? '')),
                    strtolower($hometeam),
                ),
            ));
        }

        $discovered = array_slice($discovered, 0, $limit);
        foreach ($discovered as $event) {
            $this->line(sprintf(
                '  • %s (%s) — %s, %d available ticket(s)',
                $event['external_event_id'],
                $event['event_name'] ?? 'unknown',
                $event['venue_name'] ?? 'unknown venue',
                $event['available_tickets'] ?? 0,
            ));
        }

        return array_map(static fn (array $event): string => $event['external_event_id'], $discovered);
    }

    /** @return list<string>|null */
    private function importOne(
        Xs2SandboxService $sandbox,
        Xs2EventSyncService $eventSync,
        Xs2SandboxInventorySyncService $inventorySync,
        string $eventId,
    ): ?array {
        $existing = Xs2Event::query()->with(['mapping', 'venue'])->where('external_event_id', $eventId)->first();
        if ($existing && ! $this->option('force') && ! $this->option('with-inventory')) {
            $this->line(sprintf(
                'Skipping %s — already stored (xs2_events.id=%d). Pass --force or --with-inventory to refresh.',
                $eventId,
                $existing->id,
            ));

            return $this->summaryRow($existing, 'skipped', null);
        }

        if (! $existing || $this->option('force')) {
            $this->info("Fetching sandbox event {$eventId} from ".config('xs2.sandbox.api_url').' ...');

            try {
                $payload = $sandbox->fetchEventCatalogPayload($eventId);
                $eventSync->syncSingleFromPayload($payload);
            } catch (\Throwable $exception) {
                $this->error(sprintf('%s: %s', $eventId, trim($exception->getMessage()) !== ''
                    ? $exception->getMessage()
                    : 'XS2 sandbox event could not be imported.'));

                return null;
            }
        }

        $event = Xs2Event::query()->with(['mapping', 'venue'])->where('external_event_id', $eventId)->firstOrFail();
        $inventorySummary = null;

        if ($this->option('with-inventory')) {
            $this->info("Syncing sandbox inventory for {$event->event_name} ...");

            try {
                $inventorySummary = $inventorySync->sync($event->fresh(['mapping', 'venue']));
            } catch (\Throwable $exception) {
                $this->error(sprintf('%s inventory: %s', $eventId, $exception->getMessage()));

                return null;
            }
        }

        return $this->summaryRow($event, $existing && ! $this->option('force') ? 'existing' : 'imported', $inventorySummary);
    }

    /** @param array<string, int|string|list<string>|null>|null $inventorySummary @return list<string> */
    private function summaryRow(Xs2Event $event, string $eventAction, ?array $inventorySummary): array
    {
        $venueName = $event->venue_name;
        $venue = Xs2Venue::query()->where('external_venue_id', $event->venue_id)->first();
        if ($venue?->venue_name) {
            $venueName = $venue->venue_name;
        }

        $ticketCount = (string) Xs2Ticket::query()->where('xs2_event_id', $event->id)->count();
        $inventoryLabel = '—';
        if ($inventorySummary !== null) {
            $inventoryLabel = sprintf(
                '%d synced (%d new, %d updated)',
                $inventorySummary['tickets_total'] ?? 0,
                $inventorySummary['tickets_created'] ?? 0,
                $inventorySummary['tickets_updated'] ?? 0,
            );
        } elseif ($this->option('with-inventory')) {
            $inventoryLabel = $ticketCount.' stored';
        }

        return [
            $event->external_event_id,
            $event->event_name,
            (string) $venueName,
            $ticketCount,
            (string) ($event->mapping?->status ?? 'none')." ({$eventAction})",
            $inventoryLabel,
        ];
    }
}
