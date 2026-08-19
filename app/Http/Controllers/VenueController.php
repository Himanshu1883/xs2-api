<?php

namespace App\Http\Controllers;

use App\Http\Requests\VenueEventsRequest;
use App\Http\Requests\VenueIndexRequest;
use App\Http\Resources\VenueCollection;
use App\Services\PublicVenueQueryService;
use Illuminate\Http\JsonResponse;

class VenueController extends Controller
{
    public function __construct(private readonly PublicVenueQueryService $venues) {}

    public function index(VenueIndexRequest $request): VenueCollection
    {
        return new VenueCollection($this->venues->paginate($request->validated()));
    }

    public function filterOptions(): JsonResponse
    {
        $options = $this->venues->filterOptions();

        return response()->json([
            'data' => $options,
        ]);
    }

    public function events(VenueEventsRequest $request, int $venue): JsonResponse
    {
        $payload = $this->venues->eventsForVenue($venue, $request->validated());

        return response()->json([
            'data' => $payload['data'],
            'meta' => [
                'venue' => $payload['venue'],
                'total' => count($payload['data']),
            ],
        ]);
    }

    public function categories(int $venue): JsonResponse
    {
        $payload = $this->venues->categoriesForVenue($venue);

        return response()->json([
            'data' => $payload['data'],
            'meta' => [
                'venue' => $payload['venue'],
                'total' => count($payload['data']),
            ],
        ]);
    }

    public function sections(int $venue): JsonResponse
    {
        $payload = $this->venues->sectionsForVenue($venue);

        return response()->json([
            'data' => $payload['data'],
            'meta' => [
                'venue' => $payload['venue'],
                'total' => count($payload['data']),
            ],
        ]);
    }
}
