<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Xs2\SandboxGuestDataRequest;
use App\Http\Resources\Xs2SandboxTestOrderResource;
use App\Http\Resources\Xs2SandboxTestOrderSummaryResource;
use App\Models\EventMapping;
use App\Models\Xs2SandboxTestOrder;
use App\Services\Xs2\Xs2ApiDebugRecorder;
use App\Services\Xs2\Xs2SandboxService;
use App\Services\Xs2\Xs2SandboxTestFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Xs2SandboxTestController extends Controller
{
    public function event(Request $request, Xs2SandboxService $sandbox, Xs2ApiDebugRecorder $recorder): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        if (! $sandbox->isConfigured()) {
            return $this->configurationError($recorder);
        }

        $recorder->enable();

        $validated = $request->validate([
            'event_name' => ['nullable', 'string', 'max:128'],
        ]);

        $eventName = filled($validated['event_name'] ?? null)
            ? trim((string) $validated['event_name'])
            : null;

        try {
            $result = $sandbox->fetchSandboxEvent($eventName);
        } catch (\Throwable $exception) {
            return $this->upstreamError($exception, $recorder, 'XS2 sandbox event could not be fetched.');
        }

        return response()->json([
            'message' => 'XS2 sandbox event and listing retrieved successfully.',
            'data' => $result,
            'meta' => [
                'environment' => 'sandbox',
                'is_sandbox' => true,
                'events_tried' => $result['events_tried'] ?? null,
                'max_event_attempts' => $result['max_event_attempts'] ?? null,
                'skipped_events' => $result['skipped_events'] ?? [],
                'xs2_api_debug' => $recorder->flush(),
            ],
        ]);
    }

    public function listing(Request $request, Xs2SandboxService $sandbox, Xs2ApiDebugRecorder $recorder): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'event_id' => ['required', 'string', 'max:128'],
        ]);

        if (! $sandbox->isConfigured()) {
            return $this->configurationError($recorder);
        }

        $recorder->enable();

        try {
            $result = $sandbox->fetchSandboxListing((string) $validated['event_id']);
        } catch (\Throwable $exception) {
            return $this->upstreamError($exception, $recorder, 'XS2 sandbox listing could not be fetched.');
        }

        return response()->json([
            'message' => 'XS2 sandbox listing retrieved successfully.',
            'data' => $result,
            'meta' => [
                'environment' => 'sandbox',
                'is_sandbox' => true,
                'xs2_api_debug' => $recorder->flush(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', 'string', 'max:32'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Xs2SandboxTestOrder::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (filled($validated['search'] ?? null)) {
            $search = (string) $validated['search'];
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('seatsbroker_order_id', 'like', '%'.$search.'%')
                    ->orWhere('xs2_event_id', 'like', '%'.$search.'%')
                    ->orWhere('xs2_ticket_id', 'like', '%'.$search.'%')
                    ->orWhere('xs2_booking_id', 'like', '%'.$search.'%')
                    ->orWhere('xs2_booking_code', 'like', '%'.$search.'%')
                    ->orWhere('xs2_reservation_id', 'like', '%'.$search.'%')
                    ->orWhere('xs2_event_payload->event_name', 'like', '%'.$search.'%');
            });
        }

        if (filled($validated['status'] ?? null)) {
            $query->where('status', (string) $validated['status']);
        }

        $paginator = $query->paginate($validated['per_page'] ?? 20);

        return Xs2SandboxTestOrderSummaryResource::collection($paginator)
            ->additional([
                'message' => 'XS2 sandbox test orders retrieved successfully.',
            ])
            ->response();
    }

    public function createDummyOrder(Request $request, Xs2SandboxTestFlowService $flow): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'event' => ['required', 'array'],
            'event.external_event_id' => ['required', 'string', 'max:128'],
            'listing' => ['required', 'array'],
            'listing.ticket_id' => ['required', 'string', 'max:128'],
            'listing.net_rate' => ['required', 'integer', 'min:1'],
            'listing.stock' => ['nullable', 'integer', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $order = $flow->createDummySeatsBrokerOrder(
                $request->input('event', $validated['event']),
                $request->input('listing', $validated['listing']),
                (int) ($validated['quantity'] ?? 1),
            );
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Dummy SeatsBroker order could not be created.',
            ], 422);
        }

        return response()->json([
            'message' => 'Dummy SeatsBroker sandbox order created successfully.',
            'data' => new Xs2SandboxTestOrderResource($order),
            'meta' => [
                'environment' => 'sandbox',
                'is_sandbox' => true,
                'test_order' => true,
            ],
        ], 201);
    }

    public function createXs2Order(
        Xs2SandboxTestOrder $xs2SandboxTestOrder,
        Xs2SandboxTestFlowService $flow,
        Xs2ApiDebugRecorder $recorder,
    ): JsonResponse {
        $this->authorize('viewAny', EventMapping::class);

        if ($xs2SandboxTestOrder->hasXs2Order()) {
            return response()->json([
                'message' => 'XS2 Order Already Created',
                'data' => new Xs2SandboxTestOrderResource($xs2SandboxTestOrder),
                'meta' => [
                    'environment' => 'sandbox',
                    'is_sandbox' => true,
                    'already_created' => true,
                ],
            ]);
        }

        $recorder->enable();

        try {
            $result = $flow->createXs2Order($xs2SandboxTestOrder);
        } catch (\Throwable $exception) {
            return $this->upstreamError(
                $exception,
                $recorder,
                'XS2 sandbox booking could not be created.',
                $xs2SandboxTestOrder->fresh(),
            );
        }

        return response()->json([
            'message' => $result['already_created']
                ? 'XS2 Order Already Created'
                : 'XS2 sandbox booking created successfully.',
            'data' => new Xs2SandboxTestOrderResource($result['order']),
            'meta' => [
                'environment' => 'sandbox',
                'is_sandbox' => true,
                'already_created' => $result['already_created'],
                'xs2_api_debug' => $recorder->flush(),
            ],
        ], $result['already_created'] ? 200 : 201);
    }

    public function show(Xs2SandboxTestOrder $xs2SandboxTestOrder): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        return response()->json([
            'message' => 'XS2 sandbox test order retrieved successfully.',
            'data' => new Xs2SandboxTestOrderResource($xs2SandboxTestOrder),
            'meta' => [
                'environment' => 'sandbox',
                'is_sandbox' => true,
            ],
        ]);
    }

    public function xs2BookingOrders(
        Request $request,
        Xs2SandboxService $sandbox,
        Xs2ApiDebugRecorder $recorder,
    ): JsonResponse {
        $this->authorize('viewAny', EventMapping::class);

        if (! $sandbox->isConfigured()) {
            return $this->configurationError($recorder);
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'booking_id' => ['nullable', 'string', 'max:128'],
            'bookingorder_id' => ['nullable', 'string', 'max:128'],
            'booking_email' => ['nullable', 'string', 'max:255'],
            'booking_code' => ['nullable', 'string', 'max:64'],
            'logistic_status' => ['nullable', 'string', 'max:64'],
            'event_id' => ['nullable', 'string', 'max:128'],
        ]);

        $query = array_filter([
            'page' => $validated['page'] ?? 1,
            'page_size' => $validated['page_size'] ?? 25,
            'booking_id' => $validated['booking_id'] ?? null,
            'bookingorder_id' => $validated['bookingorder_id'] ?? null,
            'booking_email' => $validated['booking_email'] ?? null,
            'booking_code' => $validated['booking_code'] ?? null,
            'logistic_status' => $validated['logistic_status'] ?? null,
            'event_id' => $validated['event_id'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $recorder->enable();

        try {
            $response = $sandbox->fetchBookingOrders($query);
        } catch (\Throwable $exception) {
            return $this->upstreamError($exception, $recorder, 'XS2 sandbox booking orders could not be fetched.');
        }

        $orders = $response['bookingorders'] ?? [];
        if (! is_array($orders)) {
            $orders = [];
        }

        $normalized = [];
        foreach ($orders as $order) {
            if (! is_array($order)) {
                continue;
            }

            $normalized[] = $this->normalizeRemoteBookingOrder($order);
        }

        return response()->json([
            'message' => 'XS2 sandbox booking orders retrieved successfully.',
            'data' => [
                'bookingorders' => $normalized,
                'raw_count' => count($normalized),
            ],
            'meta' => [
                'environment' => 'sandbox',
                'is_sandbox' => true,
                'pagination' => is_array($response['pagination'] ?? null) ? $response['pagination'] : null,
                'request_query' => $query,
                'xs2_api_debug' => $recorder->flush(),
            ],
        ]);
    }

    public function importFromXs2(
        Request $request,
        Xs2SandboxTestFlowService $flow,
        Xs2SandboxService $sandbox,
        Xs2ApiDebugRecorder $recorder,
    ): JsonResponse {
        $this->authorize('viewAny', EventMapping::class);

        if (! $sandbox->isConfigured()) {
            return $this->configurationError($recorder);
        }

        $validated = $request->validate([
            'bookingorder_id' => ['nullable', 'string', 'max:128', 'required_without:booking_id'],
            'booking_id' => ['nullable', 'string', 'max:128', 'required_without:bookingorder_id'],
        ]);

        $recorder->enable();

        try {
            $result = $flow->importFromXs2($validated);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            return $this->upstreamError($exception, $recorder, 'XS2 sandbox booking order could not be imported.');
        }

        return response()->json([
            'message' => $result['already_imported']
                ? 'XS2 sandbox booking order was already saved locally.'
                : 'XS2 sandbox booking order imported successfully.',
            'data' => new Xs2SandboxTestOrderResource($result['order']),
            'meta' => [
                'environment' => 'sandbox',
                'is_sandbox' => true,
                'already_imported' => $result['already_imported'],
                'imported_from_xs2' => ! $result['already_imported'],
                'xs2_api_debug' => $recorder->flush(),
            ],
        ], $result['already_imported'] ? 200 : 201);
    }

    public function refreshFromXs2(
        Xs2SandboxTestOrder $xs2SandboxTestOrder,
        Xs2SandboxTestFlowService $flow,
        Xs2SandboxService $sandbox,
        Xs2ApiDebugRecorder $recorder,
    ): JsonResponse {
        $this->authorize('viewAny', EventMapping::class);

        if (! $sandbox->isConfigured()) {
            return $this->configurationError($recorder);
        }

        $recorder->enable();

        try {
            $order = $flow->refreshFromXs2($xs2SandboxTestOrder);
        } catch (\Throwable $exception) {
            return $this->upstreamError(
                $exception,
                $recorder,
                'XS2 sandbox booking could not be refreshed.',
                $xs2SandboxTestOrder->fresh(),
            );
        }

        return response()->json([
            'message' => 'XS2 sandbox booking refreshed successfully.',
            'data' => new Xs2SandboxTestOrderResource($order),
            'meta' => [
                'environment' => 'sandbox',
                'is_sandbox' => true,
                'refreshed_from_xs2' => true,
                'xs2_api_debug' => $recorder->flush(),
            ],
        ]);
    }

    public function guestDataForm(
        Xs2SandboxTestOrder $xs2SandboxTestOrder,
        Xs2SandboxTestFlowService $flow,
        Xs2SandboxService $sandbox,
        Xs2ApiDebugRecorder $recorder,
    ): JsonResponse {
        $this->authorize('viewAny', EventMapping::class);

        if (! $xs2SandboxTestOrder->xs2_booking_id) {
            return response()->json([
                'message' => 'Guest data can only be updated after an XS2 booking has been created.',
            ], 422);
        }

        if (! $sandbox->isConfigured()) {
            return $this->configurationError($recorder);
        }

        $recorder->enable();

        try {
            $form = $flow->guestDataForm($xs2SandboxTestOrder);
        } catch (\Throwable $exception) {
            return $this->upstreamError(
                $exception,
                $recorder,
                'XS2 sandbox guest data requirements could not be loaded.',
            );
        }

        return response()->json([
            'message' => 'XS2 sandbox guest data form loaded successfully.',
            'data' => $form,
            'meta' => [
                'environment' => 'sandbox',
                'is_sandbox' => true,
                'xs2_api_debug' => $recorder->flush(),
            ],
        ]);
    }

    public function updateGuestData(
        SandboxGuestDataRequest $request,
        Xs2SandboxTestOrder $xs2SandboxTestOrder,
        Xs2SandboxTestFlowService $flow,
        Xs2SandboxService $sandbox,
        Xs2ApiDebugRecorder $recorder,
    ): JsonResponse {
        $this->authorize('viewAny', EventMapping::class);

        if (! $xs2SandboxTestOrder->xs2_booking_id) {
            return response()->json([
                'message' => 'Guest data can only be updated after an XS2 booking has been created.',
            ], 422);
        }

        if (! $sandbox->isConfigured()) {
            return $this->configurationError($recorder);
        }

        $guests = $request->validated('guests');
        if (! is_array($guests)) {
            return response()->json([
                'message' => 'Guest data must include a guests array.',
            ], 422);
        }

        if (count($guests) !== $xs2SandboxTestOrder->quantity) {
            return response()->json([
                'message' => sprintf(
                    'Expected %d guest(s) for this order but received %d.',
                    $xs2SandboxTestOrder->quantity,
                    count($guests),
                ),
            ], 422);
        }

        $recorder->enable();

        try {
            $order = $flow->updateGuestData($xs2SandboxTestOrder, $guests);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            $status = $exception instanceof \App\Exceptions\Integrations\Xs2RequestException
                && $exception->status !== null
                && $exception->status >= 400
                && $exception->status < 500
                ? $exception->status
                : 502;

            return response()->json([
                'message' => trim($exception->getMessage()) !== ''
                    ? $exception->getMessage()
                    : 'XS2 sandbox guest data could not be updated.',
                'data' => $xs2SandboxTestOrder->fresh() !== null
                    ? new Xs2SandboxTestOrderResource($xs2SandboxTestOrder->fresh())
                    : null,
                'meta' => [
                    'environment' => 'sandbox',
                    'is_sandbox' => true,
                    'xs2_api_debug' => $recorder->flush(),
                ],
            ], $status);
        }

        return response()->json([
            'message' => 'XS2 sandbox guest data updated successfully.',
            'data' => new Xs2SandboxTestOrderResource($order),
            'meta' => [
                'environment' => 'sandbox',
                'is_sandbox' => true,
                'xs2_api_debug' => $recorder->flush(),
            ],
        ]);
    }

    public function downloadEticket(
        Request $request,
        Xs2SandboxTestOrder $xs2SandboxTestOrder,
        Xs2SandboxTestFlowService $flow,
        Xs2SandboxService $sandbox,
        Xs2ApiDebugRecorder $recorder,
    ): Response|JsonResponse {
        $this->authorize('viewAny', EventMapping::class);

        if (! $xs2SandboxTestOrder->xs2_booking_id) {
            return response()->json([
                'message' => 'E-tickets can only be downloaded after an XS2 booking has been created.',
            ], 422);
        }

        if ($xs2SandboxTestOrder->status !== Xs2SandboxTestOrder::STATUS_XS2_ORDER_CREATED) {
            return response()->json([
                'message' => 'E-tickets can only be downloaded when the sandbox test order status is xs2_order_created.',
            ], 422);
        }

        if (! $sandbox->isConfigured()) {
            return $this->configurationError($recorder);
        }

        $validated = $request->validate([
            'item_index' => ['nullable', 'integer', 'min:0'],
        ]);

        $recorder->enable();

        try {
            $result = $flow->downloadEticket(
                $xs2SandboxTestOrder,
                (int) ($validated['item_index'] ?? 0),
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => new Xs2SandboxTestOrderResource($xs2SandboxTestOrder->fresh()),
                'meta' => [
                    'environment' => 'sandbox',
                    'is_sandbox' => true,
                    'xs2_api_debug' => $recorder->flush(),
                ],
            ], 422);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => new Xs2SandboxTestOrderResource($xs2SandboxTestOrder->fresh()),
                'meta' => [
                    'environment' => 'sandbox',
                    'is_sandbox' => true,
                    'xs2_api_debug' => $recorder->flush(),
                ],
            ], 422);
        } catch (\Throwable $exception) {
            return $this->upstreamError(
                $exception,
                $recorder,
                'XS2 sandbox e-ticket could not be downloaded.',
                $xs2SandboxTestOrder->fresh(),
            );
        }

        return response($result['body'], 200, [
            'Content-Type' => $result['content_type'],
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function normalizeRemoteBookingOrder(array $order): array
    {
        $items = $order['items'] ?? [];
        $itemCount = is_array($items) ? count($items) : 0;

        return [
            'bookingorder_id' => $order['bookingorder_id'] ?? null,
            'booking_id' => $order['booking_id'] ?? null,
            'booking_code' => $order['booking_code'] ?? null,
            'booking_email' => $order['booking_email'] ?? null,
            'reservation_id' => $order['reservation_id'] ?? null,
            'event_id' => $order['event_id'] ?? null,
            'event_name' => $order['event_name'] ?? null,
            'logistic_status' => $order['logistic_status'] ?? null,
            'guestdata_status' => $order['guestdata_status'] ?? null,
            'item_count' => $itemCount,
            'created' => $order['created'] ?? $order['created_at'] ?? null,
            'catalog_payload' => $order,
        ];
    }

    private function configurationError(Xs2ApiDebugRecorder $recorder): JsonResponse
    {
        return response()->json([
            'message' => 'XS2 sandbox credentials are not configured. Set XS2_SANDBOX_API_URL and XS2_SANDBOX_API_KEY in the API .env file.',
            'meta' => [
                'environment' => 'sandbox',
                'is_sandbox' => true,
                'xs2_api_debug' => $recorder->flush(),
            ],
        ], 503);
    }

    private function upstreamError(
        \Throwable $exception,
        Xs2ApiDebugRecorder $recorder,
        string $fallbackMessage,
        ?Xs2SandboxTestOrder $order = null,
    ): JsonResponse {
        $message = trim($exception->getMessage());

        return response()->json([
            'message' => $message !== '' ? $message : $fallbackMessage,
            'data' => $order !== null ? new Xs2SandboxTestOrderResource($order) : null,
            'meta' => [
                'environment' => 'sandbox',
                'is_sandbox' => true,
                'xs2_api_debug' => $recorder->flush(),
            ],
        ], 502);
    }
}
