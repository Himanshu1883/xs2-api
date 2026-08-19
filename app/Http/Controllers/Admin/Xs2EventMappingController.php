<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Xs2\CreateEventFromXs2Request;
use App\Http\Requests\Xs2\EventMappingIndexRequest;
use App\Http\Requests\Xs2\EventMappingSummaryRequest;
use App\Http\Requests\Xs2\IgnoreEventMappingRequest;
use App\Http\Requests\Xs2\ManualMapEventRequest;
use App\Http\Requests\Xs2\RecalculateEventMappingRequest;
use App\Http\Resources\EventMappingCollection;
use App\Http\Resources\EventMappingResource;
use App\Models\EventMapping;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Services\Xs2\EventMappingQueryService;
use App\Services\Xs2\EventMappingService;
use Illuminate\Http\JsonResponse;

class Xs2EventMappingController extends Controller
{
    public function __construct(
        private readonly EventMappingService $mappings,
        private readonly EventMappingQueryService $mappingQueries,
    ) {}

    public function index(EventMappingIndexRequest $request): EventMappingCollection
    {
        $this->authorize('viewAny', EventMapping::class);

        return new EventMappingCollection($this->mappingQueries->paginate($request->validated()));
    }

    public function show(EventMapping $mapping): EventMappingResource
    {
        $this->authorize('view', $mapping);

        return $this->resource($mapping, 'Event mapping retrieved successfully.');
    }

    public function summary(EventMappingSummaryRequest $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        return response()->json([
            'message' => 'Event mapping summary retrieved successfully.',
            'data' => $this->mappingQueries->summary($request->validated()),
        ]);
    }

    /**
     * Distinct XS2 tournament (league) names, used to populate the XS2
     * events list's league filter.
     */
    public function tournaments(): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

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
     * Distinct XS2 ticket currency codes, used to populate the XS2 events
     * list's currency filter.
     */
    public function currencies(): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        return response()->json([
            'message' => 'XS2 ticket currencies retrieved successfully.',
            'data' => Xs2Ticket::query()
                ->whereNotNull('currency_code')
                ->where('currency_code', '!=', '')
                ->selectRaw('DISTINCT UPPER(currency_code) as currency_code')
                ->orderBy('currency_code')
                ->pluck('currency_code'),
        ]);
    }

    public function map(ManualMapEventRequest $request, EventMapping $mapping): EventMappingResource
    {
        $this->authorize('map', $mapping);

        return $this->resource($this->mappings->manuallyMap(
            $mapping,
            $request->integer('event_id'),
            $request->user()->getAuthIdentifier(),
        ), 'Event mapping updated successfully.');
    }

    public function createEvent(CreateEventFromXs2Request $request, EventMapping $mapping): EventMappingResource
    {
        $this->authorize('createEvent', $mapping);

        return $this->resource($this->mappings->createLocalEvent(
            $mapping,
            $request->user()->getAuthIdentifier(),
            $request->validated(),
        ), 'Local event created and mapped successfully.');
    }

    public function ignore(IgnoreEventMappingRequest $request, EventMapping $mapping): EventMappingResource
    {
        $this->authorize('ignore', $mapping);

        return $this->resource($this->mappings->ignore(
            $mapping,
            $request->user()->getAuthIdentifier(),
        ), 'Event mapping ignored successfully.');
    }

    public function reopen(EventMapping $mapping): EventMappingResource
    {
        $this->authorize('reopen', $mapping);

        return $this->resource($this->mappings->reopen($mapping), 'Event mapping reopened successfully.');
    }

    public function recalculate(RecalculateEventMappingRequest $request, EventMapping $mapping): EventMappingResource
    {
        $this->authorize('recalculate', $mapping);

        return $this->resource(
            $this->mappings->recalculate($mapping, $request->boolean('force')),
            'Event mapping recalculated successfully.',
        );
    }

    private function resource(EventMapping $mapping, string $message): EventMappingResource
    {
        return (new EventMappingResource($this->mappingQueries->present($mapping)))
            ->additional(['message' => $message]);
    }
}
