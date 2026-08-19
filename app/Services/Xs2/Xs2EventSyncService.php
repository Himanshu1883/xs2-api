<?php

namespace App\Services\Xs2;

use App\Exceptions\Integrations\Xs2ResponseException;
use App\Jobs\ReconcileSellerListingsForMapping;
use App\Models\Xs2Event;
use App\Models\Xs2SyncState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class Xs2EventSyncService
{
    /** @var list<string> */
    private const MAPPING_INPUTS = [
        'event_name',
        'date_start_local',
        'tournament_id',
        'tournament_name',
        'venue_id',
        'venue_name',
        'location_id',
        'city',
        'sport_type',
        'hometeam_id',
        'hometeam_name',
        'visitingteam_id',
        'visitingteam_name',
    ];

    public function __construct(
        private readonly Xs2Client $client,
        private readonly Xs2EventNormalizer $normalizer,
        private readonly EventMappingService $mappingService,
    ) {}

    /** @return array<string, int|string> */
    public function sync(string $sport, bool $fullSync = false): array
    {
        $startedAt = now()->utc();
        $resource = "events:{$sport}";
        $state = Xs2SyncState::query()->firstOrCreate(['resource' => $resource]);
        $summary = [
            'pages_processed' => 0,
            'events_received' => 0,
            'events_created' => 0,
            'events_updated' => 0,
            'events_unchanged' => 0,
            'events_mapped' => 0,
            'events_pending' => 0,
            'local_events_created' => 0,
            'events_missing' => 0,
            'failed_events' => 0,
            'started_at' => $startedAt->toIso8601String(),
            'completed_at' => '',
        ];

        $state->update([
            'status' => 'running',
            'last_attempted_at' => $startedAt,
            'last_error' => null,
        ]);

        try {
            $seenExternalIds = [];
            $firstEventFailure = null;
            for ($page = 1, $hasNext = true; $hasNext; $page++) {
                $query = [
                    'sport_type' => $sport,
                    'page_size' => config('services.xs2.page_size', 100),
                    'page' => $page,
                ];

                if (! $fullSync && $state->last_successful_at) {
                    $query['updated'] = 'ge:'.$state->last_successful_at
                        ->copy()->subMinutes(config('services.xs2.sync_overlap_minutes', 5))
                        ->format('Y-m-d H:i:s');
                }

                $response = $this->client->getEvents($query);
                $summary['pages_processed']++;
                $events = $response['events'] ?? null;
                if (! is_array($events)) {
                    throw new Xs2ResponseException('XS2 events response has an unexpected collection structure.');
                }

                foreach ($events as $payload) {
                    $summary['events_received']++;

                    try {
                        if (! is_array($payload) || ! is_scalar($payload['event_id'] ?? null)) {
                            throw new Xs2ResponseException('XS2 events response contains an invalid event item.');
                        }
                        $seenExternalIds[] = (string) $payload['event_id'];
                        // Keep the supplier record and its mapping atomic. If
                        // mapping fails after the event was saved, a retry must
                        // still see the source changes and evaluate them again.
                        $result = DB::transaction(fn (): array => $this->saveAndMap($payload));
                        $summary[$result['event_action']]++;
                        if ($result['mapping_action'] !== 'none') {
                            $summary[$result['mapping_action']]++;
                        }
                    } catch (Throwable $exception) {
                        $summary['failed_events']++;
                        $firstEventFailure ??= $exception;
                        Log::warning('XS2 event could not be processed.', [
                            'external_event_id' => is_array($payload) ? ($payload['event_id'] ?? null) : null,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }

                $nextPage = data_get($response, 'pagination.next_page', data_get($response, 'next_page'));
                $totalPages = data_get($response, 'pagination.total_pages', data_get($response, 'total_pages'));
                $hasNext = filled($nextPage) || (is_numeric($totalPages) && $page < (int) $totalPages);
                if ($events === [] && $hasNext) {
                    throw new Xs2ResponseException('XS2 event pagination returned an empty page before the collection was complete.');
                }
            }

            // Process the rest of the snapshot for useful forward progress,
            // but never treat a partial import as authoritative. Keeping the
            // old checkpoint makes every failed item eligible on the retry.
            if ($firstEventFailure !== null) {
                throw $firstEventFailure;
            }

            if ($fullSync) {
                $summary['events_missing'] = $this->markMissingEvents($sport, $seenExternalIds);
            }

            $summary['completed_at'] = now()->utc()->toIso8601String();
            $state->update([
                // The starting checkpoint prevents gaps when processing takes time.
                'last_successful_at' => $startedAt,
                'status' => 'completed',
                'last_error' => null,
                'metadata' => $summary,
            ]);

            return $summary;
        } catch (Throwable $exception) {
            $summary['completed_at'] = now()->utc()->toIso8601String();
            $state->update([
                'status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 5000),
                'metadata' => $summary,
            ]);

            throw $exception;
        }
    }

    /**
     * Import one XS2 event payload into xs2_events and run mapping.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     external_event_id:string,
     *     xs2_event_id:int,
     *     mapping_id:int|null,
     *     mapping_status:string|null,
     *     event_action:string,
     *     mapping_action:string
     * }
     */
    public function syncSingleFromPayload(array $payload): array
    {
        if (! is_scalar($payload['event_id'] ?? null)) {
            throw new \InvalidArgumentException('XS2 event payload is missing event_id.');
        }

        $result = $this->saveAndMap($payload);
        $event = Xs2Event::query()->with('mapping')->where('external_event_id', (string) $payload['event_id'])->firstOrFail();

        return [
            'external_event_id' => (string) $payload['event_id'],
            'xs2_event_id' => (int) $event->id,
            'mapping_id' => $event->mapping?->id,
            'mapping_status' => $event->mapping?->status,
            'event_action' => $result['event_action'],
            'mapping_action' => $result['mapping_action'],
        ];
    }

    /** @param list<string> $seenExternalIds */
    private function markMissingEvents(string $sport, array $seenExternalIds): int
    {
        $query = Xs2Event::query()
            ->with('mapping')
            ->where('sport_type', $sport)
            ->whereNull('missing_since');
        if ($seenExternalIds !== []) {
            $query->whereNotIn('external_event_id', array_values(array_unique($seenExternalIds)));
        }

        $missingAt = now();

        return DB::transaction(function () use ($query, $missingAt): int {
            $count = 0;
            foreach ($query->get() as $event) {
                $event->update(['missing_since' => $missingAt]);
                if ($event->mapping) {
                    ReconcileSellerListingsForMapping::dispatch($event->mapping->id)->afterCommit();
                }
                $count++;
            }

            return $count;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{event_action: string, mapping_action: string}
     */
    private function saveAndMap(array $payload): array
    {
        $attributes = $this->normalizer->normalize($payload);
        $event = Xs2Event::query()->firstOrNew(['external_event_id' => $attributes['external_event_id']]);
        $wasNew = ! $event->exists;
        $event->fill($attributes);
        $dirty = array_diff_key($event->getDirty(), array_flip(['last_synced_at', 'last_seen_at'])) !== [];
        $mappingInputsChanged = $event->isDirty(self::MAPPING_INPUTS);
        $sellabilityChanged = $event->isDirty(['date_start_local', 'event_status', 'missing_since']);
        $event->save();
        $mapping = $event->mapping;
        if ($wasNew || $mappingInputsChanged || ! $mapping || $mapping->status === 'pending') {
            $mapping = $this->mappingService->map($event);
        }

        // Status and timing updates do not necessarily alter the mapping, but
        // they do alter whether its Seller listings may remain published.
        if (! $wasNew && $sellabilityChanged && $mapping) {
            ReconcileSellerListingsForMapping::dispatch($mapping->id)->afterCommit();
        }

        return [
            'event_action' => $wasNew ? 'events_created' : ($dirty ? 'events_updated' : 'events_unchanged'),
            'mapping_action' => match ($mapping->status) {
                'mapped' => 'events_mapped',
                'pending' => 'events_pending',
                'created' => 'local_events_created',
                default => 'none',
            },
        ];
    }
}
