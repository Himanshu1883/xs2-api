<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\Integrations\SellerApiConfigurationException;
use App\Exceptions\Integrations\SellerApiRequestException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SellerApi\SellerApiEventBulkImportRequest;
use App\Http\Requests\SellerApi\SellerApiEventBulkSyncRequest;
use App\Http\Requests\SellerApi\SellerApiEventImportRequest;
use App\Http\Requests\SellerApi\SellerApiEventSearchRequest;
use App\Http\Requests\SellerApi\SellerApiEventTournamentSearchRequest;
use App\Models\EventMapping;
use App\Services\SellerApi\SellerApiDebugRecorder;
use App\Services\SellerApi\SellerBulkEventSyncRunner;
use App\Services\SellerApi\SellerBulkEventSyncState;
use App\Services\SellerApi\SellerEventImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SellerApiEventController extends Controller
{
    public function search(
        SellerApiEventSearchRequest $request,
        SellerEventImportService $import,
        SellerApiDebugRecorder $recorder,
    ): JsonResponse {
        $this->authorize('viewAny', EventMapping::class);

        $environment = (string) ($request->validated('environment') ?? 'sandbox');
        $recorder->enable();
        $query = (string) $request->validated('q');
        $limit = (int) ($request->validated('limit') ?? 10);

        try {
            $results = $import->search(
                $query,
                $limit,
                $environment,
            );
        } catch (\Throwable $exception) {
            return $this->sellerApiFailureResponse(
                $import,
                $environment,
                $recorder->flush(),
                $exception,
                eventId: null,
                searchQuery: $query,
                searchLimit: $limit,
            );
        }

        $debug = $recorder->flush();
        $preview = $import->previewEventSearch($query, $limit, $environment);

        return response()->json([
            'message' => 'Seatsbroker catalog events retrieved successfully.',
            'data' => $results,
            'meta' => $this->sellerApiMeta($environment, $preview['request_url'], $debug),
        ]);
    }

    public function searchByTournament(
        SellerApiEventTournamentSearchRequest $request,
        SellerEventImportService $import,
        SellerApiDebugRecorder $recorder,
    ): JsonResponse {
        $this->authorize('viewAny', EventMapping::class);

        $environment = (string) ($request->validated('environment') ?? 'sandbox');
        $tournamentId = (int) $request->validated('tournament_id');
        $page = (int) ($request->validated('page') ?? 1);
        $perPage = (int) ($request->validated('per_page') ?? 100);
        $recorder->enable();

        try {
            $result = $import->searchByTournament($tournamentId, $page, $perPage, $environment);
        } catch (\Throwable $exception) {
            try {
                $preview = $import->previewBulkSync($tournamentId);
                $requestUrl = $preview['request_urls'][$environment] ?? '';
            } catch (\Throwable) {
                $requestUrl = '';
            }

            $debugPayload = $recorder->flush();
            $cause = trim($exception->getMessage());
            $debug = $exception instanceof SellerApiRequestException ? $exception->context : [];
            if ($cause !== '') {
                $debug['cause'] = $cause;
            }
            $debug = [
                'environment' => $environment,
                'request_url' => $requestUrl,
                ...$debug,
            ];
            if (! $exception instanceof SellerApiRequestException) {
                $debug['exception'] = $exception::class;
            }

            $status = match (true) {
                $exception instanceof SellerApiConfigurationException => 503,
                $exception instanceof SellerApiRequestException => 502,
                default => 500,
            };

            return response()->json([
                'message' => $cause !== '' ? $cause : 'Seatsbroker request could not be completed.',
                'meta' => $this->sellerApiMeta($environment, $requestUrl, $debugPayload),
                'debug' => array_filter(
                    $debug,
                    static fn (mixed $value): bool => $value !== null && $value !== '',
                ),
            ], $status);
        }

        $debug = $recorder->flush();

        return response()->json([
            'message' => 'Seatsbroker league events retrieved successfully.',
            'data' => $result['events'],
            'meta' => [
                ...$this->sellerApiMeta($environment, $result['request_url'], $debug),
                'tournament_id' => $result['tournament_id'],
                'tournament_name' => $result['tournament_name'],
                'pagination' => $result['pagination'],
            ],
        ]);
    }

    public function importPreview(Request $request, SellerEventImportService $import): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'event_id' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'environment' => ['nullable', 'in:sandbox,production'],
        ]);

        $environment = (string) ($validated['environment'] ?? 'sandbox');
        $searchQuery = trim((string) ($validated['q'] ?? ''));

        if ($searchQuery !== '' && mb_strlen($searchQuery) >= 2) {
            $preview = $import->previewEventSearch(
                $searchQuery,
                (int) ($validated['limit'] ?? 10),
                $environment,
            );
        } else {
            $preview = $import->previewSingleEventImport((string) ($validated['event_id'] ?? ''), $environment);
        }

        return response()->json([
            'message' => 'Seatsbroker single event import preview retrieved successfully.',
            'data' => $preview,
        ]);
    }

    public function import(
        SellerApiEventImportRequest $request,
        SellerEventImportService $import,
        SellerApiDebugRecorder $recorder,
    ): JsonResponse {
        $this->authorize('viewAny', EventMapping::class);

        $environment = (string) ($request->validated('environment') ?? 'sandbox');
        $eventId = (string) $request->validated('event_id');
        $recorder->enable();

        try {
            $result = $import->import(
                $eventId,
                $request->validated('payload'),
                $environment,
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'event_id' => [$exception->getMessage()],
            ]);
        } catch (\Throwable $exception) {
            return $this->sellerApiFailureResponse(
                $import,
                $environment,
                $recorder->flush(),
                $exception,
                eventId: $eventId,
            );
        }

        $debug = $recorder->flush();
        $preview = $import->previewSingleEventImport($eventId, $environment);

        $message = $result['status'] === 'already_exists'
            ? sprintf('Event “%s” already exists locally (m_id %d).', $result['match_name'], $result['m_id'])
            : sprintf('Imported “%s” as local event m_id %d.', $result['match_name'], $result['m_id']);

        return response()->json([
            'message' => $message,
            'data' => $result,
            'meta' => $this->sellerApiMeta($environment, $preview['request_url'], $debug),
        ], $result['status'] === 'already_exists' ? 200 : 201);
    }

    public function bulkImport(
        SellerApiEventBulkImportRequest $request,
        SellerEventImportService $import,
        SellerApiDebugRecorder $recorder,
    ): JsonResponse {
        $this->authorize('viewAny', EventMapping::class);

        $environment = (string) ($request->validated('environment') ?? 'sandbox');
        /** @var list<array{event_id:string,payload?:array<string, mixed>|null}> $events */
        $events = array_values(array_map(
            static fn (array $item): array => [
                'event_id' => (string) $item['event_id'],
                'payload' => is_array($item['payload'] ?? null) ? $item['payload'] : null,
            ],
            $request->validated('events'),
        ));
        $recorder->enable();

        try {
            $result = $import->importBulk($events, $environment);
        } catch (\Throwable $exception) {
            return $this->sellerApiFailureResponse(
                $import,
                $environment,
                $recorder->flush(),
                $exception,
                eventId: null,
            );
        }

        $debug = $recorder->flush();
        $created = (int) ($result['created'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        $total = count($events);

        $message = match (true) {
            $failed > 0 && ($created > 0 || $skipped > 0) => sprintf(
                'Imported %d of %d event(s): %d added, %d already existed, %d failed.',
                $created + $skipped,
                $total,
                $created,
                $skipped,
                $failed,
            ),
            $failed > 0 => sprintf('Bulk import failed for all %d selected event(s).', $total),
            $created > 0 && $skipped > 0 => sprintf(
                'Imported %d event(s): %d added, %d already existed.',
                $created + $skipped,
                $created,
                $skipped,
            ),
            $created > 0 => sprintf('Added %d event(s) to the local catalogue.', $created),
            $skipped > 0 => sprintf('All %d selected event(s) already exist locally.', $skipped),
            default => 'No events were imported.',
        };

        $status = match (true) {
            $failed === $total => 422,
            $created > 0 => 201,
            default => 200,
        };

        return response()->json([
            'message' => $message,
            'data' => $result,
            'meta' => $this->sellerApiMeta($environment, '', $debug),
        ], $status);
    }

    public function bulkSyncPreview(SellerApiEventBulkSyncRequest $request, SellerEventImportService $import): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $tournamentId = (int) $request->validated('tournament_id');
        $preview = $import->previewBulkSync($tournamentId);

        return response()->json([
            'message' => 'Seatsbroker bulk sync preview retrieved successfully.',
            'data' => $preview,
        ]);
    }

    public function bulkSync(SellerApiEventBulkSyncRequest $request, SellerEventImportService $import): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $tournamentId = (int) $request->validated('tournament_id');
        $environment = (string) $request->validated('environment');

        try {
            $preview = $import->previewBulkSync($tournamentId);
            $state = SellerBulkEventSyncState::create($tournamentId, $environment, $preview);
            $syncId = (string) $state['sync_id'];

            SellerBulkEventSyncState::markKickRequested($syncId);
            SellerBulkEventSyncRunner::kick($syncId, $tournamentId, $environment);

            $state = SellerBulkEventSyncState::get($syncId) ?? $state;
            $status = in_array($state['status'] ?? null, ['completed', 'failed'], true) ? 200 : 202;
            $message = match ($state['status'] ?? null) {
                'completed' => (string) ($state['message'] ?? 'Bulk sync completed.'),
                'failed' => (string) ($state['message'] ?? 'Bulk sync failed.'),
                'running' => 'Bulk sync is running in the background.',
                default => 'Bulk sync queued. It will continue in the background — poll status for progress.',
            };

            return response()->json([
                'message' => $message,
                'data' => $state,
            ], $status);
        } catch (SellerApiRequestException|SellerApiConfigurationException|\Throwable $exception) {
            $status = match (true) {
                $exception instanceof SellerApiConfigurationException => 503,
                $exception instanceof SellerApiRequestException => 502,
                default => 500,
            };

            return response()->json(
                $this->bulkSyncFailurePayload($import, $tournamentId, $environment, $exception, []),
                $status,
            );
        }
    }

    public function bulkSyncStatus(string $syncId): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $state = SellerBulkEventSyncState::get($syncId);
        if ($state === null) {
            return response()->json([
                'message' => 'Bulk sync run was not found.',
            ], 404);
        }

        SellerBulkEventSyncRunner::kick(
            $syncId,
            (int) ($state['tournament_id'] ?? 0),
            (string) ($state['environment'] ?? 'sandbox'),
        );

        $state = SellerBulkEventSyncState::get($syncId) ?? $state;

        $message = match ($state['status'] ?? null) {
            'completed' => (string) ($state['message'] ?? 'Bulk sync completed.'),
            'failed' => (string) ($state['message'] ?? 'Bulk sync failed.'),
            'running' => 'Bulk sync is running.',
            default => 'Bulk sync is queued.',
        };

        return response()->json([
            'message' => $message,
            'data' => $state,
        ]);
    }

    /** @param  list<array<string, mixed>>  $sellerApiDebug */
    private function sellerApiMeta(string $environment, string $requestUrl, array $sellerApiDebug): array
    {
        return [
            'environment' => $environment,
            'request_url' => $requestUrl,
            'seller_api_debug' => $sellerApiDebug,
        ];
    }

    /** @param  list<array<string, mixed>>  $sellerApiDebug */
    private function sellerApiFailureResponse(
        SellerEventImportService $import,
        string $environment,
        array $sellerApiDebug,
        \Throwable $exception,
        ?string $eventId,
        ?string $searchQuery = null,
        ?int $searchLimit = null,
    ): JsonResponse {
        if ($searchQuery !== null && trim($searchQuery) !== '') {
            $preview = $import->previewEventSearch($searchQuery, $searchLimit ?? 10, $environment);
        } else {
            $preview = $import->previewSingleEventImport($eventId ?? '', $environment);
        }
        $debug = $exception instanceof SellerApiRequestException ? $exception->context : [];

        $cause = trim($exception->getMessage());
        if ($cause !== '') {
            $debug['cause'] = $cause;
        }

        $debug = [
            'environment' => $environment,
            'request_url' => $preview['request_url'],
            ...$debug,
        ];

        if (! $exception instanceof SellerApiRequestException) {
            $debug['exception'] = $exception::class;
        }

        $status = match (true) {
            $exception instanceof SellerApiConfigurationException => 503,
            $exception instanceof SellerApiRequestException => 502,
            default => 500,
        };

        $message = $cause !== '' ? $cause : 'Seatsbroker request could not be completed.';

        return response()->json([
            'message' => $message,
            'meta' => $this->sellerApiMeta($environment, $preview['request_url'], $sellerApiDebug),
            'debug' => array_filter(
                $debug,
                static fn (mixed $value): bool => $value !== null && $value !== '',
            ),
        ], $status);
    }

    /** @return array<string, mixed> */
    private function bulkSyncFailurePayload(
        SellerEventImportService $import,
        int $tournamentId,
        string $environment,
        \Throwable $exception,
        array $sellerApiDebug,
    ): array {
        $debug = $exception instanceof SellerApiRequestException ? $exception->context : [];

        try {
            $preview = $import->previewBulkSync($tournamentId);
            $debug = [
                'environment' => $environment,
                'request_url' => $preview['request_urls'][$environment] ?? null,
                ...$debug,
            ];
        } catch (\Throwable) {
            $debug = [
                'environment' => $environment,
                ...$debug,
            ];
        }

        if (! $exception instanceof SellerApiRequestException) {
            $cause = trim($exception->getMessage());
            if ($cause !== '') {
                $debug['cause'] = $cause;
            }

            $debug['exception'] = $exception::class;

            if (config('app.debug')) {
                $debug['file'] = $exception->getFile();
                $debug['line'] = $exception->getLine();
            }
        }

        $message = trim($exception->getMessage());

        $payload = [
            'message' => $message !== ''
                ? $message
                : 'Seatsbroker bulk sync could not be completed.',
            'debug' => array_filter(
                $debug,
                static fn (mixed $value): bool => $value !== null && $value !== '',
            ),
        ];

        if ($sellerApiDebug !== []) {
            $payload['seller_api_debug'] = $sellerApiDebug;
        }

        return $payload;
    }
}
