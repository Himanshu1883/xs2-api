<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventMapping;
use App\Services\Xs2\Xs2IntegrationResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminXs2ResetController extends Controller
{
    public function __construct(
        private readonly Xs2IntegrationResetService $reset,
    ) {}

    public function resetAll(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'confirm' => ['required', 'accepted'],
            'preserve_orders' => ['sometimes', 'boolean'],
        ]);

        $preserveOrders = array_key_exists('preserve_orders', $validated)
            ? (bool) $validated['preserve_orders']
            : true;

        $summary = $this->reset->wipe($preserveOrders);

        return response()->json([
            'message' => $preserveOrders
                ? 'XS2 catalogue wiped (orders preserved).'
                : 'XS2 integration data wiped.',
            'data' => $summary,
        ]);
    }
}
