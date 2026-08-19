<?php

namespace App\Services;

use App\Models\MatchInfo;
use App\Models\Xs2Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PublicEventQueryService
{
    public function __construct(private readonly LegacyLocalEventEnglishQuery $englishLabels) {}

    /** @var array<string, string> */
    private const SORT_COLUMNS = [
        'starts_at' => 'match_date',
        'name' => 'match_name',
        'id' => 'm_id',
    ];

    /**
     * @return array{tournaments: list<array{id:int,name:string,category_id:?int}>}
     */
    public function filterOptions(): array
    {
        return [
            'tournaments' => $this->tournamentOptions(),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->publicEventsQuery()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this->englishLabels->applySearch($query, $search))
            ->when($filters['sport'] ?? null, fn (Builder $query, string $sport) => $query->whereHas(
                'publicXs2Mappings.xs2Event',
                fn (Builder $xs2Query) => $xs2Query->where('sport_type', $sport),
            ))
            ->tap(fn (Builder $query) => $this->applyDateFilters($query, $filters))
            ->when($filters['country'] ?? null, fn (Builder $query, string $country) => $query->whereHas(
                'publicXs2Mappings.xs2Event',
                fn (Builder $xs2Query) => $xs2Query->where('iso_country', strtoupper($country)),
            ))
            ->when($filters['city'] ?? null, function (Builder $query, string $city): void {
                $query->where(function (Builder $query) use ($city): void {
                    $query->where('match_info.city', 'like', "%{$city}%")
                        ->orWhere('legacy_cities.name', 'like', "%{$city}%");
                });
            })
            ->when($filters['venue'] ?? null, function (Builder $query, string $venue): void {
                $query->where(function (Builder $query) use ($venue): void {
                    $query->where('legacy_venues.stadium_name', 'like', "%{$venue}%")
                        ->orWhereHas(
                            'publicXs2Mappings.xs2Event',
                            fn (Builder $xs2Query) => $xs2Query->where('venue_name', 'like', "%{$venue}%"),
                        );
                });
            })
            ->when($filters['tournament_id'] ?? null, function (Builder $query, int|string $tournamentId): void {
                $query->where('match_info.tournament', (int) $tournamentId);
            })
            ->when($filters['tournament'] ?? null, function (Builder $query, string $tournament): void {
                $query->where(function (Builder $query) use ($tournament): void {
                    $query->where('match_info.tournament', 'like', "%{$tournament}%")
                        ->orWhere('legacy_tournaments.tournament_name', 'like', "%{$tournament}%");
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->whereHas(
                'publicXs2Mappings.xs2Event',
                fn (Builder $xs2Query) => $xs2Query->where('event_status', $status),
            ))
            ->when(array_key_exists('has_inventory', $filters), function (Builder $query) use ($filters): void {
                if (filter_var($filters['has_inventory'], FILTER_VALIDATE_BOOLEAN)) {
                    $query->whereHas('publicXs2Mappings.xs2Event', fn (Builder $xs2Query) => $xs2Query->where('number_of_tickets', '>', 0));

                    return;
                }

                $query->whereDoesntHave('publicXs2Mappings.xs2Event', fn (Builder $xs2Query) => $xs2Query->where('number_of_tickets', '>', 0));
            })
            ->orderBy('match_info.'.self::SORT_COLUMNS[$filters['sort'] ?? 'starts_at'], $filters['direction'] ?? 'asc')
            ->orderBy('match_info.m_id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    public function findPublic(MatchInfo $event): MatchInfo
    {
        return $this->publicEventsQuery()
            ->whereKey($event->getKey())
            ->firstOrFail();
    }

    /** @return Builder<MatchInfo> */
    private function publicEventsQuery(): Builder
    {
        return MatchInfo::query()
            ->from('match_info')
            ->select('match_info.*')
            ->tap(fn (Builder $query) => $this->englishLabels->apply($query))
            ->whereDoesntHave('eventMappings', fn (Builder $query) => $query->where('status', 'ignored'))
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('eventMappings')
                    ->orWhereHas('publicXs2Mappings', fn (Builder $mappingQuery) => $mappingQuery->whereHas(
                        'xs2Event',
                        fn (Builder $xs2Query) => $this->applyXs2Availability($xs2Query),
                    ));
            })
            ->with([
                'publicXs2Mappings.xs2Event' => fn (Builder|BelongsTo $query) => $this->applyXs2Availability($query),
            ]);
    }

    private function applyXs2Availability(Builder|BelongsTo $query): void
    {
        $placeholders = implode(', ', array_fill(0, count(Xs2Event::PUBLIC_HIDDEN_STATUSES), '?'));

        $query->whereNull('missing_since')
            ->where(function (Builder $query) use ($placeholders): void {
                $query->whereNull('event_status')
                    ->orWhereRaw("LOWER(TRIM(event_status)) NOT IN ({$placeholders})", Xs2Event::PUBLIC_HIDDEN_STATUSES);
            });
    }

    /**
     * Future events only by default. An explicit past date_to (historical range)
     * opts out so operators can still look up completed fixtures.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $historical = is_string($dateTo) && $dateTo !== '' && $dateTo < now()->toDateString();

        if ($historical) {
            if (is_string($dateFrom) && $dateFrom !== '') {
                $query->whereDate('match_date', '>=', $dateFrom);
            }
        } else {
            $query->where('match_date', '>=', now());
            if (is_string($dateFrom) && $dateFrom !== '') {
                $query->whereDate('match_date', '>=', $dateFrom);
            }
        }

        if (is_string($dateTo) && $dateTo !== '') {
            $query->whereDate('match_date', '<=', $dateTo);
        }
    }

    /**
     * @return list<array{id:int,name:string,category_id:?int}>
     */
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
}
