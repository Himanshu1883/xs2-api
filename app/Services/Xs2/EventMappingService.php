<?php

namespace App\Services\Xs2;

use App\Jobs\ReconcileSellerListingsForMapping;
use App\Jobs\SyncXs2CategoriesForEvent;
use App\Models\EventMapping;
use App\Models\MatchInfo;
use App\Models\Xs2Category;
use App\Models\Xs2Event;
use App\Services\Mapping\CategoryCompatibilityService;
use App\Services\Mapping\StadiumCategoryMappingService;
use App\Services\Mapping\StadiumMappingService;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class EventMappingService
{
    /** @var array<string, bool>|null */
    private ?array $candidateReferenceSchema = null;

    public function __construct(
        private readonly Xs2TextNormalizer $text,
        private readonly LocalEventCreator $localEventCreator,
        private readonly StadiumMappingService $stadiumMappings,
        private readonly StadiumCategoryMappingService $categoryMappings,
        private readonly CategoryCompatibilityService $categoryCompatibility,
    ) {}

    public function map(Xs2Event $xs2Event, bool $force = false, ?float $autoMapThreshold = null): EventMapping
    {
        return DB::transaction(function () use ($xs2Event, $force, $autoMapThreshold): EventMapping {
            // Lock the XS2 event first. It serializes mapping creation when
            // there is not yet an event_mappings row to lock.
            $lockedXs2Event = Xs2Event::query()->lockForUpdate()->findOrFail($xs2Event->id);
            $existing = EventMapping::query()
                ->where('xs2_event_id', $lockedXs2Event->id)
                ->lockForUpdate()
                ->first();

            if ($existing?->status === 'created') {
                return $existing;
            }

            if ($existing?->status === 'ignored') {
                if ($force) {
                    $this->conflict('Ignored mappings must be reopened before recalculation.');
                }

                return $existing;
            }

            // A background synchronization must never replace an admin's
            // manual decision. Force is reserved for an explicit admin
            // recalculation after its own transition checks.
            if (! $force && $existing?->mapping_method === 'manual') {
                return $existing;
            }

            return $this->mapLocked($lockedXs2Event, $existing, $autoMapThreshold);
        }, 3);
    }

    private function mapLocked(Xs2Event $xs2Event, ?EventMapping $mapping, ?float $autoMapThresholdOverride = null): EventMapping
    {
        $autoMapThreshold = $this->eventAutoMapThreshold($autoMapThresholdOverride);
        $pendingThreshold = $this->eventPendingThreshold();
        // Never let Carbon::parse(null) turn an incomplete supplier record
        // into a match for an event happening now. A start date is a required
        // safety signal for every automatic decision, including exact ones.
        if (! $this->startDate($xs2Event)) {
            return $this->save(
                $mapping,
                $xs2Event,
                null,
                'pending',
                'automatic',
                0,
                [
                    'reason' => 'missing_start_date',
                    'requires_start_date' => true,
                ],
            );
        }

        $exact = $this->findExact($xs2Event, $mapping?->m_id);
        if ($exact) {
            if ($conflictingMapping = $this->otherPublicMappingForLocalEvent((int) $exact->m_id, $mapping?->id)) {
                return $this->pendingBecauseLocalEventIsMapped(
                    $mapping,
                    $xs2Event,
                    $exact,
                    100,
                    $conflictingMapping,
                    [
                        'final_score' => 100,
                        'reason' => 'external_id_or_exact_match_details',
                    ],
                );
            }

            return $this->save($mapping, $xs2Event, $exact, 'mapped', 'exact', 100, [
                'candidate_event_id' => $exact->m_id,
                'final_score' => 100,
                'reason' => 'external_id_or_exact_match_details',
            ], $autoMapThreshold);
        }

        $candidates = $this->findCandidates($xs2Event);
        $scored = $candidates
            ->map(fn (MatchInfo $candidate): array => $this->score($xs2Event, $candidate))
            ->sortByDesc('final_score')
            ->values();
        $best = $scored->first();

        if ($best && $best['final_score'] >= $autoMapThreshold) {
            if ($conflictingMapping = $this->otherPublicMappingForLocalEvent((int) $best['event']->m_id, $mapping?->id)) {
                $result = $this->pendingBecauseLocalEventIsMapped(
                    $mapping,
                    $xs2Event,
                    $best['event'],
                    $best['final_score'],
                    $conflictingMapping,
                    $this->details($best),
                );
                $this->logAutomaticMappingDecision($xs2Event, $candidates, $best, $result);

                return $result;
            }

            $result = $this->save(
                $mapping,
                $xs2Event,
                $best['event'],
                'mapped',
                'automatic',
                $best['final_score'],
                $this->details($best),
                $autoMapThreshold,
            );
            $this->logAutomaticMappingDecision($xs2Event, $candidates, $best, $result);

            return $result;
        }

        if ($best && $best['final_score'] >= $pendingThreshold) {
            $result = $this->save(
                $mapping,
                $xs2Event,
                null,
                'pending',
                'automatic',
                $best['final_score'],
                [
                    'best_match' => $this->details($best),
                    'candidates' => $scored->take(5)->map(fn (array $item): array => [
                        'event_id' => $item['event']->m_id,
                        'score' => $item['final_score'],
                    ])->all(),
                ],
            );
            $this->logAutomaticMappingDecision($xs2Event, $candidates, $best, $result);

            return $result;
        }

        $result = $this->save(
            $mapping,
            $xs2Event,
            null,
            'pending',
            'automatic',
            $best['final_score'] ?? 0,
            [
                'reason' => 'no_reliable_local_candidate',
                'requires_local_reference_resolution' => true,
                'local_references' => [
                    'home_team' => [
                        'external_id' => $xs2Event->hometeam_id,
                        'name' => $xs2Event->hometeam_name,
                    ],
                    'away_team' => [
                        'external_id' => $xs2Event->visitingteam_id,
                        'name' => $xs2Event->visitingteam_name,
                    ],
                    'city' => [
                        'external_id' => $xs2Event->location_id,
                        'name' => $xs2Event->city,
                    ],
                    'tournament' => [
                        'external_id' => $xs2Event->tournament_id,
                        'name' => $xs2Event->tournament_name,
                    ],
                ],
            ],
        );

        $this->logAutomaticMappingDecision($xs2Event, $candidates, $best, $result);

        return $result;
    }

    public function manuallyMap(EventMapping $mapping, int $eventId, int $reviewerId): EventMapping
    {
        return DB::transaction(function () use ($mapping, $eventId, $reviewerId): EventMapping {
            $lockedMapping = $this->lockMapping($mapping);
            $this->requireStatus($lockedMapping, ['pending', 'mapped'], 'map');
            $event = MatchInfo::query()->lockForUpdate()->findOrFail($eventId);
            $localEventId = (int) $event->m_id;

            if ($lockedMapping->m_id !== null
                && (int) $lockedMapping->m_id === $localEventId
                && in_array($lockedMapping->status, ['mapped', 'created'], true)) {
                return $lockedMapping->fresh();
            }

            $this->releaseConflictingLocalEventMapping($localEventId, $lockedMapping->id);

            $lockedMapping->update([
                'm_id' => $localEventId,
                'status' => 'mapped',
                'mapping_method' => 'manual',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);

            $this->queueListingReconciliation($lockedMapping);

            $this->syncVenueMapping($lockedMapping->xs2Event, $event);

            return $lockedMapping->fresh();
        }, 3);
    }

    /** @param array{category_id?: int, tournament_id?: int, home_team_id?: int, away_team_id?: int, city_id?: int, venue_id?: int} $options */
    public function createLocalEvent(EventMapping $mapping, int $reviewerId, array $options = []): EventMapping
    {
        return DB::transaction(function () use ($mapping, $reviewerId, $options): EventMapping {
            $lockedMapping = $this->lockMapping($mapping);
            $this->requireStatus($lockedMapping, ['pending'], 'create a local event for');

            if ($lockedMapping->m_id !== null) {
                $this->conflict('This mapping already has a local event. Refresh and try again.');
            }

            // LocalEventCreator performs the schema-aware duplicate check and
            // creates only verified match_info fields inside its own transaction.
            $localEvent = $this->localEventCreator->findOrCreate(
                $lockedMapping->xs2Event,
                $options,
            );
            $this->ensureLocalEventCanBePublished((int) $localEvent->m_id, $lockedMapping->id);

            $lockedMapping->update([
                'm_id' => $localEvent->m_id,
                'status' => 'created',
                'mapping_method' => 'created',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);

            $this->queueListingReconciliation($lockedMapping);

            $this->syncVenueMapping($lockedMapping->xs2Event, $localEvent);

            return $lockedMapping->fresh();
        }, 3);
    }

    public function ignore(EventMapping $mapping, int $reviewerId): EventMapping
    {
        return DB::transaction(function () use ($mapping, $reviewerId): EventMapping {
            $lockedMapping = $this->lockMapping($mapping);
            $this->requireStatus($lockedMapping, ['pending', 'mapped'], 'ignore');

            $lockedMapping->update([
                // Ignored records must not remain linked to a public XS2 mapping.
                'm_id' => null,
                'status' => 'ignored',
                'mapping_method' => 'manual',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);

            $this->queueListingReconciliation($lockedMapping);

            return $lockedMapping->fresh();
        }, 3);
    }

    public function reopen(EventMapping $mapping): EventMapping
    {
        return DB::transaction(function () use ($mapping): EventMapping {
            $lockedMapping = $this->lockMapping($mapping);
            $this->requireStatus($lockedMapping, ['mapped', 'ignored'], 'reopen');

            $lockedMapping->update([
                'status' => 'pending',
                'mapping_method' => 'automatic',
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);

            $this->queueListingReconciliation($lockedMapping);

            return $lockedMapping->fresh();
        }, 3);
    }

    public function recalculate(EventMapping $mapping, bool $force = false, ?float $autoMapThreshold = null): EventMapping
    {
        return DB::transaction(function () use ($mapping, $force, $autoMapThreshold): EventMapping {
            $lockedMapping = $this->lockMapping($mapping);

            if ($lockedMapping->status === 'created') {
                $this->conflict('Created mappings cannot be recalculated because their local event is canonical.');
            }

            if ($lockedMapping->status === 'ignored') {
                $this->conflict('Ignored mappings must be reopened before recalculation.');
            }

            if (! $force && $lockedMapping->mapping_method === 'manual') {
                $this->conflict('Manual mappings require force to recalculate.');
            }

            $this->requireStatus($lockedMapping, ['pending', 'mapped'], 'recalculate');

            return $this->mapLocked($lockedMapping->xs2Event, $lockedMapping, $autoMapThreshold);
        }, 3);
    }

    private function findExact(Xs2Event $xs2Event, ?int $preferredEventId = null): ?MatchInfo
    {
        $references = $this->candidateReferences();
        $byExternalId = $references['external_event_id']
            ? MatchInfo::query()
                ->where('xs2event_id', $xs2Event->external_event_id)
                ->when($references['status'], fn ($query) => $query->where('status', 1))
                ->first()
            : null;

        if ($byExternalId) {
            return $byExternalId;
        }

        $start = $this->startDate($xs2Event);
        if (! $start) {
            return null;
        }

        $isExactFallback = function (MatchInfo $candidate) use ($xs2Event, $start): bool {
            $home = $this->text->normalize($xs2Event->hometeam_name);
            $away = $this->text->normalize($xs2Event->visitingteam_name);
            $candidateHome = $this->text->normalize($this->candidateText($candidate, 'local_home_team_name', 'team_1'));
            $candidateAway = $this->text->normalize($this->candidateText($candidate, 'local_away_team_name', 'team_2'));
            $candidateCity = $this->text->normalize($this->candidateText($candidate, 'local_city_name', 'city'));

            return $home !== '' && $away !== ''
                && $this->isWithinOneCalendarDay($start, $candidate)
                && $candidateHome === $home
                && $candidateAway === $away
                && ($candidateCity === ''
                    || $this->text->normalize($xs2Event->city) === ''
                    || $candidateCity === $this->text->normalize($xs2Event->city));
        };

        $candidates = $this->findCandidates($xs2Event);
        if ($preferredEventId !== null) {
            $preferred = $candidates->firstWhere('m_id', $preferredEventId);
            if ($preferred && $isExactFallback($preferred)) {
                return $preferred;
            }
        }

        return $candidates->first($isExactFallback);
    }

    /** @return Collection<int, MatchInfo> */
    private function findCandidates(Xs2Event $xs2Event): Collection
    {
        $start = $this->startDate($xs2Event);
        $references = $this->candidateReferences();
        if (! $start || ! $references['match_date']) {
            return new Collection;
        }

        $query = MatchInfo::query()
            ->from('match_info')
            ->select('match_info.*');

        if ($references['localized_name']) {
            $query->leftJoin('match_info_lang as local_names', function ($join) use ($references): void {
                $join->on('local_names.match_id', '=', 'match_info.m_id')
                    ->where('local_names.language', '=', 'en');

                if ($references['match_store']) {
                    $join->on('local_names.store_id', '=', 'match_info.store_id');
                }
            })->addSelect(DB::raw('COALESCE(local_names.match_name, match_info.match_name) as local_match_name'));
        }

        if ($references['venue']) {
            $query->leftJoin('stadium as local_venues', 'match_info.venue', '=', 'local_venues.s_id')
                ->addSelect('local_venues.stadium_name as local_venue_name');
        }

        if ($references['city']) {
            $query->leftJoin('cities as local_cities', 'match_info.city', '=', 'local_cities.id')
                ->addSelect('local_cities.name as local_city_name');
        }

        if ($references['tournament']) {
            $query->leftJoin('tournament as local_tournaments', 'match_info.tournament', '=', 'local_tournaments.t_id')
                ->addSelect('local_tournaments.tournament_name as local_tournament_name');
        }

        if ($references['teams']) {
            $query->leftJoin('teams as local_home_teams', 'match_info.team_1', '=', 'local_home_teams.id')
                ->leftJoin('teams as local_away_teams', 'match_info.team_2', '=', 'local_away_teams.id')
                ->addSelect([
                    'local_home_teams.team_name as local_home_team_name',
                    'local_away_teams.team_name as local_away_team_name',
                ]);
        }

        $from = $start->copy()->startOfDay()->subDay();
        $to = $start->copy()->endOfDay()->addDay();

        return $query
            ->whereBetween('match_date', [$from, $to])
            ->whereNotNull('match_date')
            ->when($references['status'], fn ($query) => $query->where('match_info.status', 1))
            ->when(
                $this->compatibleCategoryIdsForCandidateSearch($xs2Event),
                fn ($query, array $categoryIds) => $query->whereIn('match_info.category', $categoryIds),
            )
            ->orderBy('match_date')
            ->limit(200)
            ->get();
    }

    /** @return list<int>|null */
    private function compatibleCategoryIdsForCandidateSearch(Xs2Event $xs2Event): ?array
    {
        if (! Schema::hasColumn('match_info', 'category')) {
            return null;
        }

        return $this->categoryCompatibility->compatibleCategoryIdsForXs2Event($xs2Event);
    }

    /** @return array{event: MatchInfo, event_name_score: float, date_score: float, home_team_score: float, away_team_score: float, venue_score: float, city_score: float, tournament_score: float, final_score: float} */
    private function score(Xs2Event $xs2Event, MatchInfo $candidate): array
    {
        $eventName = $this->text->similarity($xs2Event->event_name, $this->candidateText($candidate, 'local_match_name', 'match_name'));
        $start = $this->startDate($xs2Event);
        $candidateStart = $this->candidateStartDate($candidate);
        $daysApart = $this->calendarDaysApart($start, $candidateStart);
        $date = match (true) {
            $daysApart === null => 0.0,
            $daysApart <= 1 => 100.0,
            default => max(0, 100 - ((abs($start->diffInMinutes($candidateStart, false)) / 1440) * 100)),
        };
        $home = $this->text->similarity($xs2Event->hometeam_name, $this->candidateText($candidate, 'local_home_team_name', 'team_1'));
        $away = $this->text->similarity($xs2Event->visitingteam_name, $this->candidateText($candidate, 'local_away_team_name', 'team_2'));
        $venue = $this->text->similarity($xs2Event->venue_name, $this->localVenue($candidate));
        $city = $this->text->similarity($xs2Event->city, $this->candidateText($candidate, 'local_city_name', 'city'));
        $tournament = $this->text->similarity($xs2Event->tournament_name, $this->candidateText($candidate, 'local_tournament_name', 'tournament'));

        return [
            'event' => $candidate,
            'event_name_score' => $eventName,
            'date_score' => round($date, 2),
            'days_apart' => $daysApart,
            'home_team_score' => $home,
            'away_team_score' => $away,
            'venue_score' => $venue,
            'city_score' => $city,
            'tournament_score' => $tournament,
            'final_score' => round(($eventName * .30) + ($date * .25) + ((($home + $away) / 2) * .25) + (max($venue, $city) * .10) + ($tournament * .10), 2),
        ];
    }

    private function localVenue(MatchInfo $candidate): ?string
    {
        return $this->candidateText($candidate, 'local_venue_name', 'venue_name', 'stadium_name', 'venue');
    }

    private function candidateText(MatchInfo $candidate, string ...$attributes): ?string
    {
        foreach ($attributes as $attribute) {
            $value = $candidate->getAttribute($attribute);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value !== '' && ! preg_match('/^-?\d+$/', $value)) {
                return $value;
            }
        }

        return null;
    }

    private function startDate(Xs2Event $xs2Event): ?Carbon
    {
        return $this->asCarbon($xs2Event->date_start_local);
    }

    private function candidateStartDate(MatchInfo $candidate): ?Carbon
    {
        return $this->asCarbon($candidate->match_date);
    }

    private function asCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function isWithinOneCalendarDay(Carbon $start, MatchInfo $candidate): bool
    {
        $candidateStart = $this->candidateStartDate($candidate);
        $daysApart = $this->calendarDaysApart($start, $candidateStart);

        return $daysApart !== null && $daysApart <= 1;
    }

    private function calendarDaysApart(?Carbon $first, ?Carbon $second): ?int
    {
        if (! $first instanceof Carbon || ! $second instanceof Carbon) {
            return null;
        }

        return (int) abs($first->copy()->startOfDay()->diffInDays($second->copy()->startOfDay(), false));
    }

    /** @return array<string, bool> */
    private function candidateReferences(): array
    {
        if ($this->candidateReferenceSchema !== null) {
            return $this->candidateReferenceSchema;
        }

        if (! Schema::hasTable('match_info')) {
            return $this->candidateReferenceSchema = [
                'external_event_id' => false,
                'match_date' => false,
                'localized_name' => false,
                'match_store' => false,
                'venue' => false,
                'city' => false,
                'tournament' => false,
                'teams' => false,
                'status' => false,
            ];
        }

        $matchInfoColumns = array_flip(Schema::getColumnListing('match_info'));
        $hasMatchInfoColumn = fn (string $column): bool => isset($matchInfoColumns[$column]);
        $hasTableColumns = fn (string $table, array $columns): bool => Schema::hasTable($table)
            && Schema::hasColumns($table, $columns);

        $localizedName = $hasMatchInfoColumn('m_id')
            && $hasMatchInfoColumn('match_name')
            && $hasTableColumns('match_info_lang', ['match_id', 'match_name', 'language']);

        return $this->candidateReferenceSchema = [
            'external_event_id' => $hasMatchInfoColumn('xs2event_id'),
            'match_date' => $hasMatchInfoColumn('match_date'),
            'localized_name' => $localizedName,
            'match_store' => $localizedName
                && $hasMatchInfoColumn('store_id')
                && $hasTableColumns('match_info_lang', ['store_id']),
            'venue' => $hasMatchInfoColumn('venue')
                && $hasTableColumns('stadium', ['s_id', 'stadium_name']),
            'city' => $hasMatchInfoColumn('city')
                && $hasTableColumns('cities', ['id', 'name']),
            'tournament' => $hasMatchInfoColumn('tournament')
                && $hasTableColumns('tournament', ['t_id', 'tournament_name']),
            'teams' => $hasMatchInfoColumn('team_1')
                && $hasMatchInfoColumn('team_2')
                && $hasTableColumns('teams', ['id', 'team_name']),
            'status' => $hasMatchInfoColumn('status'),
        ];
    }

    /** @param array<string, mixed> $score */
    private function details(array $score): array
    {
        unset($score['event']);
        $score['candidate_event_id'] = $score['candidate_event_id'] ?? null;

        return $score;
    }

    /** @param array<string, mixed> $details */
    private function save(
        ?EventMapping $mapping,
        Xs2Event $xs2Event,
        ?MatchInfo $event,
        string $status,
        string $method,
        float $score,
        array $details,
        ?float $inventoryAutoMapThreshold = null,
    ): EventMapping {
        if ($event) {
            $this->ensureLocalEventCanBePublished((int) $event->m_id, $mapping?->id);
        }

        $details['candidate_event_id'] = $event?->m_id ?? ($details['candidate_event_id'] ?? null);
        $attributes = [
            'm_id' => $event?->m_id,
            'status' => $status,
            'mapping_method' => $method,
            'match_score' => $score,
            'match_details' => $details,
        ];

        if (! $mapping) {
            $mapping = EventMapping::query()->create(['xs2_event_id' => $xs2Event->id, ...$attributes]);
            $this->queueListingReconciliation($mapping);

            if (in_array($status, ['mapped', 'created'], true)) {
                $this->queueCategorySync($mapping);
            }

            if ($status === 'mapped' && $event instanceof MatchInfo) {
                $this->applyInventoryMappings($xs2Event, $event, $score, $inventoryAutoMapThreshold);
            }

            return $mapping->fresh();
        }

        $mapping->update($attributes);

        if ($mapping->wasChanged(['m_id', 'status'])) {
            $this->queueListingReconciliation($mapping);

            if (in_array($status, ['mapped', 'created'], true)) {
                $this->queueCategorySync($mapping);
            }
        }

        if ($status === 'mapped' && $event instanceof MatchInfo) {
            $this->applyInventoryMappings($xs2Event, $event, $score, $inventoryAutoMapThreshold);
        }

        return $mapping->fresh();
    }

    private function applyInventoryMappings(
        Xs2Event $xs2Event,
        MatchInfo $localEvent,
        float $eventScore,
        ?float $inventoryAutoMapThreshold,
    ): void {
        $threshold = $this->eventAutoMapThreshold($inventoryAutoMapThreshold);
        if ($eventScore >= $threshold) {
            $this->cascadeInventoryMappings($xs2Event, $localEvent, $threshold);

            return;
        }

        $this->syncVenueMapping($xs2Event, $localEvent);
    }

    private function cascadeInventoryMappings(Xs2Event $xs2Event, MatchInfo $localEvent, float $autoMapThreshold): void
    {
        try {
            $stadiumMapping = $this->stadiumMappings->mapVenueForLocalEvent($xs2Event, $localEvent, $autoMapThreshold);
            if (! $this->stadiumMappings->isConfirmed($stadiumMapping)) {
                return;
            }

            Xs2Category::query()
                ->where('xs2_event_id', $xs2Event->id)
                ->with('mapping')
                ->get()
                ->each(function (Xs2Category $category) use ($stadiumMapping, $autoMapThreshold): void {
                    if ($category->mapping?->manually_confirmed) {
                        return;
                    }

                    $this->categoryMappings->resolve($category, $stadiumMapping, $autoMapThreshold);
                });
        } catch (Throwable) {
            // Venue and category mapping are best-effort alongside event mapping.
        }
    }

    private function eventAutoMapThreshold(?float $override = null): float
    {
        return $override ?? (float) config('xs2.mapping.event_auto_map_threshold', 100);
    }

    private function eventPendingThreshold(): float
    {
        return (float) config('xs2.mapping.event_pending_threshold', 65);
    }

    private function queueCategorySync(EventMapping $mapping): void
    {
        SyncXs2CategoriesForEvent::dispatch($mapping->xs2_event_id)->afterCommit();
    }

    private function syncVenueMapping(Xs2Event $xs2Event, MatchInfo $localEvent): void
    {
        try {
            $this->stadiumMappings->mapVenueForLocalEvent($xs2Event, $localEvent);
        } catch (Throwable) {
            // Venue mapping is best-effort alongside event mapping.
        }
    }

    private function ensureLocalEventCanBePublished(int $eventId, ?int $exceptMappingId = null): void
    {
        if ($this->otherPublicMappingForLocalEvent($eventId, $exceptMappingId)) {
            $this->conflict('This local event already has an active XS2 mapping. Refresh and try again.');
        }
    }

    private function releaseConflictingLocalEventMapping(int $eventId, int $exceptMappingId): void
    {
        $conflicting = $this->otherPublicMappingForLocalEvent($eventId, $exceptMappingId);
        if (! $conflicting) {
            return;
        }

        $details = is_array($conflicting->match_details) ? $conflicting->match_details : [];
        $conflicting->update([
            'm_id' => null,
            'status' => 'pending',
            'mapping_method' => 'automatic',
            'match_score' => null,
            'match_details' => [
                ...$details,
                'reason' => 'local_event_reassigned_by_manual_map',
                'reassigned_to_mapping_id' => $exceptMappingId,
                'previous_m_id' => $eventId,
            ],
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        $this->queueListingReconciliation($conflicting);
    }

    private function otherPublicMappingForLocalEvent(int $eventId, ?int $exceptMappingId = null): ?EventMapping
    {
        return EventMapping::query()
            ->where('m_id', $eventId)
            ->whereIn('status', ['mapped', 'created'])
            ->when($exceptMappingId !== null, fn ($query) => $query->whereKeyNot($exceptMappingId))
            ->lockForUpdate()
            ->first();
    }

    /** @param array<string, mixed> $details */
    private function pendingBecauseLocalEventIsMapped(
        ?EventMapping $mapping,
        Xs2Event $xs2Event,
        MatchInfo $candidate,
        float $score,
        EventMapping $conflictingMapping,
        array $details,
    ): EventMapping {
        return $this->save(
            $mapping,
            $xs2Event,
            null,
            'pending',
            'automatic',
            $score,
            [
                ...$details,
                'candidate_event_id' => $candidate->m_id,
                'reason' => 'local_event_already_has_public_mapping',
                'conflicting_mapping_id' => $conflictingMapping->id,
            ],
        );
    }

    private function lockMapping(EventMapping $mapping): EventMapping
    {
        $xs2Event = Xs2Event::query()->lockForUpdate()->findOrFail($mapping->xs2_event_id);
        $lockedMapping = EventMapping::query()->lockForUpdate()->findOrFail($mapping->id);
        $lockedMapping->setRelation('xs2Event', $xs2Event);

        return $lockedMapping;
    }

    /** @param list<string> $statuses */
    private function requireStatus(EventMapping $mapping, array $statuses, string $operation): void
    {
        if (! in_array($mapping->status, $statuses, true)) {
            $this->conflict("This mapping can no longer be used to {$operation}. Refresh and try again.");
        }
    }

    private function queueListingReconciliation(EventMapping $mapping): void
    {
        ReconcileSellerListingsForMapping::dispatch($mapping->id)->afterCommit();
    }

    private function conflict(string $message): never
    {
        throw new ConflictHttpException($message);
    }

    /** @param Collection<int, MatchInfo> $candidates @param array<string, mixed>|null $best */
    private function logAutomaticMappingDecision(
        Xs2Event $xs2Event,
        Collection $candidates,
        ?array $best,
        EventMapping $result,
    ): void {
        $xs2Category = $this->categoryCompatibility->resolveXs2EventCategory($xs2Event);

        Log::channel(config('xs2.log_channel', 'stack'))->info('XS2 automatic event mapping evaluated compatible categories.', [
            'xs2_event_id' => $xs2Event->external_event_id,
            'xs2_event_name' => $xs2Event->event_name,
            'xs2_category' => $xs2Category,
            'compatible_categories' => $xs2Category !== null
                ? $this->categoryCompatibility->getCompatibleCategories($xs2Category)
                : [],
            'candidate_count' => $candidates->count(),
            'best_candidate' => $this->candidateLogSummary($best),
            'mapping_status' => $result->status,
            'mapping_method' => $result->mapping_method,
            'match_score' => $result->match_score !== null ? (float) $result->match_score : null,
            'mapped_local_event_id' => $result->m_id,
        ]);
    }

    /** @param array<string, mixed>|null $best @return array<string, mixed>|null */
    private function candidateLogSummary(?array $best): ?array
    {
        if (! is_array($best) || ! ($best['event'] ?? null) instanceof MatchInfo) {
            return null;
        }

        /** @var MatchInfo $event */
        $event = $best['event'];
        $categoryId = $event->getAttribute('category');

        return [
            'event_id' => $event->m_id,
            'event_name' => $this->candidateText($event, 'local_match_name', 'match_name'),
            'category_id' => is_numeric($categoryId) ? (int) $categoryId : null,
            'category_name' => is_numeric($categoryId)
                ? $this->categoryCompatibility->categoryNameForId((int) $categoryId)
                : null,
            'final_score' => $best['final_score'] ?? null,
            'date_score' => $best['date_score'] ?? null,
            'venue_score' => $best['venue_score'] ?? null,
        ];
    }
}
