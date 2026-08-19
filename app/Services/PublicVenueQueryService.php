<?php

namespace App\Services;

use App\Models\EventMapping;
use App\Models\MatchInfo;
use App\Models\Xs2CategoryMapping;
use App\Services\Mapping\LegacyMasterDataSchema;
use App\Support\EnglishDisplayText;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicVenueQueryService
{
    public function __construct(
        private readonly LegacyMasterDataSchema $schema,
        private readonly LegacyLocalEventEnglishQuery $englishLabels,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $eventFilters = $this->eventFiltersFrom($filters);
        // Always restrict to venues that have at least one matching event.
        // Event filters default to future-only, so venues with only past (or
        // zero) events are excluded from the public list.
        $stadiumIds = $this->stadiumIdsMatchingEventFilters($eventFilters);

        $paginator = $this->schema->paginateSeatsbrokerVenues([
            'search' => $filters['search'] ?? null,
            'page' => $filters['page'] ?? null,
            'per_page' => $filters['per_page'] ?? null,
            'sort' => $filters['sort'] ?? null,
            'direction' => $filters['direction'] ?? null,
            'stadium_ids' => $stadiumIds,
        ]);

        /** @var list<array{id:int,name:?string,city_id:?int,city_name:?string,source:string}> $rows */
        $rows = $paginator->getCollection()->all();
        $stats = $this->statsForPage($rows, $eventFilters);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (array $row): array => $this->withStats($row, $stats)),
        );

        return $paginator;
    }

    /**
     * @return array{
     *     categories:list<array{id:int,name:string}>,
     *     tournaments:list<array{id:int,name:string,category_id:?int}>
     * }
     */
    public function filterOptions(): array
    {
        return [
            'categories' => $this->categoryOptions(),
            'tournaments' => $this->tournamentOptions(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     venue:array{id:int,name:?string,city:?string,xs2_mapped:bool,xs2_venue_id:?string,xs2_venue_name:?string},
     *     data:list<array<string, mixed>>
     * }
     */
    public function eventsForVenue(int $stadiumId, array $filters = []): array
    {
        $venue = $this->requireVenue($stadiumId);

        if (! Schema::hasTable('match_info') || ! Schema::hasColumn('match_info', 'venue')) {
            return ['venue' => $venue, 'data' => []];
        }

        $eventFilters = $this->eventFiltersFrom($filters);

        $events = MatchInfo::query()
            ->from('match_info')
            ->select('match_info.*')
            ->tap(fn ($query) => $this->englishLabels->apply($query))
            ->where('match_info.venue', $stadiumId)
            ->tap(fn ($query) => $this->applyEventFilters($query, $eventFilters))
            ->with(['publicXs2Mappings.xs2Event'])
            ->orderBy('match_info.match_date')
            ->orderBy('match_info.m_id')
            ->limit(500)
            ->get();

        $data = $events->map(function (MatchInfo $event): array {
            /** @var EventMapping|null $mapping */
            $mapping = $event->publicXs2Mappings->first();
            $xs2 = $mapping?->xs2Event;

            return [
                'id' => (int) $event->m_id,
                'name' => EnglishDisplayText::resolve(
                    $event->getAttribute('legacy_match_name'),
                    $event->match_name,
                    EnglishDisplayText::teamEventTitle(
                        $event->getAttribute('legacy_home_team_name'),
                        $event->getAttribute('legacy_away_team_name'),
                    ),
                ) ?? 'Unnamed event',
                'starts_at' => $event->match_date instanceof \DateTimeInterface
                    ? $event->match_date->format('Y-m-d\\TH:i:s')
                    : null,
                'tournament' => EnglishDisplayText::resolve(
                    $event->getAttribute('legacy_tournament_name'),
                    is_string($event->tournament) ? $event->tournament : null,
                ),
                'home_team' => EnglishDisplayText::resolve(
                    $event->getAttribute('legacy_home_team_name'),
                    is_string($event->team_1) ? $event->team_1 : null,
                ),
                'away_team' => EnglishDisplayText::resolve(
                    $event->getAttribute('legacy_away_team_name'),
                    is_string($event->team_2) ? $event->team_2 : null,
                ),
                'xs2_mapped' => $mapping !== null && $xs2 !== null,
                'xs2_event' => $mapping !== null && $xs2 !== null ? [
                    'mapping_id' => (int) $mapping->id,
                    'mapping_status' => (string) $mapping->status,
                    'id' => (int) $xs2->id,
                    'external_event_id' => $xs2->external_event_id,
                    'name' => EnglishDisplayText::preferEnglish($xs2->event_name),
                    'sport_type' => $xs2->sport_type,
                    'event_status' => $xs2->event_status,
                    'starts_at' => $xs2->date_start_local instanceof \DateTimeInterface
                        ? $xs2->date_start_local->format('Y-m-d\\TH:i:s')
                        : null,
                    'venue_name' => EnglishDisplayText::preferEnglish($xs2->venue_name),
                    'tournament_name' => EnglishDisplayText::preferEnglish($xs2->tournament_name),
                ] : null,
            ];
        })->values()->all();

        return ['venue' => $venue, 'data' => $data];
    }

    /**
     * @return array{
     *     venue:array{id:int,name:?string,city:?string,xs2_mapped:bool,xs2_venue_id:?string,xs2_venue_name:?string},
     *     data:list<array<string, mixed>>
     * }
     */
    public function categoriesForVenue(int $stadiumId): array
    {
        $venue = $this->requireVenue($stadiumId);
        $categories = $this->schema->stadiumSeatCategoriesForStadium($stadiumId);
        $mappingsBySeat = $this->xs2CategoryMappingsBySeat($stadiumId);

        $data = collect($categories)->map(function (array $category) use ($mappingsBySeat): array {
            $seatId = (int) $category['stadium_seat_id'];
            /** @var list<array<string, mixed>> $xs2 */
            $xs2 = $mappingsBySeat[$seatId] ?? [];

            return [
                'stadium_seat_id' => $seatId,
                'name' => (string) $category['stadium_seat_name'],
                'section_count' => (int) $category['detail_count'],
                'sections' => collect($category['details'])->map(fn (array $detail): array => [
                    'stadium_detail_id' => (int) $detail['stadium_detail_id'],
                    'name' => (string) $detail['name'],
                ])->values()->all(),
                'xs2_mapped' => $xs2 !== [],
                'xs2_categories' => $xs2,
            ];
        })->values()->all();

        return ['venue' => $venue, 'data' => $data];
    }

    /**
     * @return array{
     *     venue:array{id:int,name:?string,city:?string,xs2_mapped:bool,xs2_venue_id:?string,xs2_venue_name:?string},
     *     data:list<array<string, mixed>>
     * }
     */
    public function sectionsForVenue(int $stadiumId): array
    {
        $venue = $this->requireVenue($stadiumId);
        $details = $this->schema->stadiumDetailsForStadium($stadiumId);
        $mappingsByDetail = $this->xs2CategoryMappingsByDetail($stadiumId);

        $data = collect($details)
            ->sortBy(fn (array $detail): string => (string) ($detail['name'] ?? $detail['block'] ?? $detail['stadium_detail_id']), SORT_NATURAL | SORT_FLAG_CASE)
            ->map(function (array $detail) use ($mappingsByDetail): array {
                $detailId = (int) $detail['stadium_detail_id'];
                /** @var list<array<string, mixed>> $xs2 */
                $xs2 = $mappingsByDetail[$detailId] ?? [];

                return [
                    'stadium_detail_id' => $detailId,
                    'name' => $detail['name'] ?? $detail['block'] ?? $detail['section'] ?? "Section #{$detailId}",
                    'block' => $detail['block'],
                    'section' => $detail['section'],
                    'stadium_seat_id' => $detail['stadium_seat_id'],
                    'stadium_seat_name' => $detail['stadium_seat_name'],
                    'xs2_mapped' => $xs2 !== [],
                    'xs2_sections' => $xs2,
                ];
            })
            ->values()
            ->all();

        return ['venue' => $venue, 'data' => $data];
    }

    /**
     * @return array{id:int,name:?string,city:?string,xs2_mapped:bool,xs2_venue_id:?string,xs2_venue_name:?string}
     */
    private function requireVenue(int $stadiumId): array
    {
        if ($stadiumId < 1) {
            throw new NotFoundHttpException('Venue not found.');
        }

        $stadium = $this->schema->stadiumById($stadiumId);
        if ($stadium === null) {
            throw new NotFoundHttpException('Venue not found.');
        }

        $cityId = $this->schema->stadiumCityId($stadium);
        $city = $cityId ? $this->schema->cityById($cityId) : null;
        $xs2 = $this->xs2VenueForStadium($stadiumId);

        return [
            'id' => $stadiumId,
            'name' => EnglishDisplayText::preferEnglish($this->schema->stadiumName($stadium)),
            'city' => $city ? EnglishDisplayText::preferEnglish($this->schema->cityName($city)) : null,
            'xs2_mapped' => $xs2 !== null,
            'xs2_venue_id' => $xs2['external_venue_id'] ?? null,
            'xs2_venue_name' => $xs2['venue_name'] ?? null,
        ];
    }

    /**
     * @return array{external_venue_id:?string,venue_name:?string}|null
     */
    private function xs2VenueForStadium(int $stadiumId): ?array
    {
        if (
            ! Schema::hasTable('xs2_stadium_mappings')
            || ! Schema::hasTable('xs2_venues')
            || ! Schema::hasColumn('xs2_stadium_mappings', 'stadium_id')
        ) {
            return null;
        }

        $row = DB::table('xs2_stadium_mappings as m')
            ->join('xs2_venues as v', 'v.id', '=', 'm.xs2_venue_id')
            ->where('m.stadium_id', $stadiumId)
            ->where('m.status', 'mapped')
            ->orderBy('m.id')
            ->first(['v.external_venue_id', 'v.venue_name']);

        if ($row === null) {
            return null;
        }

        return [
            'external_venue_id' => filled($row->external_venue_id ?? null) ? (string) $row->external_venue_id : null,
            'venue_name' => EnglishDisplayText::preferEnglish($row->venue_name ?? null),
        ];
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    private function xs2CategoryMappingsBySeat(int $stadiumId): array
    {
        $indexed = [];

        foreach ($this->mappedCategoryMappings($stadiumId) as $mapping) {
            $payload = $this->xs2CategoryPayload($mapping);
            $seatIds = collect([(int) ($mapping->stadium_seat_id ?? 0)])
                ->merge($mapping->details->pluck('stadium_seat_id')->map(fn ($id): int => (int) $id))
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();

            foreach ($seatIds as $seatId) {
                $indexed[$seatId] ??= [];
                $indexed[$seatId][] = $payload;
            }
        }

        return $indexed;
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    private function xs2CategoryMappingsByDetail(int $stadiumId): array
    {
        $indexed = [];

        foreach ($this->mappedCategoryMappings($stadiumId) as $mapping) {
            $base = $this->xs2CategoryPayload($mapping);

            foreach ($mapping->details as $detail) {
                $detailId = (int) ($detail->stadium_detail_id ?? 0);
                if ($detailId < 1) {
                    continue;
                }

                $indexed[$detailId] ??= [];
                $indexed[$detailId][] = [
                    ...$base,
                    'detail_id' => (int) $detail->id,
                    'block' => $detail->block,
                    'section' => $detail->section,
                    'name' => $detail->name,
                    'stadium_seat_name' => $detail->stadium_seat_name,
                ];
            }
        }

        return $indexed;
    }

    /** @return Collection<int, Xs2CategoryMapping> */
    private function mappedCategoryMappings(int $stadiumId): Collection
    {
        if (! Schema::hasTable('xs2_category_mappings')) {
            return collect();
        }

        return Xs2CategoryMapping::query()
            ->with(['category.context', 'details'])
            ->where('stadium_id', $stadiumId)
            ->where('status', 'mapped')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function xs2CategoryPayload(Xs2CategoryMapping $mapping): array
    {
        $category = $mapping->category;
        $context = $category?->context;
        $rawName = $category?->category_name;
        [$name, $section] = $this->categoryNameParts($rawName);

        return [
            'mapping_id' => (int) $mapping->id,
            'status' => (string) $mapping->status,
            'external_category_id' => $category?->external_category_id,
            'name' => $name,
            'section' => $section,
            'raw_name' => $rawName,
            'type' => $context?->category_type,
            'external_venue_id' => $context?->external_venue_id,
        ];
    }

    /** @return array{0:?string,1:?string} */
    private function categoryNameParts(?string $name): array
    {
        if ($name === null) {
            return [null, null];
        }

        [$category, $section] = array_pad(explode('_', $name, 2), 2, null);

        return [trim((string) $category) !== '' ? trim((string) $category) : null, filled($section) ? trim((string) $section) : null];
    }

    /**
     * @param  list<array{id:int,name:?string,city_id:?int,city_name:?string,source:string}>  $rows
     * @param  array{category_id:?int,tournament_id:?int,date_from:?string,date_to:?string,performer:?string}  $eventFilters
     * @return array<int, array{
     *     event_count:int,
     *     category_count:int,
     *     section_count:int,
     *     xs2_mapped:bool,
     *     xs2_venue_id:?string,
     *     xs2_venue_name:?string
     * }>
     */
    private function statsForPage(array $rows, array $eventFilters = []): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['id'],
            $rows,
        )));

        $empty = [
            'event_count' => 0,
            'category_count' => 0,
            'section_count' => 0,
            'xs2_mapped' => false,
            'xs2_venue_id' => null,
            'xs2_venue_name' => null,
        ];

        if ($ids === []) {
            return [];
        }

        /** @var array<int, array{event_count:int,category_count:int,section_count:int,xs2_mapped:bool,xs2_venue_id:?string,xs2_venue_name:?string}> $stats */
        $stats = [];
        foreach ($ids as $id) {
            $stats[$id] = $empty;
        }

        if (Schema::hasTable('match_info') && Schema::hasColumn('match_info', 'venue')) {
            $eventCountsQuery = DB::table('match_info')
                ->selectRaw('venue as stadium_id, COUNT(*) as aggregate')
                ->whereIn('venue', $ids);
            $this->applyEventFiltersToQuery($eventCountsQuery, $eventFilters);
            $eventCounts = $eventCountsQuery->groupBy('venue')->pluck('aggregate', 'stadium_id');

            foreach ($eventCounts as $stadiumId => $count) {
                if (isset($stats[(int) $stadiumId])) {
                    $stats[(int) $stadiumId]['event_count'] = (int) $count;
                }
            }
        }

        if (Schema::hasTable('stadium_details') && Schema::hasColumn('stadium_details', 'stadium_id')) {
            $sectionCounts = DB::table('stadium_details')
                ->selectRaw('stadium_id, COUNT(*) as aggregate')
                ->whereIn('stadium_id', $ids)
                ->groupBy('stadium_id')
                ->pluck('aggregate', 'stadium_id');

            foreach ($sectionCounts as $stadiumId => $count) {
                if (isset($stats[(int) $stadiumId])) {
                    $stats[(int) $stadiumId]['section_count'] = (int) $count;
                }
            }

            if (Schema::hasColumn('stadium_details', 'category')) {
                $categoryCounts = DB::table('stadium_details')
                    ->selectRaw('stadium_id, COUNT(DISTINCT category) as aggregate')
                    ->whereIn('stadium_id', $ids)
                    ->whereNotNull('category')
                    ->where('category', '>', 0)
                    ->groupBy('stadium_id')
                    ->pluck('aggregate', 'stadium_id');

                foreach ($categoryCounts as $stadiumId => $count) {
                    if (isset($stats[(int) $stadiumId])) {
                        $stats[(int) $stadiumId]['category_count'] = (int) $count;
                    }
                }
            }
        }

        if (
            Schema::hasTable('xs2_stadium_mappings')
            && Schema::hasTable('xs2_venues')
            && Schema::hasColumn('xs2_stadium_mappings', 'stadium_id')
        ) {
            $mappings = DB::table('xs2_stadium_mappings as m')
                ->join('xs2_venues as v', 'v.id', '=', 'm.xs2_venue_id')
                ->whereIn('m.stadium_id', $ids)
                ->where('m.status', 'mapped')
                ->orderBy('m.id')
                ->get(['m.stadium_id', 'v.external_venue_id', 'v.venue_name']);

            foreach ($mappings as $mapping) {
                $stadiumId = (int) $mapping->stadium_id;
                if (! isset($stats[$stadiumId]) || $stats[$stadiumId]['xs2_mapped']) {
                    continue;
                }

                $stats[$stadiumId]['xs2_mapped'] = true;
                $stats[$stadiumId]['xs2_venue_id'] = filled($mapping->external_venue_id ?? null)
                    ? (string) $mapping->external_venue_id
                    : null;
                $stats[$stadiumId]['xs2_venue_name'] = filled($mapping->venue_name ?? null)
                    ? (string) $mapping->venue_name
                    : null;
            }
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{category_id:?int,tournament_id:?int,date_from:?string,date_to:?string,performer:?string}
     */
    private function eventFiltersFrom(array $filters): array
    {
        $categoryId = isset($filters['category_id']) && is_numeric($filters['category_id'])
            ? (int) $filters['category_id']
            : null;
        $tournamentId = isset($filters['tournament_id']) && is_numeric($filters['tournament_id'])
            ? (int) $filters['tournament_id']
            : null;
        $performer = trim((string) ($filters['performer'] ?? ''));

        return [
            'category_id' => $categoryId !== null && $categoryId > 0 ? $categoryId : null,
            'tournament_id' => $tournamentId !== null && $tournamentId > 0 ? $tournamentId : null,
            'date_from' => filled($filters['date_from'] ?? null) ? (string) $filters['date_from'] : null,
            'date_to' => filled($filters['date_to'] ?? null) ? (string) $filters['date_to'] : null,
            'performer' => $performer !== '' ? $performer : null,
        ];
    }

    /**
     * @param  array{category_id:?int,tournament_id:?int,date_from:?string,date_to:?string,performer:?string}  $filters
     * @return list<int>
     */
    private function stadiumIdsMatchingEventFilters(array $filters): array
    {
        if (! Schema::hasTable('match_info') || ! Schema::hasColumn('match_info', 'venue')) {
            return [];
        }

        $query = DB::table('match_info')->select('venue')->whereNotNull('venue');
        $this->applyEventFiltersToQuery($query, $filters);

        return $query->distinct()
            ->pluck('venue')
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\MatchInfo>|\Illuminate\Database\Query\Builder  $query
     * @param  array{category_id:?int,tournament_id:?int,date_from:?string,date_to:?string,performer:?string}  $filters
     */
    private function applyEventFilters($query, array $filters): void
    {
        $this->applyEventFiltersToQuery($query, $filters);
    }

    /**
     * Future events only by default. An explicit past date_to opts into a
     * historical range; otherwise match_date is constrained to >= now().
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\MatchInfo>|\Illuminate\Database\Query\Builder  $query
     * @param  array{category_id:?int,tournament_id:?int,date_from:?string,date_to:?string,performer:?string}  $filters
     */
    private function applyEventFiltersToQuery($query, array $filters): void
    {
        if (($filters['category_id'] ?? null) !== null) {
            $categoryId = (int) $filters['category_id'];
            if (Schema::hasColumn('match_info', 'category')) {
                $query->where('match_info.category', $categoryId);
            } elseif (
                Schema::hasTable('tournament')
                && Schema::hasColumn('tournament', 'category')
                && Schema::hasColumn('match_info', 'tournament')
            ) {
                $query->whereIn('match_info.tournament', function ($sub) use ($categoryId): void {
                    $sub->from('tournament')->select('t_id')->where('category', $categoryId);
                });
            }
        }

        if (($filters['tournament_id'] ?? null) !== null && Schema::hasColumn('match_info', 'tournament')) {
            $query->where('match_info.tournament', (int) $filters['tournament_id']);
        }

        if (Schema::hasColumn('match_info', 'match_date')) {
            $dateFrom = $filters['date_from'] ?? null;
            $dateTo = $filters['date_to'] ?? null;
            $historical = is_string($dateTo) && $dateTo !== '' && $dateTo < now()->toDateString();

            if ($historical) {
                if (is_string($dateFrom) && $dateFrom !== '') {
                    $query->whereDate('match_info.match_date', '>=', $dateFrom);
                }
            } else {
                $query->where('match_info.match_date', '>=', now());
                if (is_string($dateFrom) && $dateFrom !== '') {
                    $query->whereDate('match_info.match_date', '>=', $dateFrom);
                }
            }

            if (is_string($dateTo) && $dateTo !== '') {
                $query->whereDate('match_info.match_date', '<=', $dateTo);
            }
        }

        if (($filters['performer'] ?? null) !== null) {
            $needle = '%'.$filters['performer'].'%';
            $query->where(function ($query) use ($needle): void {
                $query->where('match_info.team_1', 'like', $needle)
                    ->orWhere('match_info.team_2', 'like', $needle)
                    ->orWhere('match_info.match_name', 'like', $needle);

                if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'team_name')) {
                    $teamIds = DB::table('teams')
                        ->where('team_name', 'like', $needle)
                        ->pluck('id')
                        ->filter(fn ($id): bool => is_numeric($id))
                        ->map(fn ($id): int => (int) $id)
                        ->all();

                    if ($teamIds !== []) {
                        $query->orWhereIn('match_info.team_1', $teamIds)
                            ->orWhereIn('match_info.team_2', $teamIds);
                    }
                }
            });
        }
    }

    /** @return list<array{id:int,name:string}> */
    private function categoryOptions(): array
    {
        if (! Schema::hasTable('game_category')) {
            return [];
        }

        $hasTranslations = Schema::hasTable('game_category_lang')
            && Schema::hasColumns('game_category_lang', ['game_cat_id', 'category_name', 'language']);
        $query = DB::table('game_category as categories')->select('categories.id');

        if ($hasTranslations) {
            $query->leftJoin('game_category_lang as labels', function ($join): void {
                $join->on('labels.game_cat_id', '=', 'categories.id')
                    ->where('labels.language', '=', 'en');
            })->selectRaw("COALESCE(NULLIF(labels.category_name, ''), NULLIF(categories.category_name, ''), CONCAT('Category #', categories.id)) as name");
        } else {
            $query->selectRaw("COALESCE(NULLIF(categories.category_name, ''), CONCAT('Category #', categories.id)) as name");
        }

        if (Schema::hasColumn('game_category', 'status')) {
            $query->where('categories.status', 1);
        }

        // Duplicate en rows in game_category_lang can multiply categories.id.
        return $query->orderBy('name')
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
            ])
            ->unique('id')
            ->values()
            ->all();
    }

    /** @return list<array{id:int,name:string,category_id:?int}> */
    private function tournamentOptions(): array
    {
        if (! Schema::hasTable('tournament')) {
            return [];
        }

        $hasTranslations = Schema::hasTable('tournament_lang')
            && Schema::hasColumns('tournament_lang', ['tournament_id', 'tournament_name', 'language']);
        $hasCategory = Schema::hasColumn('tournament', 'category');
        $query = DB::table('tournament as tournaments')->select('tournaments.t_id as id');

        if ($hasCategory) {
            $query->addSelect('tournaments.category as category_id');
        }

        if ($hasTranslations) {
            $query->leftJoin('tournament_lang as labels', function ($join): void {
                $join->on('labels.tournament_id', '=', 'tournaments.t_id')
                    ->where('labels.language', '=', 'en');
            })->selectRaw("COALESCE(NULLIF(labels.tournament_name, ''), NULLIF(tournaments.tournament_name, ''), CONCAT('Tournament #', tournaments.t_id)) as name");
        } else {
            $query->selectRaw("COALESCE(NULLIF(tournaments.tournament_name, ''), CONCAT('Tournament #', tournaments.t_id)) as name");
        }

        if (Schema::hasColumn('tournament', 'status')) {
            $query->where('tournaments.status', 1);
        }

        // Duplicate en rows in tournament_lang can multiply tournaments.t_id.
        return $query->orderBy('name')
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'category_id' => $hasCategory && is_numeric($row->category_id ?? null) ? (int) $row->category_id : null,
            ])
            ->unique('id')
            ->values()
            ->all();
    }

    /**
     * @param  array{id:int,name:?string,city_id:?int,city_name:?string,source:string}  $row
     * @param  array<int, array{event_count:int,category_count:int,section_count:int,xs2_mapped:bool,xs2_venue_id:?string,xs2_venue_name:?string}>  $stats
     * @return array{
     *     id:int,
     *     name:?string,
     *     city_id:?int,
     *     city_name:?string,
     *     source:string,
     *     event_count:int,
     *     category_count:int,
     *     section_count:int,
     *     xs2_mapped:bool,
     *     xs2_venue_id:?string,
     *     xs2_venue_name:?string
     * }
     */
    private function withStats(array $row, array $stats): array
    {
        $id = (int) $row['id'];
        $extra = $stats[$id] ?? [
            'event_count' => 0,
            'category_count' => 0,
            'section_count' => 0,
            'xs2_mapped' => false,
            'xs2_venue_id' => null,
            'xs2_venue_name' => null,
        ];

        return [...$row, ...$extra];
    }
}
