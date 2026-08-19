<?php

namespace App\Services\Mapping;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyMasterDataSchema
{
    /** @var array<string, list<string>> */
    private array $columns = [];

    public function __construct(private readonly MappingTextNormalizer $text) {}

    public function hasCountries(): bool
    {
        return $this->hasTable('countries');
    }

    public function countryRows(): array
    {
        if (! $this->hasCountries()) {
            return [];
        }

        return DB::table('countries')->get()->all();
    }

    public function countryId(object|array|int $country): ?int
    {
        if (is_int($country)) {
            return $country;
        }

        $row = (array) $country;
        $column = $this->firstColumn('countries', ['id', 'country_id']);
        $value = $column ? ($row[$column] ?? null) : null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function countryName(object|array $country): ?string
    {
        $row = (array) $country;
        $column = $this->firstColumn('countries', ['name', 'country_name']);

        return $column && filled($row[$column] ?? null) ? (string) $row[$column] : null;
    }

    public function countryById(int $id): ?object
    {
        $idColumn = $this->firstColumn('countries', ['id', 'country_id']);

        return $idColumn && $this->hasCountries() ? DB::table('countries')->where($idColumn, $id)->first() : null;
    }

    /** @return list<string> */
    public function countryCodeColumns(): array
    {
        return array_values(array_filter([
            $this->firstColumn('countries', ['iso2', 'iso', 'short_code', 'country_code']),
            // Live Seatsbrokers data uses this legacy field for ISO-like codes.
            $this->firstColumn('countries', ['sortname']),
            $this->firstColumn('countries', ['iso3']),
        ]));
    }

    /** @return list<object> */
    public function citiesForCountry(int $countryId): array
    {
        if (! $this->hasTable('cities')) {
            return [];
        }

        $countryColumn = $this->firstColumn('cities', ['country_id', 'country']);
        if ($countryColumn) {
            return DB::table('cities')->where($countryColumn, $countryId)->get()->all();
        }

        // The inspected production catalog is countries -> states -> cities.
        if ($this->hasTable('states')
            && $this->firstColumn('cities', ['state_id'])
            && $this->firstColumn('states', ['country_id', 'country'])) {
            $cityState = $this->firstColumn('cities', ['state_id']);
            $stateId = $this->firstColumn('states', ['id', 'state_id']);
            $stateCountry = $this->firstColumn('states', ['country_id', 'country']);

            return DB::table('cities as c')
                ->join('states as s', "c.{$cityState}", '=', "s.{$stateId}")
                ->where("s.{$stateCountry}", $countryId)
                ->select('c.*')
                ->get()
                ->all();
        }

        return [];
    }

    public function cityId(object|array $city): ?int
    {
        $row = (array) $city;
        $column = $this->firstColumn('cities', ['id', 'city_id']);
        $value = $column ? ($row[$column] ?? null) : null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function cityName(object|array $city): ?string
    {
        $row = (array) $city;
        $column = $this->firstColumn('cities', ['name', 'city_name']);

        return $column && filled($row[$column] ?? null) ? (string) $row[$column] : null;
    }

    public function cityById(int $id): ?object
    {
        $idColumn = $this->firstColumn('cities', ['id', 'city_id']);

        return $idColumn && $this->hasTable('cities') ? DB::table('cities')->where($idColumn, $id)->first() : null;
    }

    /** @return list<object> */
    public function stadiumsForCity(int $cityId): array
    {
        $table = $this->stadiumTable();
        $cityColumn = $table ? $this->firstColumn($table, ['city_id', 'city']) : null;
        if (! $table || ! $cityColumn) {
            return [];
        }

        return DB::table($table)->where($cityColumn, $cityId)->get()->all();
    }

    /**
     * Local + catalog stadium rows for manual XS2 venue mapping.
     *
     * @return list<array{id:int,name:?string,city_id:?int,country_id:?int,coordinates:array{latitude:?float,longitude:?float},source:string}>
     */
    public function stadiumOptionsForMapping(?int $cityId, string $search = '', ?string $venueNameHint = null): array
    {
        $byId = [];

        foreach ($cityId ? $this->stadiumsForCity($cityId) : [] as $row) {
            $id = $this->stadiumId($row);
            if ($id === null) {
                continue;
            }
            $byId[$id] = $this->stadiumOptionRow($row, 'stadium');
        }

        $needles = array_values(array_unique(array_filter([
            trim($search),
            trim((string) $venueNameHint),
        ])));

        foreach ($needles as $needle) {
            foreach ($this->stadiumIdsMatchingName($needle) as $id) {
                if (isset($byId[$id])) {
                    continue;
                }
                $row = $this->stadiumById($id);
                if ($row) {
                    $byId[$id] = $this->stadiumOptionRow($row, $this->stadiumRowSource($row));
                }
            }
        }

        if (trim($search) !== '') {
            foreach ($this->apiStadiumNameSearch(trim($search), 150) as $row) {
                $id = $this->stadiumId($row);
                if ($id !== null && ! isset($byId[$id])) {
                    $byId[$id] = $this->stadiumOptionRow($row, 'api_stadium');
                }
            }
        }

        if ($search === '' && $cityId === null && $byId === []) {
            foreach ($this->apiStadiumNameSearch('', 200) as $row) {
                $id = $this->stadiumId($row);
                if ($id !== null) {
                    $byId[$id] = $this->stadiumOptionRow($row, 'api_stadium');
                }
            }
        }

        return $this->dedupeStadiumOptionsByName(
            collect($byId)
                ->filter(fn (array $row): bool => $row['id'] !== null)
                ->values()
                ->all(),
            $cityId,
        );
    }

    /**
     * Legacy imports often created many IDs for the same venue name. Keep one
     * row per normalized name, preferring city match, local stadium table, then
     * richest stadium_details coverage for category mapping.
     *
     * @param  list<array{id:int,name:?string,city_id:?int,country_id:?int,coordinates:array{latitude:?float,longitude:?float},source:string}>  $options
     * @return list<array{id:int,name:?string,city_id:?int,country_id:?int,coordinates:array{latitude:?float,longitude:?float},source:string,alternate_ids?:list<int>}>
     */
    private function dedupeStadiumOptionsByName(array $options, ?int $cityId): array
    {
        if ($options === []) {
            return [];
        }

        $detailCounts = $this->stadiumDetailCounts(
            collect($options)->pluck('id')->filter()->map(fn ($id): int => (int) $id)->all(),
        );

        /** @var array<string, array{winner: array<string, mixed>, score: int, ids: list<int>}> $groups */
        $groups = [];

        foreach ($options as $option) {
            $nameKey = $this->text->normalizeStadium((string) ($option['name'] ?? ''));
            $key = $nameKey !== '' ? $nameKey : 'id:'.(string) $option['id'];
            $id = (int) $option['id'];
            $score = $this->stadiumOptionScore($option, $cityId, (int) ($detailCounts[$id] ?? 0));

            if (! isset($groups[$key])) {
                $groups[$key] = ['winner' => $option, 'score' => $score, 'ids' => [$id]];

                continue;
            }

            $groups[$key]['ids'][] = $id;
            if ($score > $groups[$key]['score']) {
                $groups[$key]['winner'] = $option;
                $groups[$key]['score'] = $score;
            }
        }

        return collect($groups)
            ->map(function (array $group): array {
                $option = $group['winner'];
                $winnerId = (int) $option['id'];
                $alternates = array_values(array_unique(array_filter(
                    $group['ids'],
                    fn (int $candidate): bool => $candidate !== $winnerId,
                )));
                if ($alternates !== []) {
                    $option['alternate_ids'] = $alternates;
                }

                return $option;
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** @param list<int> $stadiumIds @return array<int, int> */
    private function stadiumDetailCounts(array $stadiumIds): array
    {
        if ($stadiumIds === [] || ! Schema::hasTable('stadium_details')) {
            return [];
        }

        $stadiumColumn = $this->firstColumn('stadium_details', ['stadium_id', 's_id', 'stadium']);
        if (! $stadiumColumn) {
            return [];
        }

        return DB::table('stadium_details')
            ->whereIn($stadiumColumn, $stadiumIds)
            ->selectRaw("{$stadiumColumn} as stadium_id, count(*) as aggregate")
            ->groupBy($stadiumColumn)
            ->pluck('aggregate', 'stadium_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /** @param array{id:int,name:?string,city_id:?int,country_id:?int,coordinates:array{latitude:?float,longitude:?float},source:string} $option */
    private function stadiumOptionScore(array $option, ?int $cityId, int $detailCount): int
    {
        $score = min($detailCount, 500_000);
        if ($option['source'] === 'stadium') {
            $score += 10_000;
        }
        if ($cityId !== null && (int) ($option['city_id'] ?? 0) === $cityId) {
            $score += 50_000;
        }

        return $score;
    }

    /**
     * Paginated Seatsbrokers venue catalog (`stadium` + `api_stadium`) for public listing.
     *
     * @param  array{search?:string,sort?:string,direction?:string,page?:int,per_page?:int,stadium_ids?:list<int>|null}  $filters
     */
    public function paginateSeatsbrokerVenues(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 24)));
        $sort = ($filters['sort'] ?? 'name') === 'id' ? 'id' : 'name';
        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $stadiumIds = $filters['stadium_ids'] ?? null;

        if (is_array($stadiumIds) && $stadiumIds === []) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        $parts = array_values(array_filter([
            $this->stadiumVenueCatalogQuery(),
            $this->apiStadiumVenueCatalogQuery(),
        ]));

        if ($parts === []) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union = $union->unionAll($part);
        }

        $query = DB::query()
            ->fromSub($union, 'venues')
            ->when(is_array($stadiumIds), function ($query) use ($stadiumIds): void {
                $query->whereIn('id', $stadiumIds);
            })
            ->when($search !== '', function ($query) use ($search): void {
                $needle = '%'.$search.'%';
                $query->where(function ($query) use ($needle): void {
                    $query->where('name', 'like', $needle)
                        ->orWhere('city_name', 'like', $needle);
                });
            })
            ->orderBy($sort, $direction)
            ->orderBy('id');

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => filled($row->name ?? null) ? (string) $row->name : null,
                'city_id' => is_numeric($row->city_id ?? null) ? (int) $row->city_id : null,
                'city_name' => filled($row->city_name ?? null) ? (string) $row->city_name : null,
                'source' => (string) ($row->source ?? 'stadium'),
            ]),
        );

        return $paginator;
    }

