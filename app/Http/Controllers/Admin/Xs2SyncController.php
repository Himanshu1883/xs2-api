<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Xs2\TriggerXs2SyncRequest;
use App\Http\Resources\Xs2SyncStateResource;
use App\Models\EventMapping;
use App\Services\Xs2\Xs2InventoryMappingSyncManagementService;
use App\Services\Xs2\Xs2SyncManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class Xs2SyncController extends Controller
{
    public function __construct(
        private readonly Xs2SyncManagementService $syncs,
        private readonly Xs2InventoryMappingSyncManagementService $inventoryMappings,
    ) {}

    public function status(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EventMapping::class);

        return Xs2SyncStateResource::collection($this->syncs->statuses());
    }

    public function queue(TriggerXs2SyncRequest $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $sport = $request->string('sport')->toString();
        $full = $request->boolean('full');

        if (! $this->syncs->queue($sport, $full)) {
            return response()->json([
                'message' => 'An XS2 event synchronization is already queued or running for this sport.',
                'data' => ['sport' => $sport, 'full' => $full],
            ]);
        }

        return response()->json([
            'message' => 'XS2 event synchronization has been queued.',
            'data' => ['sport' => $sport, 'full' => $full],
        ], 202);
    }

    public function queueVenues(): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $events = $this->inventoryMappings->queueVenues();

        return response()->json([
            'message' => $events > 0
                ? "XS2 venue synchronization has been queued for {$events} event(s)."
                : 'No mapped future XS2 events are eligible for venue synchronization.',
            'data' => ['events' => $events],
        ], 202);
    }

    public function queueCategories(): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $events = $this->inventoryMappings->queueCategories();

        return response()->json([
            'message' => $events > 0
                ? "XS2 category synchronization has been queued for {$events} event(s)."
                : 'No mapped future XS2 events are eligible for category synchronization.',
            'data' => ['events' => $events],
        ], 202);
    }
}
