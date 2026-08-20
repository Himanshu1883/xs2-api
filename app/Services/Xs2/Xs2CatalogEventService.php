<?php

namespace App\Services\Xs2;

use App\Models\Xs2Event;
use Illuminate\Support\Facades\DB;

class Xs2CatalogEventService
{
    public function __construct(
        private readonly Xs2Client $client,
        private readonly Xs2EventSyncService $eventSync,
    ) {}

    /**
     * @return array{
     *     sport:string,
     *     request_url:string,
     *     events:list<array<string, mixed>>,
     *     pagination:array{current_page:int,last_page:int,per_page:int,total:int}
     * }
     */
    public function search(
        string $sport,
        ?string $tournamentName,
        ?string $search,
        int $page = 1,
        int $perPage = 20,
    ): array {
        $sport = trim($sport) !== '' ? trim($sport) : $this->defaultSport();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $query = [
            'sport_type' => $sport,
            'page' => $page,
            'page_size' => $perPage,
        ];

        if ($tournamentName !== null && trim($tournamentName) !== '') {
            $query['tournament_name'] = trim($tournamentName);
        }

        if ($search !== null && trim($search) !== '') {
            $query['event_name'] = trim($search);
        }

        $response = $this->client->getEvents($query);
        $events = $response['events'] ?? null;
        if (! is_array($events)) {
            throw new \RuntimeException('XS2 events response has an unexpected collection structure.');
        }

        $mappedEvents = array_map(
            fn (array $event): array => $this->mapCatalogEvent($event),
            array_values(array_filter($events, is_array(...))),
        );

        return [
            'sport' => $sport,
            'request_url' => $this->client->previewEventsRequestUrl($query),
            'events' => $mappedEvents,
            'pagination' => $this->resolvePagination($response, $page, $perPage, count($mappedEvents)),
        ];
    }

    /**
     * @return array{sport:string,request_url:string,default_sport:string}
     */
    public function preview(string $sport, ?string $tournamentName = null, ?string $search = null, int $page = 1, int $perPage = 20): array
    {
        $sport = trim($sport) !== '' ? trim($sport) : $this->defaultSport();
        $page = max(1, $page);
        $query = [
            'sport_type' => $sport,
            'page' => $page,
            'page_size' => max(1, min(100, $perPage)),
        ];

        if ($tournamentName !== null && trim($tournamentName) !== '') {
            $query['tournament_name'] = trim($tournamentName);
        }

        if ($search !== null && trim($search) !== '') {
            $query['event_name'] = trim($search);
        }

        return [
            'sport' => $sport,
            'request_url' => $this->client->previewEventsRequestUrl($query),
            'default_sport' => $this->defaultSport(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    public function syncEvent(string $externalEventId, ?array $payload = null): array
    {
        $externalEventId = trim($externalEventId);
        if ($externalEventId === '') {
            throw new \InvalidArgumentException('XS2 external event id is required.');
        }

        if ($payload === null || ($payload['event_id'] ?? null) !== $externalEventId) {
            $response = $this->client->getEvent($externalEventId);
            $payload = is_array($response['event'] ?? null)
                ? $response['event']
                : (is_array($response) && isset($response['event_id']) ? $response : null);

            if (! is_array($payload) || trim((string) ($payload['event_id'] ?? '')) === '') {
                throw new \InvalidArgumentException('XS2 event was not found in the catalog.');
            }
        }

        return DB::transaction(fn (): array => $this->eventSync->syncSingleFromPayload($payload));
    }

    /** @param array<string, mixed> $event */
    private function mapCatalogEvent(array $event): array
    {
        $externalId = trim((string) ($event['event_id'] ?? ''));
        $existing = $externalId === ''
            ? null
            : Xs2Event::query()->with('mapping')->where('external_event_id', $externalId)->first();

        return [
            'external_event_id' => $externalId,
            'event_name' => is_string($event['event_name'] ?? null) ? trim($event['event_name']) : null,
            'starts_at' => is_string($event['date_start'] ?? null) ? trim($event['date_start']) : null,
            'tournament_name' => is_string($event['tournament_name'] ?? null) ? trim($event['tournament_name']) : null,
            'venue_name' => is_string($event['venue_name'] ?? null) ? trim($event['venue_name']) : null,
            'city' => is_string($event['city'] ?? null) ? trim($event['city']) : null,
            'sport_type' => is_string($event['sport_type'] ?? null) ? trim($event['sport_type']) : null,
            'already_synced' => $existing !== null,
            'mapping_id' => $existing?->mapping?->id,
            'mapping_status' => $existing?->mapping?->status,
            'catalog_payload' => $event,
        ];
    }

    private function defaultSport(): string
    {
        $sports = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) config('services.xs2.sports', config('xs2.sports', 'soccer'))),
        )));

        return $sports[0] ?? 'soccer';
    }

    /**
     * @return array{current_page:int,last_page:int,per_page:int,total:int}
     *
     * @param  array<string, mixed>|list<mixed>  $response
     */
    private function resolvePagination(array $response, int $page, int $perPage, int $eventCount): array
    {
        $currentPage = max(1, (int) data_get($response, 'pagination.page', data_get($response, 'page', $page)));
        $totalPages = data_get($response, 'pagination.total_pages', data_get($response, 'total_pages'));
        $nextPage = data_get($response, 'pagination.next_page', data_get($response, 'next_page'));
        $totalSize = data_get($response, 'pagination.total_size', data_get($response, 'total_size'));

        if (is_numeric($totalPages)) {
            $lastPage = max(1, (int) $totalPages);
        } elseif (is_numeric($nextPage) && (int) $nextPage > $currentPage) {
            $lastPage = max($currentPage + 1, (int) $nextPage);
        } elseif ($eventCount >= $perPage) {
            $lastPage = $currentPage + 1;
        } else {
            $lastPage = $currentPage;
        }

        if (is_numeric($totalSize)) {
            $total = (int) $totalSize;
        } else {
            $total = max(0, ($lastPage - 1) * $perPage + $eventCount);
        }

        return [
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
        ];
    }
}
