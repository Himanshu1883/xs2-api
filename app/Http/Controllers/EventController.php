<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventIndexRequest;
use App\Http\Resources\EventCollection;
use App\Http\Resources\EventResource;
use App\Models\MatchInfo;
use App\Services\PublicEventQueryService;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    public function __construct(private readonly PublicEventQueryService $events) {}

    public function index(EventIndexRequest $request): EventCollection
    {
        return new EventCollection($this->events->paginate($request->validated()));
    }

    public function filterOptions(): JsonResponse
    {
        return response()->json([
            'data' => $this->events->filterOptions(),
        ]);
    }

    /**
     * Show one local event by its public local identifier (`match_info.m_id`).
     */
    public function show(MatchInfo $event): EventResource
    {
        return (new EventResource($this->events->findPublic($event)))
            ->additional(['message' => 'Event retrieved successfully.']);
    }
}
