<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\Integrations\Xs2ConfigurationException;
use App\Exceptions\Integrations\Xs2RequestException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Xs2\Xs2OrderIndexRequest;
use App\Http\Resources\Xs2OrderResource;
use App\Models\EventMapping;
use App\Models\Xs2Order;
use App\Services\Admin\ApiEnvironmentService;
use App\Services\Xs2\Xs2OrderSyncService;
use Illuminate\Http\JsonResponse;

class Xs2OrderController extends Controller
{
    public function __construct(
        private readonly ApiEnvironmentService $apiEnvironment,
    ) {}

    public function index(Xs2OrderIndexRequest $request)
    {
        $this->authorize('viewAny', EventMapping::class);

        $filters = $request->validated();
        $search = trim((string) ($filters['search'] ?? ''));

        $query = Xs2Order::query()->with(['attendees', 'sbOrder'])->withCount('attendees');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('external_order_id', 'like', $like)
                    ->orWhere('xs2_booking_id', 'like', $like)
                    ->orWhere('xs2_bookingorder_id', 'like', $like)
                    ->orWhere('event_name', 'like', $like)
                    ->orWhere('venue_name', 'like', $like)
                    ->orWhere('buyer_first_name', 'like', $like)
                    ->orWhere('buyer_last_name', 'like', $like)
                    ->orWhere('buyer_email', 'like', $like)
                    ->orWhere('external_event_id', 'like', $like)
                    ->orWhereRaw("CONCAT(COALESCE(buyer_first_name, ''), ' ', COALESCE(buyer_last_name, '')) like ?", [$like])
                    ->orWhereHas('sbOrder', fn ($sb) => $sb->where('booking_no', 'like', $like));
            });
        }

        if (array_key_exists('is_sandbox', $filters) && $filters['is_sandbox'] !== null && $filters['is_sandbox'] !== '') {
            $query->where('is_sandbox', filter_var($filters['is_sandbox'], FILTER_VALIDATE_BOOLEAN));
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('order_status', (string) $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('event_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('event_date', '<=', $filters['date_to']);
        }

        return Xs2OrderResource::collection(
            $query->orderByDesc('synced_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 20)
        )->additional([
            'create_order_environment' => $this->apiEnvironment->xs2OrdersEnvironment(),
        ]);
    }

    public function show(Xs2Order $xs2Order): Xs2OrderResource
    {
        $this->authorize('viewAny', EventMapping::class);

        $xs2Order->load(['attendees', 'sbOrder'])->loadCount('attendees');

        return new Xs2OrderResource($xs2Order);
    }

    public function sync(Xs2OrderSyncService $sync): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        try {
            $summary = $sync->sync();
        } catch (Xs2ConfigurationException|Xs2RequestException $exception) {
            $status = $exception instanceof Xs2RequestException && $exception->status !== null
                ? max(400, min(599, $exception->status))
                : 422;

            return response()->json([
                'message' => $exception->getMessage(),
                'data' => [
                    'endpoint' => config('xs2.sandbox.bookingorders_endpoint', '/v1/bookingorders'),
                    'environment' => 'sandbox',
                    'is_sandbox' => true,
                ],
            ], $status);
        }

        return response()->json([
            'message' => sprintf(
                'Synced %d sandbox order(s) from XS2 Test API GET %s (%d created, %d updated, %d attendee row(s)).',
                $summary['fetched'],
                $summary['endpoint'],
                $summary['created'],
                $summary['updated'],
                $summary['attendees'],
            ),
            'data' => $summary,
        ]);
    }
}
