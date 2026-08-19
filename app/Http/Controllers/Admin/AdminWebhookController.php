<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebhookLogResource;
use App\Models\EventMapping;
use App\Models\WebhookLog;
use App\Services\Webhooks\WebhookSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWebhookController extends Controller
{
    public function __construct(private readonly WebhookSettingService $settings) {}

    public function showSettings(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $plainToken = null;
        if (! $this->settings->isConfigured()) {
            $plainToken = $this->settings->regenerateBearerToken();
        }

        return response()->json([
            'message' => 'SB order webhook settings.',
            'data' => $this->settings->settingsPayload($plainToken),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'regenerate_token' => ['sometimes', 'boolean'],
        ]);

        $plainToken = null;
        if (($validated['regenerate_token'] ?? false) === true) {
            $plainToken = $this->settings->regenerateBearerToken();
        } else {
            $this->settings->ensureBearerToken();
        }

        return response()->json([
            'message' => $plainToken !== null
                ? 'Webhook bearer token regenerated. Copy it now — it will not be shown again.'
                : 'Webhook settings saved.',
            'data' => $this->settings->settingsPayload($plainToken),
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:received,processed,failed,unauthorized'],
            'booking_no' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = WebhookLog::query()
            ->with('sbOrder:id,booking_no,booking_status_text')
            ->orderByDesc('id');

        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }

        if (filled($validated['booking_no'] ?? null)) {
            $query->where('booking_no', 'like', '%'.$validated['booking_no'].'%');
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'message' => 'Webhook delivery history.',
            'data' => WebhookLogResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
