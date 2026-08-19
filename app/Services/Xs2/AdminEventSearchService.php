<?php

namespace App\Services\Xs2;

use App\Models\MatchInfo;
use App\Services\LegacyLocalEventEnglishQuery;
use App\Services\Mapping\CategoryCompatibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

class AdminEventSearchService
{
    public function __construct(
        private readonly LegacyLocalEventEnglishQuery $englishLabels,
        private readonly CategoryCompatibilityService $categoryCompatibility,
    ) {}

    /** @param array<string, mixed> $filters
     * @return Collection<int, MatchInfo>
     */
    public function search(array $filters): Collection
    {
        // Default to upcoming local events. Explicit date_from/date_to still
        // work (mapping review uses a ±2 day window around the XS2 start).
        // A past-only date_to opts into a historical search window.
        $dateTo = $filters['date_to'] ?? now()->addMonths(3)->toDateString();
        $historical = is_string($dateTo) && $dateTo !== '' && $dateTo < now()->toDateString();
        $dateFrom = (is_string($filters['date_from'] ?? null) && ($filters['date_from'] ?? '') !== '')
            ? (string) $filters['date_from']
            : ($historical ? now()->subMonths(3)->toDateString() : now()->format('Y-m-d H:i:s'));

        $hasCategory = Schema::hasColumn('match_info', 'category');
        $hasTournament = Schema::hasColumn('match_info', 'tournament');

        return MatchInfo::query()
            ->from('match_info')
            ->select('match_info.*')
            ->tap(fn (Builder $query) => $this->englishLabels->apply($query))
            ->with('publicXs2Mappings.xs2Event')
            ->where('match_info.match_date', '>=', $dateFrom)
            ->whereDate('match_info.match_date', '<=', $dateTo)
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('match_info.match_name', 'like', "%{$search}%")
                        ->orWhere('match_info.team_1', 'like', "%{$search}%")
                        ->orWhere('match_info.team_2', 'like', "%{$search}%")
                        ->orWhere('match_info.city', 'like', "%{$search}%")
                        ->orWhere('match_info.tournament', 'like', "%{$search}%")
                        ->orWhere('legacy_home_teams.team_name', 'like', "%{$search}%")
                        ->orWhere('legacy_away_teams.team_name', 'like', "%{$search}%")
                        ->orWhere('legacy_cities.name', 'like', "%{$search}%")
                        ->orWhere('legacy_tournaments.tournament_name', 'like', "%{$search}%")
                        ->orWhere('legacy_venues.stadium_name', 'like', "%{$search}%");
                });
            })

            ->when($filters['category_id'] ?? null, function (Builder $query, int $categoryId) use ($hasCategory): void {
                if (! $hasCategory) {
                    return;
                }

                $compatibleCategoryIds = $this->categoryCompatibility->compatibleCategoryIdsForCategoryId($categoryId);
                if (count($compatibleCategoryIds) > 1) {
                    $query->whereIn('match_info.category', $compatibleCategoryIds);

                    return;
                }

                $query->where('match_info.category', $categoryId);
            })
            ->when($filters['tournament_id'] ?? null, fn (Builder $query, int $tournamentId) => $query->when(
                $hasTournament,
                fn (Builder $query) => $query->where('match_info.tournament', $tournamentId),
            ))
            ->when($filters['venue'] ?? null, function (Builder $query, string $venue): void {
                $query->where(function (Builder $query) use ($venue): void {
                    $query->where('legacy_venues.stadium_name', 'like', "%{$venue}%")
                        ->orWhereHas(
                            'publicXs2Mappings.xs2Event',
                            fn (Builder $xs2Query) => $xs2Query->where('venue_name', 'like', "%{$venue}%"),
                        );
                });
            })
            ->when($filters['tournament'] ?? null, function (Builder $query, string $tournament): void {
                $query->where(function (Builder $query) use ($tournament): void {
                    $query->where('match_info.tournament', 'like', "%{$tournament}%")
                        ->orWhere('legacy_tournaments.tournament_name', 'like', "%{$tournament}%");
                });
            })
            ->orderBy('match_info.match_date')
            ->orderBy('match_info.m_id')
            ->limit($filters['limit'] ?? 20)
            ->get();
    }
}
