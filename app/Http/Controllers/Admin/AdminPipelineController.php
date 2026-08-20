<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventMapping;
use App\Models\Xs2Event;
use App\Models\Xs2EventInventorySyncState;
use App\Services\Pipeline\PipelineWorkloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPipelineController extends Controller
{
    public function __construct(
        private readonly PipelineWorkloadService $workload,
    ) {}

    public function workload(): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        return response()->json([
            'message' => 'Pipeline workload retrieved successfully.',
            'data' => $this->workload->snapshot(),
        ]);
    }

    public function runs(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'message' => 'Pipeline runs retrieved successfully.',
            'data' => $this->workload->paginatedRuns(
                page: (int) ($validated['page'] ?? 1),
                perPage: (int) ($validated['per_page'] ?? 20),
            ),
        ]);
    }

    public function showRun(string $correlationId): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $detail = $this->workload->runDetail($correlationId);
        if ($detail === null) {
            return response()->json(['message' => 'Pipeline run not found.'], 404);
        }

        return response()->json([
            'message' => 'Pipeline run detail retrieved successfully.',
            'data' => $detail,
        ]);
    }

    public function eventStatus(int $xs2EventId): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $event = Xs2Event::query()->with('inventorySyncState')->findOrFail($xs2EventId);
        $state = $event->inventorySyncState;

        return response()->json([
            'message' => 'Pipeline event status retrieved successfully.',
            'data' => [
                'xs2_event_id' => $event->id,
                'external_event_id' => $event->external_event_id,
                'event_name' => $event->event_name,
                'date_start_local' => $event->date_start_local?->toIso8601String(),
                'pipeline_correlation_id' => $state?->pipeline_correlation_id,
                'pipeline_run_id' => $state?->pipeline_run_id,
                'inventory_status' => $state?->tickets_sync_status,
                'listing_gen_status' => $state?->listing_gen_status,
                'publish_status' => $state?->publish_status,
                'reconcile_status' => $state?->reconcile_status,
                'last_pipeline_stage_at' => $state?->last_pipeline_stage_at?->toIso8601String(),
            ],
        ]);
    }
}
