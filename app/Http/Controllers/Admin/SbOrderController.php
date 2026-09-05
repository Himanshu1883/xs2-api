<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SbOrderIndexRequest;
use App\Http\Resources\SbOrderResource;
use App\Http\Resources\SbOrderXs2SyncLogResource;
use App\Jobs\CreateXs2SandboxOrderFromSbOrder;
use App\Models\EventMapping;
use App\Models\SbOrder;
use App\Models\SbOrderXs2SyncLog;
use App\Services\Admin\ApiEnvironmentService;
use App\Services\SellerApi\SellerBookingSyncService;
use App\Services\Xs2\SbOrderXs2GuestDataSyncService;
use App\Services\Xs2\SbOrderXs2SandboxOrderService;
use Illuminate\Http\JsonResponse;

class SbOrderController extends Controller
{
    public function __construct(
        private readonly SbOrderXs2SandboxOrderService $xs2SandboxOrders,
        private readonly ApiEnvironmentService $apiEnvironment,
    ) {}

    public function index(SbOrderIndexRequest $request)
    {
        $this->authorize('viewAny', EventMapping::class);

        $filters = $request->validated();
        $search = trim((string) ($filters['search'] ?? ''));

        $query = SbOrder::query()->with(['attendees', 'xs2Order'])->withCount('attendees');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('booking_no', 'like', $like)
                    ->orWhere('match_name', 'like', $like)
                    ->orWhere('tournament_name', 'like', $like)
                    ->orWhere('stadium_name', 'like', $like)
                    ->orWhere('buyer_first_name', 'like', $like)
                    ->orWhere('buyer_last_name', 'like', $like)
                    ->orWhere('listing_id', 'like', $like)
                    ->orWhereRaw("CONCAT(COALESCE(buyer_first_name, ''), ' ', COALESCE(buyer_last_name, '')) like ?", [$like]);
            });
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('booking_status', (int) $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('match_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('match_date', '<=', $filters['date_to']);
        }

        $orders = $query->orderByDesc('synced_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 20);
        $this->xs2SandboxOrders->attachXs2ListingResolutions($orders->getCollection());

        return SbOrderResource::collection($orders);
    }

    public function show(SbOrder $sbOrder): SbOrderResource
    {
        $this->authorize('viewAny', EventMapping::class);

        $sbOrder->load(['attendees', 'xs2Order'])->loadCount('attendees');
        $this->xs2SandboxOrders->attachXs2ListingResolutions([$sbOrder]);

        return new SbOrderResource($sbOrder);
    }

    public function sync(SellerBookingSyncService $sync): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $summary = $sync->sync();

        if (($summary['status'] ?? '') === 'failed') {
            $error = trim((string) ($summary['error'] ?? ''));
            $message = $error !== ''
                ? $error
                : 'Seller API booking sync failed.';

            if (str_contains(strtolower($message), 'invalid api key')
                || str_contains(strtolower($message), 'in-active')) {
                $message .= ' Set SELLER_API_LISTING_API_KEY to your SeatsBrokers seller Sanctum key (apiKey header), not the external catalog Bearer token. Confirm SELLER_API_LISTING_BASE_URL matches the environment where the order exists (sandbox vs production sellerapi).';
            }

            return response()->json([
                'message' => $message,
                'data' => $summary,
            ], 502);
        }

        $host = is_string($summary['listing_base_url'] ?? null) ? trim((string) $summary['listing_base_url']) : '';
        $hostLabel = $host !== '' ? $host : 'Seller API';
        $apiTotal = $summary['api_total'] ?? null;
        $totalHint = is_numeric($apiTotal)
            ? sprintf(', API total=%d', (int) $apiTotal)
            : '';

        return response()->json([
            'message' => sprintf(
                'Synced %d booking(s) from %s (%d created, %d updated, %d attendee row(s), %d listing stock update(s) queued%s). Sync only imports what this listing host returns for the configured listing apiKey.',
                $summary['fetched'],
                $hostLabel,
                $summary['created'],
                $summary['updated'],
                $summary['attendees'],
                $summary['stock_reconcile_queued'] ?? 0,
                $totalHint,
            ),
            'data' => $summary,
        ]);
    }

    public function refresh(SbOrder $sbOrder, SellerBookingSyncService $sync): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        try {
            $refreshed = $sync->syncOrder($sbOrder);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $statusLabel = $refreshed->booking_status_text
            ?? ($refreshed->booking_status !== null ? (string) $refreshed->booking_status : 'unknown');

        $refreshed->load(['attendees', 'xs2Order'])->loadCount('attendees');
        $this->xs2SandboxOrders->attachXs2ListingResolutions([$refreshed]);

        return response()->json([
            'message' => sprintf(
                'Updated booking %s from Seller API (status: %s).',
                $refreshed->booking_no,
                $statusLabel,
            ),
            'data' => new SbOrderResource($refreshed),
        ]);
    }

    public function fetchAttendees(SbOrder $sbOrder, SellerBookingSyncService $sync): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        try {
            $refreshed = $sync->fetchAttendees($sbOrder, true);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $refreshed->load(['attendees', 'xs2Order'])->loadCount('attendees');
        $this->xs2SandboxOrders->attachXs2ListingResolutions([$refreshed]);

        if ($refreshed->attendees->isEmpty()) {
            return response()->json([
                'message' => 'No attendee details returned from Seats Broker for this order.',
                'data' => new SbOrderResource($refreshed),
            ], 422);
        }

        return response()->json([
            'message' => sprintf(
                'Fetched %d attendee(s) from Seats Broker for booking %s.',
                $refreshed->attendees->count(),
                $refreshed->booking_no,
            ),
            'data' => new SbOrderResource($refreshed),
        ]);
    }

    public function createXs2Order(SbOrder $sbOrder): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $sbOrder->load(['attendees', 'xs2Order'])->loadCount('attendees');

        if ($sbOrder->xs2Order && filled($sbOrder->xs2Order->xs2_booking_id)) {
            return response()->json([
                'message' => 'XS2 order already exists for this SB order.',
            ], 422);
        }

        $skipReason = $this->xs2SandboxOrders->resolveManualCreateSkipReason($sbOrder);
        if ($skipReason !== null) {
            return response()->json([
                'message' => $skipReason,
            ], 422);
        }

        $this->xs2SandboxOrders->recordQueueDecision($sbOrder);
        CreateXs2SandboxOrderFromSbOrder::dispatch($sbOrder->id);

        $this->xs2SandboxOrders->attachXs2ListingResolutions([$sbOrder]);

        $isSandbox = $this->apiEnvironment->xs2OrdersEnvironment() === ApiEnvironmentService::ENV_SANDBOX;
        $envLabel = $isSandbox ? 'sandbox' : 'production';

        return response()->json([
            'message' => sprintf(
                'XS2 %s order creation queued for booking %s. Reservation and booking will complete in the background.',
                $envLabel,
                $sbOrder->booking_no,
            ),
            'data' => [
                'queued' => true,
                'sb_order' => new SbOrderResource($sbOrder),
            ],
        ], 202);
    }

    public function moveToXs2Order(SbOrder $sbOrder, SbOrderXs2GuestDataSyncService $guestData): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $result = $guestData->copyAttendeesFromSbOrder($sbOrder);

        if (! ($result['copied'] ?? false)) {
            return response()->json([
                'message' => $result['error'] ?? $result['reason'] ?? 'Could not move attendees to the XS2 order.',
            ], 422);
        }

        $sbOrder->load(['attendees', 'xs2Order'])->loadCount('attendees');
        $this->xs2SandboxOrders->attachXs2ListingResolutions([$sbOrder]);

        return response()->json([
            'message' => sprintf(
                'Moved %d attendee(s) from booking %s onto XS2 order %s.',
                $sbOrder->attendees->count(),
                $sbOrder->booking_no,
                $sbOrder->xs2Order?->external_order_id ?? $result['xs2_order_id'],
            ),
            'data' => new SbOrderResource($sbOrder),
        ]);
    }

    public function xs2SyncLog(SbOrder $sbOrder): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $log = SbOrderXs2SyncLog::query()
            ->where('sb_order_id', $sbOrder->id)
            ->first();

        return response()->json([
            'data' => $log !== null ? new SbOrderXs2SyncLogResource($log) : null,
        ]);
    }
}
