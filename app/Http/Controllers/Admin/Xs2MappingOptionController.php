<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Xs2CategoryMapping;
use App\Models\Xs2StadiumMapping;
use App\Services\Mapping\LegacyMasterDataSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Xs2MappingOptionController extends Controller
{
    /**
     * Return only stadiums in the venue's resolved city. This keeps manual
     * selection inside the same geographic guardrail as automatic matching.
     */
    public function stadiums(Request $request, Xs2StadiumMapping $mapping, LegacyMasterDataSchema $schema): JsonResponse
    {
        $mapping->loadMissing('venue');
        $query = trim((string) $request->query('search', ''));
        $venueName = $mapping->relationLoaded('venue')
            ? ($mapping->venue?->venue_name ?? null)
            : $mapping->venue()->value('venue_name');

        $data = collect($schema->stadiumOptionsForMapping(
            $mapping->resolved_city_id ? (int) $mapping->resolved_city_id : null,
            $query,
            is_string($venueName) ? $venueName : null,
        ))
            ->when($query !== '', fn ($stadiums) => $stadiums->filter(
                fn (array $stadium): bool => str_contains(mb_strtolower((string) $stadium['name']), mb_strtolower($query))
            ))
            ->values();

        return response()->json([
            'message' => 'Local stadium options retrieved successfully.',
            'data' => $data,
            'meta' => [
                'resolved_city_id' => $mapping->resolved_city_id,
                'selection_available' => $mapping->resolved_city_id !== null || $data->isNotEmpty(),
                'includes_api_stadium_catalog' => $schema->apiStadiumTable() !== null,
            ],
        ]);
    }

    /**
     * Seatsbroker category options can be selected only after the parent
     * stadium decision has been confirmed. One XS2 category maps to exactly
     * one of these (a `stadium_seats` entry), never a raw block/section list.
     */
    public function categories(Xs2CategoryMapping $mapping, LegacyMasterDataSchema $schema): JsonResponse
    {
        $stadiumMapping = $mapping->stadiumMapping;
        if (! $mapping->stadium_id || $stadiumMapping?->status !== 'mapped') {
            return response()->json([
                'message' => 'Confirm the parent stadium mapping before choosing a seatsbroker category.',
                'data' => [],
            ], 409);
        }

        return response()->json([
            'message' => 'Local seatsbroker category options retrieved successfully.',
            'data' => $schema->stadiumSeatCategoriesForStadium((int) $mapping->stadium_id),
            'meta' => ['stadium_id' => (int) $mapping->stadium_id],
        ]);
    }

    /**
     * Same seatsbroker category options as categories(), but keyed directly
     * by a local stadium ID. Used by the grouped category-mapping list,
     * where a row represents every event's mapping for a category at once
     * rather than a single per-event mapping to bind the route to.
     */
    public function categoriesForStadium(Request $request, LegacyMasterDataSchema $schema): JsonResponse
    {
        $stadiumId = (int) $request->query('stadium_id');
        if ($stadiumId < 1 || ! Xs2StadiumMapping::where('stadium_id', $stadiumId)->where('status', 'mapped')->exists()) {
            return response()->json([
                'message' => 'Confirm the parent stadium mapping before choosing a seatsbroker category.',
                'data' => [],
            ], 409);
        }

        return response()->json([
            'message' => 'Local seatsbroker category options retrieved successfully.',
            'data' => $schema->stadiumSeatCategoriesForStadium($stadiumId),
            'meta' => ['stadium_id' => $stadiumId],
        ]);
    }
}
