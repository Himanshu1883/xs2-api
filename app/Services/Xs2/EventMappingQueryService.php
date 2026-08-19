<?php

namespace App\Services\Xs2;

use App\Models\EventMapping;
use App\Models\MatchInfo;
use App\Models\Xs2Ticket;
use App\Services\LegacyLocalEventEnglishQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EventMappingQueryService
{
    public function __construct(private readonly LegacyLocalEventEnglishQuery $englishLabels) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $mappings = EventMapping::query()
            ->with([
                'xs2Event' => fn (BelongsTo $query) => $query
                    ->withCount('tickets')
                    ->withSum('tickets', 'stock')
                    ->with(['venue.stadiumMapping', 'inventorySyncState']),
                'event' => function (Builder|BelongsTo $query): void {
                    $this->addLocalEventDisplayNames($query);
                },
                'reviewer:id,first_name,last_name',
            ])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['mapping_method'] ?? null, fn (Builder $query, string $method) => $query->where('mapping_method', $method))
            ->when($filters['minimum_score'] ?? null, fn (Builder $query, float $score) => $query->where('match_score', '>=', $score))
            ->when($filters['maximum_score'] ?? null, fn (Builder $query, float $score) => $query->where('match_score', '<=', $score))
            ->when(array_key_exists('has_local_event', $filters), function (Builder $query) use ($filters): void {
                filter_var($filters['has_local_event'], FILTER_VALIDATE_BOOLEAN)
                    ? $query->whereNotNull('m_id')
                    : $query->whereNull('m_id');
            })
            ->when(array_key_exists('has_tickets', $filters), function (Builder $query) use ($filters): void {
                filter_var($filters['has_tickets'], FILTER_VALIDATE_BOOLEAN)
                    ? $query->whereHas('xs2Event.tickets')
                    : $query->whereDoesntHave('xs2Event.tickets');
            })
            ->when(array_key_exists('has_ticket_flags', $filters), function (Builder $query) use ($filters): void {
                filter_var($filters['has_ticket_flags'], FILTER_VALIDATE_BOOLEAN)
                    ? $query->whereHas('xs2Event.tickets', fn (Builder $ticketQuery) => $ticketQuery->withNonEmptyFlags())
                    : $query->whereDoesntHave('xs2Event.tickets', fn (Builder $ticketQuery) => $ticketQuery->withNonEmptyFlags());
            })
            ->when(! empty($filters['ticket_flags'] ?? []), function (Builder $query) use ($filters): void {
                $query->whereHas(
                    'xs2Event.tickets',
                    fn (Builder $ticketQuery) => $ticketQuery->withAnyFlags($filters['ticket_flags']),
                );
            })
            ->when(array_key_exists('has_guest_validation', $filters), function (Builder $query) use ($filters): void {
                filter_var($filters['has_guest_validation'], FILTER_VALIDATE_BOOLEAN)
                    ? $query->whereHas('xs2Event.tickets', fn (Builder $ticketQuery) => $ticketQuery->withGuestValidation())
                    : $query->whereDoesntHave('xs2Event.tickets', fn (Builder $ticketQuery) => $ticketQuery->withGuestValidation());
            })
            ->when($filters['currency_code'] ?? null, function (Builder $query) use ($filters): void {
                $query->whereHas(
                    'xs2Event.tickets',
                    fn (Builder $ticketQuery) => $this->applyTicketCurrencyFilter($ticketQuery, $filters['currency_code']),
                );
            })
            ->when($filters['local_event_ids'] ?? null, fn (Builder $query, array $ids) => $query->whereIn('m_id', $ids))
            ->whereHas('xs2Event', function (Builder $query) use ($filters): void {
                $this->applyEventFilters($query, $filters)
                    ->when($filters['venue_id'] ?? null, fn (Builder $query, string $venueId) => $query->where('venue_id', $venueId))
                    ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                        $query->where(function (Builder $query) use ($search): void {
                            $query->where('event_name', 'like', "%{$search}%")
                                ->orWhere('tournament_name', 'like', "%{$search}%")
                                ->orWhere('city', 'like', "%{$search}%")
                                ->orWhere('hometeam_name', 'like', "%{$search}%")
                                ->orWhere('visitingteam_name', 'like', "%{$search}%");
                        });
                    });
            })
            ->orderBy($filters['sort'] ?? 'match_score', $filters['direction'] ?? 'desc')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $this->loadSuggestedEvents($mappings->getCollection());

        return $mappings;
    }

    /** @param array<string, mixed> $filters */
    public function summary(array $filters): array
    {
        $counts = EventMapping::query()
            ->whereHas('xs2Event', fn (Builder $query) => $this->applyEventFilters($query, $filters))
            ->when($filters['currency_code'] ?? null, function (Builder $query) use ($filters): void {
                $query->whereHas(
                    'xs2Event.tickets',
                    fn (Builder $ticketQuery) => $this->applyTicketCurrencyFilter($ticketQuery, $filters['currency_code']),
                );
            })
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'total' => (int) $counts->sum(),
            'mapped' => (int) ($counts->get('mapped') ?? 0),
            'pending' => (int) ($counts->get('pending') ?? 0),
            'created' => (int) ($counts->get('created') ?? 0),
            'ignored' => (int) ($counts->get('ignored') ?? 0),
            'total_listings' => $this->ticketsQuery($filters)->count(),
            'total_tickets' => (int) $this->ticketsQuery($filters)->sum('stock'),
            'total_inventory_value' => $this->inventoryValueByCurrency($filters),
        ];
    }

    /**
     * Sum of stock × listing price per currency (major units).
     * Listing price matches publish logic: package_price, else net_rate, else face_value.
     *
     * @param  array<string, mixed>  $filters
     * @return array{by_currency: array<string, float>}
     */
    private function inventoryValueByCurrency(array $filters): array
    {
        $divisor = max(1, (int) config('services.xs2.minor_unit_divisor'));

        $rows = $this->ticketsQuery($filters)
            ->selectRaw(
                'UPPER(COALESCE(NULLIF(currency_code, \'\'), \'EUR\')) as currency_code, '
                .'SUM(stock * COALESCE(package_price, net_rate, face_value, 0)) as total_minor',
            )
            ->groupByRaw('UPPER(COALESCE(NULLIF(currency_code, \'\'), \'EUR\'))')
            ->get();

        $byCurrency = [];
        foreach ($rows as $row) {
            $totalMinor = (int) ($row->total_minor ?? 0);
            if ($totalMinor === 0) {
                continue;
            }

            $byCurrency[(string) $row->currency_code] = round($totalMinor / $divisor, 2);
        }

        ksort($byCurrency);

        return ['by_currency' => $byCurrency];
    }

    /**
     * Listings are Xs2Ticket rows (EventMappingResource.listings_count).
     *
     * @param  array<string, mixed>  $filters
     */
    private function ticketsQuery(array $filters): Builder
    {
        return Xs2Ticket::query()
            ->when($filters['currency_code'] ?? null, fn (Builder $query) => $this->applyTicketCurrencyFilter($query, $filters['currency_code']))
            ->whereHas('xs2Event', function (Builder $query) use ($filters): void {
                $this->applyEventFilters($query, $filters);
            });
    }

    private function applyTicketCurrencyFilter(Builder $query, string $currencyCode): Builder
    {
        $normalized = strtoupper(trim($currencyCode));
        if ($normalized === '') {
            return $query;
        }

        return $query->whereRaw(
            'UPPER(COALESCE(NULLIF(currency_code, \'\'), \'EUR\')) = ?',
            [$normalized],
        );
    }

    /**
     * Future XS2 events only by default. An explicit past date_to opts into a
     * historical range; otherwise date_start_local is constrained to >= now().
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyEventFilters(Builder $query, array $filters, bool $forceFuture = false): Builder
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $historical = ! $forceFuture
            && is_string($dateTo)
            && $dateTo !== ''
            && $dateTo < now()->toDateString();

        $query
            ->when($filters['sport'] ?? null, fn (Builder $query, string $sport) => $query->where('sport_type', $sport))
            ->when($filters['tournament'] ?? null, fn (Builder $query, string $tournament) => $query->where('tournament_name', $tournament));

        if ($historical) {
            if (is_string($dateFrom) && $dateFrom !== '') {
                $query->whereDate('date_start_local', '>=', $dateFrom);
            }
        } else {
            $query->where('date_start_local', '>=', now());
            if (is_string($dateFrom) && $dateFrom !== '') {
                $query->whereDate('date_start_local', '>=', $dateFrom);
            }
        }

        if (is_string($dateTo) && $dateTo !== '') {
            $query->whereDate('date_start_local', '<=', $dateTo);
        }

        return $query;
    }

    public function present(EventMapping $mapping): EventMapping
    {
        $mapping->load([
            'xs2Event' => fn (BelongsTo $query) => $query
                ->withCount('tickets')
                ->withSum('tickets', 'stock')
                ->with(['venue.stadiumMapping', 'inventorySyncState']),
            'event' => function (Builder|BelongsTo $query): void {
                $this->addLocalEventDisplayNames($query);
            },
            'reviewer:id,first_name,last_name',
        ]);
        $this->loadSuggestedEvents(collect([$mapping]));

        return $mapping;
    }

    /** @param Collection<int, EventMapping> $mappings */
    private function loadSuggestedEvents(Collection $mappings): void
    {
        $candidateIds = $mappings
            ->flatMap(fn (EventMapping $mapping) => $this->candidateEventIds($mapping))
            ->unique()
            ->values();

        if ($candidateIds->isEmpty()) {
            $mappings->each(fn (EventMapping $mapping) => $mapping->setRelation('suggestedEvents', collect()));

            return;
        }

        $eventsQuery = MatchInfo::query();
        $this->addLocalEventDisplayNames($eventsQuery);

        $events = $eventsQuery
            ->whereIn('match_info.m_id', $candidateIds)
            ->get()
            ->keyBy('m_id');

        $mappings->each(function (EventMapping $mapping) use ($events): void {
            $mapping->setRelation(
                'suggestedEvents',
                collect($this->candidateEventIds($mapping))
                    ->map(fn (int $eventId) => $events->get($eventId))
                    ->filter()
                    ->values(),
            );
        });
    }

    /** @return array<int, int> */
    private function candidateEventIds(EventMapping $mapping): array
    {
        $details = is_array($mapping->match_details) ? $mapping->match_details : [];
        $ids = [];

        foreach (($details['candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $eventId = $candidate['event_id'] ?? $candidate['m_id'] ?? null;
            if (is_numeric($eventId) && (int) $eventId > 0) {
                $ids[] = (int) $eventId;
            }
        }

        $bestMatch = $details['best_match'] ?? [];
        $bestMatchId = is_array($bestMatch)
            ? ($bestMatch['candidate_event_id'] ?? $bestMatch['candidate_m_id'] ?? null)
            : null;
        $candidateId = $details['candidate_event_id'] ?? $details['candidate_m_id'] ?? $bestMatchId;

        if (is_numeric($candidateId) && (int) $candidateId > 0) {
            $ids[] = (int) $candidateId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * The legacy match-info fields may store reference IDs. Load the matching
     * display names with each local event query so mapping resources do not
     * issue a lookup per result row.
     */
    private function addLocalEventDisplayNames(Builder|BelongsTo $query): void
    {
        $query->select('match_info.*');
        $this->englishLabels->apply($query);
    }
}
