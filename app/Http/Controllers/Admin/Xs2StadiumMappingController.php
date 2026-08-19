<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Xs2\ConfirmStadiumMappingRequest;
use App\Http\Requests\Xs2\MappingIndexRequest;
use App\Http\Resources\Xs2StadiumMappingResource;
use App\Jobs\ResolvePendingXs2Listings;
use App\Models\Xs2Event;
use App\Models\Xs2StadiumMapping;
use App\Models\Xs2Venue;
use App\Services\Mapping\LegacyMasterDataSchema;
use App\Services\Mapping\StadiumMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class Xs2StadiumMappingController extends Controller
{
    public function index(MappingIndexRequest $request)
    {
        $filters = $request->validated();
        $query = $this->queryWithCounts();

        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $query->when($filters['stadium_id'] ?? null, fn ($query, $id) => $query->where('stadium_id', $id));
        $query->when($filters['venue_id'] ?? null, fn ($query, $id) => $query->whereHas('venue', fn ($venue) => $venue->where('external_venue_id', $id)));
        $query->when($filters['search'] ?? null, fn ($query, $search) => $query->whereHas('venue', fn ($venue) => $venue
            ->where('venue_name', 'like', "%{$search}%")
            ->orWhere('city_name', 'like', "%{$search}%")));
        $query->when($filters['tournament'] ?? null, fn ($query, $tournament) => $query->whereHas(
            'venue.events',
            fn ($events) => $events->where('tournament_name', $tournament),
        ));

        return Xs2StadiumMappingResource::collection($query->latest()->paginate($filters['per_page'] ?? 20));
    }

    public function summary(): JsonResponse
    {
        $schema = app(LegacyMasterDataSchema::class);
        $totalSeatsbrokerVenues = $schema->countDistinctSeatsbrokerVenues();

        $totalXs2Venues = Schema::hasTable('xs2_venues')
            ? (int) Xs2Venue::query()->count()
            : (int) Xs2StadiumMapping::query()->count();

        $mapped = (int) Xs2StadiumMapping::query()->where('status', 'mapped')->count();
        $unmapped = (int) Xs2StadiumMapping::query()->where('status', '!=', 'mapped')->count();

        return response()->json([
            'message' => 'Stadium mapping summary retrieved successfully.',
            'data' => [
                'total_xs2_venues' => $totalXs2Venues,
                'total_seatsbroker_venues' => $totalSeatsbrokerVenues,
                'mapped' => $mapped,
                'unmapped' => $unmapped,
            ],
        ]);
    }

    public function show(Xs2StadiumMapping $mapping): Xs2StadiumMappingResource
    {
        return new Xs2StadiumMappingResource($this->queryWithCounts()->findOrFail($mapping->id));
    }

    /**
     * Distinct XS2 tournament (league) names, used to populate the venue
     * list's league filter.
     */
    public function tournaments(): JsonResponse
    {
        return response()->json([
            'message' => 'XS2 tournaments retrieved successfully.',
            'data' => Xs2Event::query()
                ->whereNotNull('tournament_name')
                ->where('tournament_name', '!=', '')
                ->distinct()
                ->orderBy('tournament_name')
                ->pluck('tournament_name'),
        ]);
    }

    /**
     * Base query for a stadium mapping with its venue eager-loaded and the
     * xs2_events_count / seatsbroker_events_count / categories_count
     * aggregates attached, shared by index() and show() so both return the
     * same counts. SeatsBroker event counts include future fixtures only
     * (match_date >= now), matching PublicVenueQueryService.
     */
    private function queryWithCounts()
    {
        $query = Xs2StadiumMapping::query()->with('venue')->withCount('categoryMappings');

        $query->addSelect([
            'xs2_events_count' => Xs2Event::query()
                ->selectRaw('count(*)')
                ->join('xs2_venues', 'xs2_venues.external_venue_id', '=', 'xs2_events.venue_id')
                ->whereColumn('xs2_venues.id', 'xs2_stadium_mappings.xs2_venue_id'),
        ]);

        if (Schema::hasTable('match_info') && Schema::hasColumn('match_info', 'venue')) {
            $seatsbrokerEventsCount = DB::table('match_info')
                ->selectRaw('count(*)')
                ->whereColumn('match_info.venue', 'xs2_stadium_mappings.stadium_id');

            if (Schema::hasColumn('match_info', 'match_date')) {
                $seatsbrokerEventsCount->where('match_info.match_date', '>=', now());
            }

            $query->addSelect([
                'seatsbroker_events_count' => $seatsbrokerEventsCount,
            ]);
        }

        return $query;
    }

    public function confirm(ConfirmStadiumMappingRequest $request, Xs2StadiumMapping $mapping, StadiumMappingService $mappings)
    {
        $stadiumId = (int) ($request->validated('stadium_id') ?? $mapping->stadium_id);
        if ($stadiumId < 1) {
            throw ValidationException::withMessages(['stadium_id' => ['Select an existing local stadium.']]);
        }

        $mapping = $mappings->confirmManual($mapping, $stadiumId);

        return (new Xs2StadiumMappingResource($mapping->load('venue')))
            ->additional(['message' => 'Stadium mapping confirmed successfully.']);
    }

    public function change(ConfirmStadiumMappingRequest $request, Xs2StadiumMapping $mapping, StadiumMappingService $mappings)
    {
        if (! $request->filled('stadium_id')) {
            throw ValidationException::withMessages(['stadium_id' => ['A replacement local stadium is required.']]);
        }

        $stadiumId = (int) $request->validated('stadium_id');
        $mapping = $mappings->confirmManual($mapping, $stadiumId);

        return (new Xs2StadiumMappingResource($mapping->load('venue')))
            ->additional(['message' => 'Stadium mapping confirmed successfully.']);
    }

    public function ignore(ConfirmStadiumMappingRequest $request, Xs2StadiumMapping $mapping)
    {
        $mapping->update([
            'stadium_id' => null,
            'status' => 'ignored',
            'mapping_method' => 'manual',
            'manually_confirmed' => true,
            'mapped_at' => now(),
            'mapping_error' => null,
        ]);
        ResolvePendingXs2Listings::dispatchAfterMappingChange('stadium', $mapping->id);

        return (new Xs2StadiumMappingResource($mapping->load('venue')))
            ->additional(['message' => 'Stadium mapping ignored successfully.']);
    }
}
