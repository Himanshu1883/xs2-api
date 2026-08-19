<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Join legacy catalog tables and English translation rows so admin/public APIs
 * expose Latin event, team, tournament, and venue labels.
 */
class LegacyLocalEventEnglishQuery
{
    public function apply(Builder|BelongsTo $query): void
    {
        $query
            ->leftJoin('stadium as legacy_venues', 'match_info.venue', '=', 'legacy_venues.s_id')
            ->leftJoin('cities as legacy_cities', 'match_info.city', '=', 'legacy_cities.id')
            ->leftJoin('tournament as legacy_tournaments', 'match_info.tournament', '=', 'legacy_tournaments.t_id')
            ->leftJoin('teams as legacy_home_teams', 'match_info.team_1', '=', 'legacy_home_teams.id')
            ->leftJoin('teams as legacy_away_teams', 'match_info.team_2', '=', 'legacy_away_teams.id');

        $select = [
            'legacy_venues.stadium_name as legacy_venue_name',
            'legacy_cities.name as legacy_city_name',
            'legacy_tournaments.tournament_name as legacy_tournament_name',
        ];

        if ($this->supportsTeamTranslations()) {
            $query->leftJoin('teams_lang as legacy_home_teams_en', function ($join): void {
                $join->on('legacy_home_teams_en.team_id', '=', 'match_info.team_1')
                    ->where('legacy_home_teams_en.language', '=', 'en');
                $this->scopeStore($join, 'legacy_home_teams_en', 'teams_lang');
            })->leftJoin('teams_lang as legacy_away_teams_en', function ($join): void {
                $join->on('legacy_away_teams_en.team_id', '=', 'match_info.team_2')
                    ->where('legacy_away_teams_en.language', '=', 'en');
                $this->scopeStore($join, 'legacy_away_teams_en', 'teams_lang');
            });

            $select['legacy_home_team_name'] = DB::raw(
                'COALESCE(NULLIF(legacy_home_teams_en.team_name, ""), legacy_home_teams.team_name) as legacy_home_team_name',
            );
            $select['legacy_away_team_name'] = DB::raw(
                'COALESCE(NULLIF(legacy_away_teams_en.team_name, ""), legacy_away_teams.team_name) as legacy_away_team_name',
            );
        } else {
            $select['legacy_home_team_name'] = 'legacy_home_teams.team_name as legacy_home_team_name';
            $select['legacy_away_team_name'] = 'legacy_away_teams.team_name as legacy_away_team_name';
        }

        if ($this->supportsTournamentTranslations()) {
            $query->leftJoin('tournament_lang as legacy_tournaments_en', function ($join): void {
                $join->on('legacy_tournaments_en.tournament_id', '=', 'match_info.tournament')
                    ->where('legacy_tournaments_en.language', '=', 'en');
                $this->scopeStore($join, 'legacy_tournaments_en', 'tournament_lang');
            });
            $select['legacy_tournament_name'] = DB::raw(
                'COALESCE(NULLIF(legacy_tournaments_en.tournament_name, ""), legacy_tournaments.tournament_name) as legacy_tournament_name',
            );
        }

        $query->addSelect($select);

        if ($this->supportsMatchTranslations()) {
            $query->leftJoin('match_info_lang as legacy_match_names', function ($join): void {
                $join->on('legacy_match_names.match_id', '=', 'match_info.m_id')
                    ->where('legacy_match_names.language', '=', 'en');
                $this->scopeStore($join, 'legacy_match_names', 'match_info_lang');
            })->addSelect(DB::raw('COALESCE(NULLIF(legacy_match_names.match_name, ""), match_info.match_name) as legacy_match_name'));
        }
    }

    /**
     * Match the same English labels exposed in event resources, not only raw
     * legacy columns that may hold IDs or non-display placeholders.
     */
    public function applySearch(Builder $query, string $search): void
    {
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

            if ($this->supportsMatchTranslations()) {
                $query->orWhere('legacy_match_names.match_name', 'like', "%{$search}%");
            }

            if ($this->supportsTeamTranslations()) {
                $query->orWhere('legacy_home_teams_en.team_name', 'like', "%{$search}%")
                    ->orWhere('legacy_away_teams_en.team_name', 'like', "%{$search}%");
            }

            if ($this->supportsTournamentTranslations()) {
                $query->orWhere('legacy_tournaments_en.tournament_name', 'like', "%{$search}%");
            }

            $query->orWhereHas('publicXs2Mappings.xs2Event', function (Builder $xs2Query) use ($search): void {
                $xs2Query->where(function (Builder $xs2Query) use ($search): void {
                    $xs2Query->where('event_name', 'like', "%{$search}%")
                        ->orWhere('hometeam_name', 'like', "%{$search}%")
                        ->orWhere('visitingteam_name', 'like', "%{$search}%")
                        ->orWhere('venue_name', 'like', "%{$search}%")
                        ->orWhere('tournament_name', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%");
                });
            });
        });
    }

    private function supportsMatchTranslations(): bool
    {
        return Schema::hasTable('match_info')
            && Schema::hasTable('match_info_lang')
            && Schema::hasColumns('match_info', ['m_id', 'match_name'])
            && Schema::hasColumns('match_info_lang', ['match_id', 'match_name', 'language']);
    }

    private function supportsTeamTranslations(): bool
    {
        return Schema::hasTable('teams_lang')
            && Schema::hasColumns('teams_lang', ['team_id', 'team_name', 'language']);
    }

    private function supportsTournamentTranslations(): bool
    {
        return Schema::hasTable('tournament_lang')
            && Schema::hasColumns('tournament_lang', ['tournament_id', 'tournament_name', 'language']);
    }

    private function scopeStore(JoinClause $join, string $alias, string $table, string $matchInfoTable = 'match_info'): void
    {
        if (Schema::hasColumn($table, 'store_id') && Schema::hasColumn($matchInfoTable, 'store_id')) {
            $join->on("{$alias}.store_id", '=', "{$matchInfoTable}.store_id");
        }
    }
}
