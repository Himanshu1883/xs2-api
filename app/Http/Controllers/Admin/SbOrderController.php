<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SbOrderIndexRequest;
use App\Http\Resources\SbOrderResource;
use App\Http\Resources\SbOrderXs2SyncLogResource;
use App\Models\EventMapping;
use App\Models\SbOrder;
use App\Models\SbOrderXs2SyncLog;
use App\Services\SellerApi\SellerBookingSyncService;
use App\Services\Xs2\SbOrderXs2GuestDataSyncService;
use Illuminate\Http\JsonResponse;

class SbOrderController extends Controller
{
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

        return SbOrderResource::collection(
            $query->orderByDesc('synced_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 20)
        );
    }

    public function show(SbOrder $sbOrder): SbOrderResource
    {
        $this->authorize('viewAny', EventMapping::class);

        $sbOrder->load(['attendees', 'xs2Order'])->loadCount('attendees');

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
