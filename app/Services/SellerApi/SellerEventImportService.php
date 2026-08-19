<?php

namespace App\Services\SellerApi;

use App\Support\SeatsbrokerCatalogId;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Search Seatsbrokers external catalog events and import them into legacy match_info
 * (plus teams / tournament / game_category / stadium / city / country as needed).
 *
 * Name search uses GET /api/events?event_name=…&limit=…&lang=….
 * Import by ID uses GET /api/events?event_id=….
 * Bulk sync paginates the catalog with per_page + tournament_id.
 * Catalog hashes are MD5 of legacy integer PKs.
 */
class SellerEventImportService
{
    private const CATALOG_CACHE_KEY = 'seller-api:catalog-events-v1';

    private const CATALOG_CACHE_SECONDS = 300;

    public function __construct(
        private readonly SellerApiClient $client,
        private readonly SellerVenueCatalogSyncService $venues,
    ) {}

    /**
     * @return array{event_count: int, environment: string}
     */
    public function refreshCatalog(string $environment = 'sandbox'): array
    {
        Cache::forget(self::CATALOG_CACHE_KEY);
        $events = $this->client->fetchAllEvents(null, $environment);
        Cache::put(self::CATALOG_CACHE_KEY, $events, self::CATALOG_CACHE_SECONDS);

        return [
            'event_count' => count($events),
            'environment' => $environment,
        ];
    }

    /**
     * @return list<array{
     *     event_id:string,
     *     m_id:?int,
     *     match_name:string,
     *     match_date:?string,
     *     tournament_name:?string,
     *     stadium_name:?string,
     *     city_name:?string,
     *     country_name:?string,
     *     team_name_a:?string,
     *     team_name_b:?string,
     *     category_name:?string,
     *     already_exists:bool,
     *     catalog_payload:array<string, mixed>
     * }>
     */
    public function search(string $query, int $limit = 10, string $environment = 'sandbox'): array
    {
        $needle = trim($query);
        if ($needle === '' || mb_strlen($needle) < 2) {
            return [];
        }

        $environment = $this->normalizeCatalogEnvironment($environment);
        $limit = max(1, min(50, $limit));

        return array_map(
            fn (array $event): array => $this->mapSearchResult($event),
            $this->client->fetchEventsByName($needle, $limit, environment: $environment),
        );
    }

    /**
     * @return array{
     *     tournament_id:int,
     *     tournament_name:?string,
     *     request_url:string,
     *     events:list<array<string, mixed>>,
     *     pagination:array{current_page:int,last_page:int,per_page:int}
     * }
     */
    public function searchByTournament(
        int $tournamentId,
        int $page = 1,
        int $perPage = 100,
        string $environment = 'sandbox',
    ): array {
        if ($tournamentId < 1) {
            throw new \InvalidArgumentException('Tournament id must be a positive integer.');
        }

        $environment = $this->normalizeCatalogEnvironment($environment);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $catalogTournamentId = SeatsbrokerCatalogId::hash($tournamentId);
        $filters = ['tournament_id' => $catalogTournamentId];

        $response = $this->client->fetchEventsPage($page, $perPage, $filters, $environment);
        $batch = data_get($response, 'data');
        /** @var list<array<string, mixed>> $events */
        $events = is_array($batch)
            ? array_values(array_filter($batch, is_array(...)))
            : [];

        $requestUrl = $this->client->catalogEventsPreviewUrl($filters, $environment);
        if ($page > 1) {
            $requestUrl .= (str_contains($requestUrl, '?') ? '&' : '?').'page='.$page;
        }

        return [
            'tournament_id' => $tournamentId,
            'tournament_name' => $this->localTournamentName($tournamentId),
            'request_url' => $requestUrl,
            'events' => array_map(fn (array $event): array => $this->mapSearchResult($event), $events),
            'pagination' => [
                'current_page' => max(1, (int) data_get($response, 'meta.current_page', $page)),
                'last_page' => max(1, (int) data_get($response, 'meta.last_page', 1)),
                'per_page' => $perPage,
            ],
        ];
    }

