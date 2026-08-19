<?php

namespace App\Console\Commands;

use App\Models\Xs2Event;
use App\Models\Xs2Venue;
use App\Services\Xs2\Xs2VenueSyncService;
use Illuminate\Console\Command;

class BackfillXs2VenuesCommand extends Command
{
    protected $signature = 'xs2:backfill-venues
                            {--venue-id= : Only backfill this external venue ID}
                            {--dry-run : Report the affected venues without writing changes}';

    protected $description = 'Backfill XS2 venues from stored XS2 events without calling the XS2 API.';

    public function handle(Xs2VenueSyncService $venues): int
    {
        $query = Xs2Event::query()->orderByDesc('external_updated_at')->orderByDesc('id');
        if (filled($this->option('venue-id'))) {
            $query->where('venue_id', (string) $this->option('venue-id'));
        }

        /** @var array<string, array<string, mixed>> $payloads */
        $payloads = [];
        $skipped = 0;
        foreach ($query->get() as $event) {
            $payload = $this->payloadFor($event);
            if ($payload === null) {
                $skipped++;

                continue;
            }

            $venueId = (string) $payload['venue_id'];
            $payloads[$venueId] = isset($payloads[$venueId])
                ? $this->fillMissing($payloads[$venueId], $payload)
                : $payload;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: no XS2 venue records were changed.');
            $this->table(['Venues', 'Events skipped'], [[count($payloads), $skipped]]);

            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;
        $failed = 0;
        foreach ($payloads as $venueId => $payload) {
            try {
                // Intentionally use syncPayload() rather than syncForEvent(): all
                // data comes from our database and this command must not call XS2.
                $result = $venues->syncPayload($this->withStoredDetails($venueId, $payload));
                $created += (int) $result['created'];
                $updated += (int) $result['updated'];
            } catch (\Throwable $exception) {
                $failed++;
                $this->warn("Could not backfill XS2 venue {$venueId}: {$exception->getMessage()}");
            }
        }

        $this->table(['Processed', 'Created', 'Updated', 'Events skipped', 'Failed'], [[count($payloads), $created, $updated, $skipped, $failed]]);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed>|null */
    private function payloadFor(Xs2Event $event): ?array
    {
        $source = is_array($event->raw_payload) ? $event->raw_payload : [];
        $externalVenueId = $event->venue_id ?? data_get($source, 'venue_id');
        if (! is_scalar($externalVenueId) || trim((string) $externalVenueId) === '') {
            return null;
        }

        $embedded = data_get($source, 'venue');
        $payload = is_array($embedded) ? $embedded : [];
        $payload['venue_id'] = (string) $externalVenueId;

        $this->fillWhenMissing($payload, 'venue_name', $event->venue_name);
        $this->fillWhenMissing($payload, 'city', $event->city);
        $this->fillWhenMissing($payload, 'country_code', $event->iso_country);
        $this->fillWhenMissing($payload, 'latitude', $event->latitude);
        $this->fillWhenMissing($payload, 'longitude', $event->longitude);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function fillWhenMissing(array &$payload, string $key, mixed $value): void
    {
        if ((! array_key_exists($key, $payload) || blank($payload[$key])) && filled($value)) {
            $payload[$key] = $value;
        }
    }

    /** @param array<string, mixed> $preferred @param array<string, mixed> $fallback @return array<string, mixed> */
    private function fillMissing(array $preferred, array $fallback): array
    {
        foreach ($fallback as $key => $value) {
            $this->fillWhenMissing($preferred, $key, $value);
        }

        return $preferred;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withStoredDetails(string $venueId, array $payload): array
    {
        $venue = Xs2Venue::query()->where('external_venue_id', $venueId)->first();
        if (! $venue) {
            return $payload;
        }

        // Retain fields that only the previous venue-detail response supplied.
        // A backfill has event-level data and must not erase useful address,
        // postal, or source payload details while it repairs the map fields.
        $stored = is_array($venue->raw_payload) ? $venue->raw_payload : [];
        $stored['venue_id'] ??= $venueId;
        foreach ([
            'venue_name' => $venue->venue_name,
            'slug' => $venue->slug,
            'address' => $venue->address,
            'postal_code' => $venue->postal_code,
            'city_name' => $venue->city_name,
            'country_name' => $venue->country_name,
            'country_code' => $venue->country_code,
            'latitude' => $venue->latitude,
            'longitude' => $venue->longitude,
        ] as $key => $value) {
            $this->fillWhenMissing($stored, $key, $value);
        }

        return $this->fillMissing($payload, $stored);
    }
}
