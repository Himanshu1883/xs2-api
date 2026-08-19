<?php

namespace App\Http\Controllers;

use App\Services\Webhooks\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SbOrderWebhookController extends Controller
{
    public function __construct(private readonly WebhookService $webhooks) {}

    public function store(Request $request): JsonResponse
    {
        $result = $this->webhooks->handleOrderWebhook($request);

        return response()->json($result['body'], $result['status']);
    }
}