    /**
     * @return array{
     *     environment:string,
     *     request_url:string,
     *     default_environment:string
     * }
     */
    public function previewEventSearch(string $query, int $limit = 10, string $environment = 'sandbox'): array
    {
        $environment = $this->normalizeCatalogEnvironment($environment);
        $query = trim($query);
        $limit = max(1, min(50, $limit));

        return [
            'environment' => $environment,
            'request_url' => $this->client->catalogEventSearchPreviewUrl($query, $limit, $environment),
            'default_environment' => $this->client->defaultCatalogEnvironment(),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{
     *     event_id:string,
     *     m_id:?int,
     *     match_name:string,
     *     match_date:?string,
     *     tournament_name:?string,
     *     stadium_name:?string,
     *     city_name:?string,
     *     country_name:?string,
     *     team_name_a:?string,
     *     team_name_b:?string,
     *     category_name:?string,
     *     already_exists:bool,
     *     catalog_payload:array<string, mixed>
     * }
     */
    private function mapSearchResult(array $event): array
    {
        $mId = SeatsbrokerCatalogId::resolve($event['event_id'] ?? null);

        return [
            'event_id' => (string) ($event['event_id'] ?? ''),
            'm_id' => $mId,
            'match_name' => (string) ($event['match_name'] ?? 'Untitled event'),
            'match_date' => $this->nullableString($event['match_date'] ?? null),
            'tournament_name' => $this->nullableString($event['tournament_name'] ?? null),
            'stadium_name' => $this->nullableString($event['stadium_name'] ?? null),
            'city_name' => $this->nullableString($event['city_name'] ?? null),
            'country_name' => $this->nullableString($event['country_name'] ?? null),
            'team_name_a' => $this->nullableString($event['team_name_a'] ?? null),
            'team_name_b' => $this->nullableString($event['team_name_b'] ?? null),
            'category_name' => $this->nullableString($event['category_name'] ?? null),
            'already_exists' => $mId !== null
                && Schema::hasTable('match_info')
                && DB::table('match_info')->where('m_id', $mId)->exists(),
            'catalog_payload' => $event,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{
     *     status:string,
     *     m_id:int,
     *     event_id:string,
     *     match_name:string,
     *     created:array<string, bool|int>
     * }
     */
    public function import(string $eventId, ?array $payload = null, string $environment = 'sandbox'): array
    {
        set_time_limit(max(30, (int) config('seller-api.import_time_limit', 120)));

        if (! Schema::hasTable('match_info')) {
            throw new \RuntimeException('Legacy match_info table is required to import Seatsbroker events.');
        }

        $environment = $this->normalizeCatalogEnvironment($environment);
        $eventId = trim($eventId);
        $event = is_array($payload) && ($payload['event_id'] ?? null) === $eventId
            ? $payload
            : $this->client->fetchEventById($eventId, $environment);

        if (! is_array($event) || trim((string) ($event['event_id'] ?? '')) === '') {
            throw new \InvalidArgumentException('Seatsbroker event was not found in the catalog.');
        }

        $mId = SeatsbrokerCatalogId::resolve($event['event_id'] ?? null);
        if ($mId === null) {
            throw new \RuntimeException('Could not resolve a local match id from the Seatsbroker event_id.');
        }

        if (DB::table('match_info')->where('m_id', $mId)->exists()) {
            return [
                'status' => 'already_exists',
                'm_id' => $mId,
                'event_id' => (string) $event['event_id'],
                'match_name' => (string) ($event['match_name'] ?? 'Untitled event'),
                'created' => [
                    'event' => false,
                    'venue' => false,
                    'teams' => 0,
                    'tournament' => false,
                    'category' => false,
                    'city' => false,
                    'country' => false,
                    'sections' => 0,
                    'seat_categories' => 0,
                ],
            ];
        }

        return DB::transaction(function () use ($event, $mId, $environment): array {
            $created = [
                'event' => false,
                'venue' => false,
                'teams' => 0,
                'tournament' => false,
                'category' => false,
                'city' => false,
                'country' => false,
                'sections' => 0,
                'seat_categories' => 0,
            ];

            $categoryId = $this->ensureGameCategory($event, $created);
            $tournamentId = $this->ensureTournament($event, $categoryId, $created);
            $team1Id = $this->ensureTeam($event['team_id_a'] ?? null, $event['team_name_a'] ?? null, $event['team_image_a'] ?? null, $categoryId, $created);
            $team2Id = $this->ensureTeam($event['team_id_b'] ?? null, $event['team_name_b'] ?? null, $event['team_image_b'] ?? null, $categoryId, $created);
            $countryId = $this->ensureCountry($event['country_name'] ?? null, $created);
            $cityId = $this->ensureCity($event['city_name'] ?? null, $countryId, $created);
            $stadiumId = $this->ensureStadium($event, $countryId, $cityId, $categoryId, $created, $environment);

            $matchDate = $this->nullableString($event['match_date'] ?? null) ?? now()->format('Y-m-d H:i:s');
            $matchTime = $this->nullableString($event['match_time'] ?? null);
            if ($matchTime === null && preg_match('/\b(\d{2}:\d{2})(?::\d{2})?\b/', $matchDate, $matches) === 1) {
                $matchTime = $matches[1];
            }

            $matchName = $this->nullableString($event['match_name'] ?? null) ?? "Event #{$mId}";
            $eventType = $this->nullableString($event['event_type'] ?? null) ?? 'match';
            $slug = Str::slug($matchName);
            if ($slug === '') {
                $slug = "event-{$mId}";
            }
            if (! str_ends_with($slug, '-tickets')) {
                $slug .= '-tickets';
            }

            $row = [
                'm_id' => $mId,
                'match_name' => $matchName,
                'extra_title' => '',
                'team_1' => $team1Id !== null ? (string) $team1Id : '',
                'team_2' => $team2Id !== null ? (string) $team2Id : '',
                'hometown' => $team1Id !== null ? (string) $team1Id : '0',
                'tournament' => $tournamentId !== null ? (string) $tournamentId : '',
                'slug' => $slug,
                'status' => '1',
                'availability' => '1',
                'matchticket' => '1000',
                'daysremaining' => '0',
                'description' => '',
                'meta_title' => '',
                'meta_description' => '',
                'hot_tickets' => '0',
                'match_date' => $matchDate,
                'match_time' => $matchTime ?? '',
                'venue' => $stadiumId,
                'city' => $cityId !== null ? (string) $cityId : '',
                'country' => $countryId !== null ? (string) $countryId : '',
                'create_date' => now()->format('Y-m-d H:i:s'),
                'event_type' => $eventType,
                'price_type' => 'EUR',
                'store_id' => 13,
                'xs2event_id' => '',
                'source_type' => '1boxoffice',
                'category' => $categoryId !== null ? (string) $categoryId : '',
                'tixstock_status' => 1,
                'oneclicket_status' => 1,
                'xs2event_status' => 1,
                'oneboxoffice_status' => 1,
            ];

            $row = $this->applyLegacyMatchInfoDefaults($row);

            $columns = array_column(Schema::getColumns('match_info'), 'name');
            $insert = [];
            foreach ($row as $column => $value) {
                if (in_array($column, $columns, true)) {
                    $insert[$column] = $value;
                }
            }

            DB::table('match_info')->insert($insert);
            $created['event'] = true;

            return [
                'status' => 'created',
                'm_id' => $mId,
                'event_id' => (string) $event['event_id'],
                'match_name' => $matchName,
                'created' => $created,
            ];
        });
    }

    public function forgetCatalogCache(): void
    {
        Cache::forget(self::CATALOG_CACHE_KEY);
    }

    /**
     * @return array{
     *     tournament_id:int,
     *     tournament_name:?string,
     *     request_urls:array{sandbox:string,production:string},
     *     default_environment:string,
     *     catalog_tournament_id:string
     * }
     */
    public function previewBulkSync(int $tournamentId): array
    {
        if ($tournamentId < 1) {
            throw new \InvalidArgumentException('Tournament id must be a positive integer.');
        }

        $catalogTournamentId = SeatsbrokerCatalogId::hash($tournamentId);
        $filters = ['tournament_id' => $catalogTournamentId];

        return [
            'tournament_id' => $tournamentId,
            'tournament_name' => $this->localTournamentName($tournamentId),
            'request_urls' => [
                'sandbox' => $this->client->catalogEventsPreviewUrl($filters, 'sandbox'),
                'production' => $this->client->catalogEventsPreviewUrl($filters, 'production'),
            ],
            'default_environment' => $this->client->defaultCatalogEnvironment(),
            'catalog_tournament_id' => $catalogTournamentId,
        ];
    }

    /**
     * @return array{
     *     tournament_id:int,
     *     tournament_name:?string,
     *     environment:string,
     *     request_url:string,
     *     fetched:int,
     *     created:int,
     *     skipped:int,
     *     failed:int,
     *     created_events:list<array{m_id:int,match_name:string,event_id:string}>,
     *     errors:list<array{event_id:string,message:string}>
     * }
     */
    /**
     * Import every catalog event (paginated GET /api/events) with venue enrichment.
     *
     * @return array{
     *     environment:string,
     *     request_url:string,
     *     fetched:int,
     *     created:int,
     *     skipped:int,
     *     failed:int,
     *     created_events:list<array{m_id:int,match_name:string,event_id:string}>,
     *     errors:list<array{event_id:string,message:string}>
     * }
     */
    public function syncAll(string $environment, ?callable $onProgress = null): array
    {
        $environment = $this->normalizeCatalogEnvironment($environment);

        return $this->syncCatalogEvents(
            $environment,
            [],
            [
                'environment' => $environment,
                'request_url' => $this->client->catalogEventsPreviewUrl([], $environment),
            ],
            $onProgress,
        );
    }

    public function syncByTournament(
        int $tournamentId,
        string $environment,
        ?callable $onProgress = null,
    ): array {
        if ($tournamentId < 1) {
            throw new \InvalidArgumentException('Tournament id must be a positive integer.');
        }

        $environment = $this->normalizeCatalogEnvironment($environment);
        $preview = $this->previewBulkSync($tournamentId);
        $catalogTournamentId = SeatsbrokerCatalogId::hash($tournamentId);

        return $this->syncCatalogEvents(
            $environment,
            ['tournament_id' => $catalogTournamentId],
            [
                'tournament_id' => $tournamentId,
                'tournament_name' => $preview['tournament_name'],
                'environment' => $environment,
                'request_url' => $preview['request_urls'][$environment],
            ],
            $onProgress,
        );
    }

    /**
     * @param  array<string, scalar|null>  $filters
     * @param  array<string, mixed>  $summarySeed
     * @return array{
     *     environment:string,
     *     request_url:string,
     *     fetched:int,
     *     created:int,
     *     skipped:int,
     *     failed:int,
     *     created_events:list<array{m_id:int,match_name:string,event_id:string}>,
     *     errors:list<array{event_id:string,message:string}>
     * }
     */
    private function syncCatalogEvents(
        string $environment,
        array $filters,
        array $summarySeed,
        ?callable $onProgress = null,
    ): array {
        $perPage = max(1, (int) config('services.seller_api.catalog_per_page', 100));

        $summary = array_merge([
            'fetched' => 0,
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'created_events' => [],
            'errors' => [],
        ], $summarySeed);

        $page = 1;
        $lastPage = 1;

        do {
            try {
                $response = $this->client->fetchEventsPage($page, $perPage, $filters, $environment);
            } catch (\Throwable $exception) {
                $summary['errors'][] = [
                    'event_id' => '',
                    'message' => sprintf('Failed fetching catalog page %d: %s', $page, $exception->getMessage()),
                ];

                if ($page === 1) {
                    throw $exception;
                }

                break;
            }

            $batch = data_get($response, 'data');
            if (! is_array($batch)) {
                throw new \RuntimeException('Seller API events response is missing a data array.');
            }

            /** @var list<array<string, mixed>> $events */
            $events = array_values(array_filter($batch, is_array(...)));
            $lastPage = max(1, (int) data_get($response, 'meta.last_page', 1));

            foreach ($events as $event) {
                $eventId = trim((string) ($event['event_id'] ?? ''));
                $summary['fetched']++;

                if ($eventId === '') {
                    $summary['failed']++;
                    $summary['errors'][] = [
                        'event_id' => '',
                        'message' => 'Catalog event is missing event_id.',
                    ];

                    continue;
                }

                try {
                    $result = $this->import($eventId, $event, $environment);
                    if ($result['status'] === 'already_exists') {
                        $summary['skipped']++;

                        continue;
                    }

                    $summary['created']++;
                    $summary['created_events'][] = [
                        'm_id' => $result['m_id'],
                        'match_name' => $result['match_name'],
                        'event_id' => $result['event_id'],
                    ];
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    $summary['errors'][] = [
                        'event_id' => $eventId,
                        'message' => $exception->getMessage(),
                    ];
                }
            }

            if ($onProgress !== null) {
                $onProgress($summary, $page, $lastPage);
            }

            $page++;
        } while ($page <= $lastPage);

        $this->forgetCatalogCache();

        return $summary;
    }

    private function normalizeCatalogEnvironment(string $environment): string
    {
        $environment = strtolower(trim($environment));
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw new \InvalidArgumentException('Environment must be sandbox or production.');
        }

        return $environment;
    }

    private function localTournamentName(int $tournamentId): ?string
    {
        if (! Schema::hasTable('tournament')) {
            return null;
        }

        $name = DB::table('tournament')->where('t_id', $tournamentId)->value('tournament_name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * @return array{
     *     environment:string,
     *     request_url:string,
     *     default_environment:string
     * }
     */
    public function previewSingleEventImport(string $eventId, string $environment = 'sandbox'): array
    {
        $environment = $this->normalizeCatalogEnvironment($environment);
        $eventId = trim($eventId);

        return [
            'environment' => $environment,
            'request_url' => $this->client->catalogEventsPreviewUrl(
                $eventId !== '' ? ['event_id' => $eventId] : [],
                $environment,
            ),
            'default_environment' => $this->client->defaultCatalogEnvironment(),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, bool|int>  $created
     */
    private function ensureGameCategory(array $event, array &$created): ?int
    {
        if (! Schema::hasTable('game_category')) {
            return null;
        }

        $name = $this->nullableString($event['category_name'] ?? null)
            ?? $this->nullableString(data_get($event, 'categories.sub_category'))
            ?? $this->nullableString(data_get($event, 'categories.category'));

        if ($name === null) {
            return null;
        }

        $existing = DB::table('game_category')
            ->whereRaw('LOWER(category_name) = ?', [mb_strtolower($name)])
            ->value('id');
        if (is_numeric($existing)) {
            return (int) $existing;
        }

        $id = (int) DB::table('game_category')->insertGetId([
            'category_name' => $name,
            'parent_cat_id' => 0,
            'image' => '',
            'create_date' => now()->format('Y-m-d H:i:s'),
            'status' => 1,
            'store_id' => 13,
            'add_by' => 0,
        ]);
        $created['category'] = true;

        return $id;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, bool|int>  $created
     */
    private function ensureTournament(array $event, ?int $categoryId, array &$created): ?int
    {
        if (! Schema::hasTable('tournament')) {
            return null;
        }

        $tournamentId = SeatsbrokerCatalogId::resolve($event['tournament_id'] ?? null);
        $name = $this->nullableString($event['tournament_name'] ?? null);
        if ($tournamentId === null && $name === null) {
            return null;
        }

        if ($tournamentId !== null) {
            $existing = DB::table('tournament')->where('t_id', $tournamentId)->first();
            if ($existing !== null) {
                $updates = [];
                if ($name !== null && trim((string) ($existing->tournament_name ?? '')) === '') {
                    $updates['tournament_name'] = $name;
                }
                if ($categoryId !== null && (int) ($existing->category ?? 0) < 1) {
                    $updates['category'] = $categoryId;
                }
                if ($updates !== []) {
                    DB::table('tournament')->where('t_id', $tournamentId)->update($updates);
                }

                $this->ensureTournamentEnglishName($tournamentId, $name);

                return $tournamentId;
            }

            if ($name === null) {
                $name = "Tournament #{$tournamentId}";
            }

            DB::table('tournament')->insert([
                't_id' => $tournamentId,
                'tournament_name' => $name,
                'status' => '1',
                'create_date' => (string) time(),
                'popular_tournament' => '0',
                'sort_by' => 0,
                'show_in_list' => 1,
                'attendee_status' => '0',
                'category' => $categoryId,
                'source_type' => '1boxoffice',
                'sitemap_status' => 0,
                'show_on_footer' => 0,
            ]);
            $created['tournament'] = true;
            $this->ensureTournamentEnglishName($tournamentId, $name);

            return $tournamentId;
        }

        $existingId = DB::table('tournament')
            ->whereRaw('LOWER(tournament_name) = ?', [mb_strtolower((string) $name)])
            ->value('t_id');
        if (is_numeric($existingId)) {
            return (int) $existingId;
        }

        $newId = (int) DB::table('tournament')->insertGetId([
            'tournament_name' => $name,
            'status' => '1',
            'create_date' => (string) time(),
            'popular_tournament' => '0',
            'sort_by' => 0,
            'show_in_list' => 1,
            'attendee_status' => '0',
            'category' => $categoryId,
            'source_type' => '1boxoffice',
            'sitemap_status' => 0,
            'show_on_footer' => 0,
        ], 't_id');
        $created['tournament'] = true;
        $this->ensureTournamentEnglishName($newId, $name);

        return $newId;
    }

    private function ensureTournamentEnglishName(int $tournamentId, ?string $name): void
    {
        if ($name === null
            || ! Schema::hasTable('tournament_lang')
            || ! Schema::hasColumns('tournament_lang', ['tournament_id', 'tournament_name', 'language'])) {
            return;
        }

        $exists = DB::table('tournament_lang')
            ->where('tournament_id', $tournamentId)
            ->where('language', 'en')
            ->exists();
        if ($exists) {
            return;
        }

        $row = [
            'tournament_id' => $tournamentId,
            'tournament_name' => $name,
            'language' => 'en',
        ];
        if (Schema::hasColumn('tournament_lang', 'store_id')) {
            $row['store_id'] = 13;
        }

        DB::table('tournament_lang')->insert($row);
    }

    /**
     * @param  array<string, bool|int>  $created
     */
    private function ensureTeam(mixed $teamHash, mixed $teamName, mixed $teamImage, ?int $categoryId, array &$created): ?int
    {
        if (! Schema::hasTable('teams')) {
            return null;
        }

        $teamId = SeatsbrokerCatalogId::resolve($teamHash);
        $name = $this->nullableString($teamName);
        if ($teamId === null && $name === null) {
            return null;
        }

        $image = $this->normalizeTeamImage($this->nullableString($teamImage));

        if ($teamId !== null) {
            $existing = DB::table('teams')->where('id', $teamId)->first();
            if ($existing !== null) {
                $updates = [];
                if ($name !== null && trim((string) ($existing->team_name ?? '')) === '') {
                    $updates['team_name'] = $name;
                }
                if ($image !== null && trim((string) ($existing->team_image ?? '')) === '') {
                    $updates['team_image'] = $image;
                }
                if ($updates !== []) {
                    DB::table('teams')->where('id', $teamId)->update($updates);
                }

                $this->ensureTeamEnglishName($teamId, $name);

                return $teamId;
            }

            if ($name === null) {
                $name = "Team #{$teamId}";
            }

            DB::table('teams')->insert([
                'id' => $teamId,
                'team_name' => $name,
                'category' => $categoryId !== null ? (string) $categoryId : '',
                'team_image' => $image ?? '',
                'create_date' => (string) time(),
                'status' => 1,
                'show_status' => 1,
                'store_id' => 13,
                'source_type' => '1boxoffice',
                'sitemap_status' => 0,
                'show_on_footer' => 0,
            ]);
            $created['teams'] = (int) $created['teams'] + 1;
            $this->ensureTeamEnglishName($teamId, $name);

            return $teamId;
        }

        $existingId = DB::table('teams')
            ->whereRaw('LOWER(team_name) = ?', [mb_strtolower((string) $name)])
            ->value('id');
        if (is_numeric($existingId)) {
            return (int) $existingId;
        }

        $newId = (int) DB::table('teams')->insertGetId([
            'team_name' => $name,
            'category' => $categoryId !== null ? (string) $categoryId : '',
            'team_image' => $image ?? '',
            'create_date' => (string) time(),
            'status' => 1,
            'show_status' => 1,
            'store_id' => 13,
            'source_type' => '1boxoffice',
            'sitemap_status' => 0,
            'show_on_footer' => 0,
        ]);
        $created['teams'] = (int) $created['teams'] + 1;
        $this->ensureTeamEnglishName($newId, $name);

        return $newId;
    }

    private function ensureTeamEnglishName(int $teamId, ?string $name): void
    {
        if ($name === null
            || ! Schema::hasTable('teams_lang')
            || ! Schema::hasColumns('teams_lang', ['team_id', 'team_name', 'language'])) {
            return;
        }

        $exists = DB::table('teams_lang')
            ->where('team_id', $teamId)
            ->where('language', 'en')
            ->exists();
        if ($exists) {
            return;
        }

        $row = [
            'team_id' => $teamId,
            'team_name' => $name,
            'language' => 'en',
        ];
        if (Schema::hasColumn('teams_lang', 'store_id')) {
            $row['store_id'] = 13;
        }

        DB::table('teams_lang')->insert($row);
    }

    /**
     * @param  array<string, bool|int>  $created
     */
    private function ensureCountry(?string $countryName, array &$created): ?int
    {
        if ($countryName === null || ! Schema::hasTable('countries')) {
            return null;
        }

        $existing = DB::table('countries')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($countryName)])
            ->value('id');
        if (is_numeric($existing)) {
            return (int) $existing;
        }

        if (! Schema::hasColumn('countries', 'name')) {
            return null;
        }

        $row = [
            'name' => $countryName,
            'sortname' => strtoupper(substr($countryName, 0, 2)),
            'phonecode' => 0,
            'add_by' => 0,
            'create_date' => '',
        ];
        $columns = array_column(Schema::getColumns('countries'), 'name');
        $insert = [];
        foreach ($row as $column => $value) {
            if (in_array($column, $columns, true)) {
                $insert[$column] = $value;
            }
        }

        $id = (int) DB::table('countries')->insertGetId($insert);
        $created['country'] = true;

        return $id;
    }

    /**
     * @param  array<string, bool|int>  $created
     */
    private function ensureCity(?string $cityName, ?int $countryId, array &$created): ?int
    {
        if ($cityName === null || ! Schema::hasTable('cities')) {
            return null;
        }

        $existing = DB::table('cities')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($cityName)])
            ->value('id');
        if (is_numeric($existing)) {
            return (int) $existing;
        }

        $row = [
            'name' => $cityName,
            'state_id' => 0,
            'add_by' => 0,
            'create_date' => '',
        ];
        $columns = array_column(Schema::getColumns('cities'), 'name');
        $insert = [];
        foreach ($row as $column => $value) {
            if (in_array($column, $columns, true)) {
                $insert[$column] = $value;
            }
        }

        $id = (int) DB::table('cities')->insertGetId($insert);
        $created['city'] = true;

        return $id;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, bool|int>  $created
     */
    private function ensureStadium(
        array $event,
        ?int $countryId,
        ?int $cityId,
        ?int $categoryId,
        array &$created,
        string $environment = 'sandbox',
    ): ?int {
        if (! Schema::hasTable('stadium')) {
            return null;
        }

        $stadiumId = SeatsbrokerCatalogId::resolve($event['stadium_id'] ?? null);
        $name = $this->nullableString($event['stadium_name'] ?? null);
        $image = $this->nullableString($event['stadium_image'] ?? null);

        if ($stadiumId === null) {
            return null;
        }

        $existing = DB::table('stadium')->where('s_id', $stadiumId)->first();
        if ($existing === null) {
            DB::table('stadium')->insert([
                's_id' => $stadiumId,
                'stadium_type' => 1,
                'stadium_image' => $image,
                'stadium_name' => $name ?? "Venue #{$stadiumId}",
                'country' => $countryId,
                'city' => $cityId,
                'width' => '',
                'height' => '',
                'main_team' => '',
                'map_code' => '',
                'status' => '1',
                'attendee_status' => '0',
                'create_date' => now()->format('Y-m-d H:i:s'),
                'stadium_name_ar' => $name ?? '',
                'source_type' => '1boxoffice',
                'category' => $categoryId !== null ? (string) $categoryId : '1',
            ]);
            $created['venue'] = true;
        } else {
            $updates = [];
            if ($name !== null && trim((string) ($existing->stadium_name ?? '')) === '') {
                $updates['stadium_name'] = $name;
            }
            if ($image !== null && trim((string) ($existing->stadium_image ?? '')) === '') {
                $updates['stadium_image'] = $image;
            }
            if ($countryId !== null && (int) ($existing->country ?? 0) < 1) {
                $updates['country'] = $countryId;
            }
            if ($cityId !== null && (int) ($existing->city ?? 0) < 1) {
                $updates['city'] = $cityId;
            }
            if ($updates !== []) {
                DB::table('stadium')->where('s_id', $stadiumId)->update($updates);
            }
        }

        // Sync blocks/categories only when sections are missing for this stadium.
        $needsBlocks = Schema::hasTable('stadium_details')
            && ! DB::table('stadium_details')->where('stadium_id', $stadiumId)->exists();

        if ($needsBlocks) {
            try {
                $summary = $this->venues->syncVenueByStadiumId($stadiumId, environment: $environment);
                if ($summary['found']) {
                    $created['seat_categories'] = (int) $created['seat_categories'] + $summary['categories_created'];
                    $created['sections'] = (int) $created['sections'] + $summary['sections_created'];
                    if ($summary['venues_created'] > 0) {
                        $created['venue'] = true;
                    }
                }
            } catch (\Throwable) {
                // Venue catalog sync is best-effort; the stadium row above is enough for /events.
            }
        }

        return $stadiumId;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function applyLegacyMatchInfoDefaults(array $row): array
    {
        $knownDefaults = [
            'upcoming_events' => 0,
            'url_key' => '',
            'request' => 0,
            'epl_status' => 0,
            'confirm_status' => 0,
            'affiliate_status' => 0,
            'show_match_name' => 0,
        ];

        foreach ($knownDefaults as $column => $value) {
            if (! array_key_exists($column, $row)) {
                $row[$column] = $value;
            }
        }

        $columnMeta = collect(Schema::getColumns('match_info'))->keyBy('name');

        foreach ($columnMeta as $name => $column) {
            if (array_key_exists($name, $row)) {
                continue;
            }

            if (($column['nullable'] ?? false)
                || ($column['default'] ?? null) !== null
                || ($column['auto_increment'] ?? false)
                || ($column['generation'] ?? null) !== null) {
                continue;
            }

            $row[$name] = $this->legacyMatchInfoColumnDefault(
                (string) $name,
                (string) ($column['type_name'] ?? 'varchar'),
                $row,
            );
        }

        return $row;
    }

    /** @param  array<string, mixed>  $row */
    private function legacyMatchInfoColumnDefault(string $name, string $type, array $row): mixed
    {
        return match ($type) {
            'int', 'tinyint', 'smallint', 'mediumint', 'bigint' => 0,
            'datetime', 'timestamp' => $row['match_date'] ?? now()->format('Y-m-d H:i:s'),
            'date' => isset($row['match_date'])
                ? substr((string) $row['match_date'], 0, 10)
                : now()->format('Y-m-d'),
            default => '',
        };
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function normalizeTeamImage(?string $image): ?string
    {
        if ($image === null || str_ends_with($image, '/')) {
            return null;
        }

        if (substr_count($image, '://') > 1) {
            $embeddedHttp = strrpos($image, 'http');
            if ($embeddedHttp !== false && $embeddedHttp > 0) {
                $image = substr($image, $embeddedHttp);
            }
        }

        $maxLength = 255;
        if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'team_image')) {
            foreach (Schema::getColumns('teams') as $column) {
                if (($column['name'] ?? '') === 'team_image' && ($column['type_name'] ?? '') === 'varchar') {
                    $maxLength = (int) ($column['length'] ?? 255);
                    break;
                }
            }
        }

        if (mb_strlen($image) > $maxLength) {
            $image = mb_substr($image, 0, $maxLength);
        }

        return $image !== '' ? $image : null;
    }
}