    /**
     * @deprecated Prefer paginateSeatsbrokerVenues(); retained for diagnostics.
     *
     * @return list<array{id:int,name:?string,city_id:?int,city_name:?string,source:string}>
     */
    public function seatsbrokerVenueCatalogRows(): array
    {
        return $this->paginateSeatsbrokerVenues([
            'page' => 1,
            'per_page' => 100000,
        ])->items();
    }

    public function countDistinctSeatsbrokerVenues(): int
    {
        $ids = [];

        if ($table = $this->stadiumTable()) {
            $idColumn = $this->firstColumn($table, ['s_id', 'stadium_id', 'id']);
            if ($idColumn) {
                foreach (DB::table($table)->pluck($idColumn) as $id) {
                    if (is_numeric($id)) {
                        $ids[(int) $id] = true;
                    }
                }
            }
        }

        if ($apiTable = $this->apiStadiumTable()) {
            $idColumn = $this->firstColumn($apiTable, ['stadium_id', 's_id', 'id']);
            if ($idColumn) {
                foreach (DB::table($apiTable)->pluck($idColumn) as $id) {
                    if (is_numeric($id)) {
                        $ids[(int) $id] = true;
                    }
                }
            }
        }

        return count($ids);
    }

    /**
     * @return list<int>
     */
    public function stadiumIdsMatchingName(string $name): array
    {
        $table = $this->stadiumTable();
        $idColumn = $table ? $this->firstColumn($table, ['s_id', 'stadium_id', 'id']) : null;
        $nameColumn = $table ? $this->firstColumn($table, ['stadium_name', 'name', 'title']) : null;
        if (! $table || ! $idColumn || ! $nameColumn) {
            return [];
        }

        $needle = implode(' ', $this->text->stadiumTokens($name));
        if ($needle === '') {
            return [];
        }

        return DB::table($table)
            ->select(["{$idColumn} as id", "{$nameColumn} as name"])
            ->get()
            ->filter(function (object $row) use ($needle): bool {
                $haystack = implode(' ', $this->text->stadiumTokens((string) ($row->name ?? '')));

                return $haystack !== '' && str_contains($haystack, $needle);
            })
            ->pluck('id')
            ->merge($this->apiStadiumIdsMatchingName($name))
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function apiStadiumIdsMatchingName(string $name): array
    {
        $table = $this->apiStadiumTable();
        $idColumn = $table ? $this->firstColumn($table, ['stadium_id', 's_id', 'id']) : null;
        $nameColumn = $table ? $this->firstColumn($table, ['stadium_name', 'name', 'title']) : null;
        if (! $table || ! $idColumn || ! $nameColumn) {
            return [];
        }

        $needle = implode(' ', $this->text->stadiumTokens($name));
        if ($needle === '') {
            return [];
        }

        return DB::table($table)
            ->select(["{$idColumn} as id", "{$nameColumn} as name"])
            ->get()
            ->filter(function (object $row) use ($needle): bool {
                $haystack = implode(' ', $this->text->stadiumTokens((string) ($row->name ?? '')));

                return $haystack !== '' && str_contains($haystack, $needle);
            })
            ->pluck('id')
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function stadiumId(object|array $stadium): ?int
    {
        $row = (array) $stadium;
        foreach (array_filter([$this->stadiumTable(), $this->apiStadiumTable()]) as $table) {
            $column = $this->firstColumn($table, ['s_id', 'stadium_id', 'id']);
            $value = $column ? ($row[$column] ?? null) : null;
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    public function stadiumName(object|array $stadium): ?string
    {
        $row = (array) $stadium;
        foreach (array_filter([$this->stadiumTable(), $this->apiStadiumTable()]) as $table) {
            $column = $this->firstColumn($table, ['stadium_name', 'name', 'title']);
            if ($column && filled($row[$column] ?? null)) {
                return (string) $row[$column];
            }
        }

        return null;
    }

    public function stadiumCountryId(object|array $stadium): ?int
    {
        $table = $this->stadiumTable();
        $row = (array) $stadium;
        $column = $table ? $this->firstColumn($table, ['country_id', 'country']) : null;
        $value = $column ? ($row[$column] ?? null) : null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function stadiumCityId(object|array $stadium): ?int
    {
        $table = $this->stadiumTable();
        $row = (array) $stadium;
        $column = $table ? $this->firstColumn($table, ['city_id', 'city']) : null;
        $value = $column ? ($row[$column] ?? null) : null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function stadiumById(int $id): ?object
    {
        $table = $this->stadiumTable();
        $idColumn = $table ? $this->firstColumn($table, ['s_id', 'stadium_id', 'id']) : null;
        if ($table && $idColumn) {
            $row = DB::table($table)->where($idColumn, $id)->first();
            if ($row) {
                return $row;
            }
        }

        $apiTable = $this->apiStadiumTable();
        $apiIdColumn = $apiTable ? $this->firstColumn($apiTable, ['stadium_id', 's_id', 'id']) : null;

        return $apiTable && $apiIdColumn
            ? DB::table($apiTable)->where($apiIdColumn, $id)->first()
            : null;
    }

    /** @return array{latitude:?float,longitude:?float} */
    public function stadiumCoordinates(object|array $stadium): array
    {
        $table = $this->stadiumTable();
        $row = (array) $stadium;
        $lat = $table ? $this->firstColumn($table, ['latitude', 'lat']) : null;
        $lng = $table ? $this->firstColumn($table, ['longitude', 'lng', 'lon']) : null;

        return [
            'latitude' => isset($row[$lat]) && is_numeric($row[$lat]) ? (float) $row[$lat] : null,
            'longitude' => isset($row[$lng]) && is_numeric($row[$lng]) ? (float) $row[$lng] : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function stadiumDetailsForStadium(int $stadiumId): array
    {
        if (! $this->hasTable('stadium_details')) {
            return [];
        }

        $stadiumColumn = $this->firstColumn('stadium_details', ['stadium_id', 's_id', 'stadium']);
        if (! $stadiumColumn) {
            return [];
        }

        $details = DB::table('stadium_details as d')
            ->where("d.{$stadiumColumn}", $stadiumId)
            ->select('d.*')
            ->get();

        $detailIdColumn = $this->firstColumn('stadium_details', ['id', 'stadium_detail_id', 'sd_id']);
        $blockColumn = $this->firstColumn('stadium_details', ['block_id', 'block', 'full_block_name']);
        $nameColumn = $this->firstColumn('stadium_details', ['full_block_name', 'name', 'display_name', 'title']);
        $sectionColumn = $this->firstColumn('stadium_details', ['section', 'section_name']);
        $seatColumn = $this->firstColumn('stadium_details', ['stadium_seat_id', 'seat_id', 'category']);
        $seatNames = $this->seatNames($details, $seatColumn);

        return $details->map(function (object $detail) use ($detailIdColumn, $stadiumColumn, $blockColumn, $nameColumn, $sectionColumn, $seatColumn, $seatNames): array {
            $row = (array) $detail;
            $seatId = $seatColumn && is_numeric($row[$seatColumn] ?? null) ? (int) $row[$seatColumn] : null;

            return [
                'stadium_detail_id' => $detailIdColumn && is_numeric($row[$detailIdColumn] ?? null) ? (int) $row[$detailIdColumn] : null,
                'stadium_id' => is_numeric($row[$stadiumColumn] ?? null) ? (int) $row[$stadiumColumn] : null,
                'stadium_seat_id' => $seatId,
                'stadium_seat_name' => $seatId ? ($seatNames[$seatId] ?? null) : null,
                'block' => $blockColumn && filled($row[$blockColumn] ?? null) ? (string) $row[$blockColumn] : null,
                'section' => $sectionColumn && filled($row[$sectionColumn] ?? null) ? (string) $row[$sectionColumn] : null,
                'name' => $nameColumn && filled($row[$nameColumn] ?? null) ? (string) $row[$nameColumn] : null,
                'raw' => $row,
            ];
        })->filter(fn (array $detail): bool => $detail['stadium_detail_id'] !== null)->values()->all();
    }

    /**
     * @return list<array{stadium_seat_id:int,stadium_seat_name:string,detail_count:int,details:list<array{stadium_detail_id:int,name:string}>}>
     */
    public function stadiumSeatCategoriesForStadium(int $stadiumId): array
    {
        return collect($this->stadiumDetailsForStadium($stadiumId))
            ->filter(fn (array $detail): bool => $detail['stadium_seat_id'] !== null && filled($detail['stadium_seat_name'] ?? null))
            ->groupBy('stadium_seat_id')
            ->map(fn (Collection $rows, int|string $seatId): array => [
                'stadium_seat_id' => (int) $seatId,
                'stadium_seat_name' => (string) $rows->first()['stadium_seat_name'],
                'detail_count' => $rows->count(),
                'details' => $rows
                    ->map(fn (array $row): array => [
                        'stadium_detail_id' => $row['stadium_detail_id'],
                        'name' => $row['name'] ?? $row['block'] ?? $row['section'] ?? "Detail #{$row['stadium_detail_id']}",
                    ])
                    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all(),
            ])
            ->sortBy('stadium_seat_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function stadiumTable(): ?string
    {
        foreach (['stadium', 'stadiums'] as $table) {
            if ($this->hasTable($table)) {
                return $table;
            }
        }

        return null;
    }

    public function apiStadiumTable(): ?string
    {
        return $this->hasTable('api_stadium') ? 'api_stadium' : null;
    }

    private function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return $this->columns[$table] ??= ($this->hasTable($table) ? Schema::getColumnListing($table) : []);
    }

    /** @param list<string> $candidates */
    private function firstColumn(string $table, array $candidates): ?string
    {
        $columns = $this->columns($table);
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<object> */
    private function apiStadiumNameSearch(string $search, int $limit = 100): array
    {
        $table = $this->apiStadiumTable();
        $nameColumn = $table ? $this->firstColumn($table, ['stadium_name', 'name', 'title']) : null;
        if (! $table || ! $nameColumn) {
            return [];
        }

        $query = DB::table($table)->orderBy($nameColumn);
        if ($search !== '') {
            $query->where($nameColumn, 'like', '%'.$search.'%');
        }

        return $query->limit(max(1, $limit))->get()->all();
    }

    /** @return array{id:int,name:?string,city_id:?int,city_name:?string,source:string} */
    private function enrichVenueCatalogRow(array $option): array
    {
        $cityName = null;
        if ($option['city_id'] !== null) {
            $city = $this->cityById($option['city_id']);
            $cityName = $city ? $this->cityName($city) : null;
        }

        return [
            'id' => $option['id'],
            'name' => $option['name'],
            'city_id' => $option['city_id'],
            'city_name' => $cityName,
            'source' => $option['source'],
        ];
    }

    private function stadiumVenueCatalogQuery(): ?Builder
    {
        $table = $this->stadiumTable();
        $idColumn = $table ? $this->firstColumn($table, ['s_id', 'stadium_id', 'id']) : null;
        $nameColumn = $table ? $this->firstColumn($table, ['stadium_name', 'name', 'title']) : null;
        if (! $table || ! $idColumn || ! $nameColumn) {
            return null;
        }

        $cityColumn = $this->firstColumn($table, ['city_id', 'city']);
        $cityIdColumn = $this->firstColumn('cities', ['id', 'city_id']);
        $cityNameColumn = $this->firstColumn('cities', ['name', 'city_name']);

        $query = DB::table("{$table} as s");
        if ($cityColumn && $this->hasTable('cities') && $cityIdColumn && $cityNameColumn) {
            return $query
                ->leftJoin('cities as c', "s.{$cityColumn}", '=', "c.{$cityIdColumn}")
                ->select([
                    DB::raw("s.{$idColumn} as id"),
                    DB::raw("s.{$nameColumn} as name"),
                    DB::raw("s.{$cityColumn} as city_id"),
                    DB::raw("c.{$cityNameColumn} as city_name"),
                    DB::raw("'stadium' as source"),
                ]);
        }

        return $query->select([
            DB::raw("s.{$idColumn} as id"),
            DB::raw("s.{$nameColumn} as name"),
            DB::raw('NULL as city_id'),
            DB::raw('NULL as city_name'),
            DB::raw("'stadium' as source"),
        ]);
    }

    private function apiStadiumVenueCatalogQuery(): ?Builder
    {
        $apiTable = $this->apiStadiumTable();
        $idColumn = $apiTable ? $this->firstColumn($apiTable, ['stadium_id', 's_id', 'id']) : null;
        $nameColumn = $apiTable ? $this->firstColumn($apiTable, ['stadium_name', 'name', 'title']) : null;
        if (! $apiTable || ! $idColumn || ! $nameColumn) {
            return null;
        }

        $query = DB::table("{$apiTable} as a")->select([
            DB::raw("a.{$idColumn} as id"),
            DB::raw("a.{$nameColumn} as name"),
            DB::raw('NULL as city_id'),
            DB::raw('NULL as city_name'),
            DB::raw("'api_stadium' as source"),
        ]);

        $stadiumTable = $this->stadiumTable();
        $stadiumIdColumn = $stadiumTable ? $this->firstColumn($stadiumTable, ['s_id', 'stadium_id', 'id']) : null;
        if ($stadiumTable && $stadiumIdColumn) {
            $query->leftJoin("{$stadiumTable} as ls", "a.{$idColumn}", '=', "ls.{$stadiumIdColumn}")
                ->whereNull("ls.{$stadiumIdColumn}");
        }

        return $query;
    }

    /** @return array{id:int,name:?string,city_id:?int,country_id:?int,coordinates:array{latitude:?float,longitude:?float},source:string} */
    private function stadiumOptionRow(object $stadium, string $source): array
    {
        $coordinates = $this->stadiumCoordinates($stadium);

        return [
            'id' => $this->stadiumId($stadium),
            'name' => $this->stadiumName($stadium),
            'city_id' => $this->stadiumCityId($stadium),
            'country_id' => $this->stadiumCountryId($stadium),
            'coordinates' => $coordinates,
            'source' => $source,
        ];
    }

    private function stadiumRowSource(object $row): string
    {
        $array = (array) $row;
        if (array_key_exists('stadium_id', $array) && ! array_key_exists('s_id', $array)) {
            return 'api_stadium';
        }

        return 'stadium';
    }

    /** @param Collection<int, object> $details @return array<int, string> */
    private function seatNames($details, ?string $seatColumn): array
    {
        if (! $seatColumn || ! $this->hasTable('stadium_seats')) {
            return [];
        }

        $ids = $details->pluck($seatColumn)->filter(fn ($id): bool => is_numeric($id))->map(fn ($id): int => (int) $id)->unique();
        if ($ids->isEmpty()) {
            return [];
        }

        $idColumn = $this->firstColumn('stadium_seats', ['id', 'stadium_seat_id']);
        $nameColumn = $this->firstColumn('stadium_seats', ['seat_category', 'name', 'category_name']);
        if (! $idColumn || ! $nameColumn) {
            return [];
        }

        return DB::table('stadium_seats')
            ->whereIn($idColumn, $ids->all())
            ->pluck($nameColumn, $idColumn)
            ->map(fn ($name): string => (string) $name)
            ->all();
    }
}
