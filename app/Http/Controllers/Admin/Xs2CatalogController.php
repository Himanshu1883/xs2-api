<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Xs2\Xs2CatalogBulkSyncRequest;
use App\Http\Requests\Xs2\Xs2CatalogEventSearchRequest;
use App\Http\Requests\Xs2\Xs2CatalogEventSyncRequest;
use App\Jobs\SyncXs2CatalogEventJob;
use App\Models\EventMapping;
use App\Services\Xs2\Xs2ApiDebugRecorder;
use App\Services\Xs2\Xs2CatalogEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Xs2CatalogController extends Controller
{
    public function previewEvents(Request $request, Xs2CatalogEventService $catalog): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'sport' => ['nullable', 'string', 'max:50'],
            'tournament_name' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'message' => 'XS2 events catalog preview retrieved successfully.',
            'data' => $catalog->preview(
                (string) ($validated['sport'] ?? ''),
                $validated['tournament_name'] ?? null,
                $validated['search'] ?? null,
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? 20),
            ),
        ]);
    }

    public function searchEvents(
        Xs2CatalogEventSearchRequest $request,
        Xs2CatalogEventService $catalog,
        Xs2ApiDebugRecorder $recorder,
    ): JsonResponse {
        $this->authorize('viewAny', EventMapping::class);

        $recorder->enable();

        try {
            $result = $catalog->search(
                (string) ($request->validated('sport') ?? ''),
                $request->validated('tournament_name'),
                $request->validated('search'),
                (int) ($request->validated('page') ?? 1),
                (int) ($request->validated('per_page') ?? 20),
            );
        } catch (\Throwable $exception) {
            $preview = $catalog->preview(
                (string) ($request->validated('sport') ?? ''),
                $request->validated('tournament_name'),
                $request->validated('search'),
                (int) ($request->validated('per_page') ?? 20),
            );

            return response()->json([
                'message' => trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'XS2 catalog request could not be completed.',
                'meta' => [
                    'request_url' => $preview['request_url'],
                    'sport' => $preview['sport'],
                    'xs2_api_debug' => $recorder->flush(),
                ],
            ], 502);
        }

        $debug = $recorder->flush();

        return response()->json([
            'message' => 'XS2 catalog events retrieved successfully.',
            'data' => $result['events'],
            'meta' => [
                'request_url' => $result['request_url'],
                'sport' => $result['sport'],
                'pagination' => $result['pagination'],
                'xs2_api_debug' => $debug,
            ],
        ]);
    }

    public function syncEvent(
        Xs2CatalogEventSyncRequest $request,
        Xs2CatalogEventService $catalog,
        Xs2ApiDebugRecorder $recorder,
    ): JsonResponse {
        $this->authorize('viewAny', EventMapping::class);

        $externalEventId = (string) ($request->validated('external_event_id') ?? data_get($request->validated('payload'), 'event_id', ''));
        $recorder->enable();

        try {
            $result = $catalog->syncEvent($externalEventId, $request->validated('payload'));
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'XS2 event could not be synchronized.',
                'meta' => [
                    'xs2_api_debug' => $recorder->flush(),
                ],
            ], 422);
        }

        $debug = $recorder->flush();
        $message = $result['mapping_status'] === 'mapped'
            ? sprintf('Synchronized “%s” and mapped it to local event.', $externalEventId)
            : sprintf('Synchronized XS2 event “%s”. Review mapping status: %s.', $externalEventId, $result['mapping_status'] ?? 'pending');

        return response()->json([
            'message' => $message,
            'data' => $result,
            'meta' => [
                'xs2_api_debug' => $debug,
            ],
        ], 201);
    }

    public function bulkSyncEvents(Xs2CatalogBulkSyncRequest $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $events = $request->validated('events');
        $dispatched = 0;

        foreach ($events as $event) {
            $externalEventId = (string) $event['external_event_id'];
            $payload = $event['payload'] ?? null;

            SyncXs2CatalogEventJob::dispatch($externalEventId, $payload);
            $dispatched++;
        }

        return response()->json([
            'message' => sprintf('%d event sync job(s) have been queued.', $dispatched),
            'data' => [
                'queued' => $dispatched,
            ],
        ], 202);
    }
}
