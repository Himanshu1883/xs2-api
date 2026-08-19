<?php

namespace App\Services\Webhooks;

use App\Models\WebhookLog;
use App\Services\SellerApi\SellerBookingSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public function __construct(
        private readonly WebhookSettingService $settings,
        private readonly SellerBookingSyncService $bookingSync,
    ) {}

    /**
     * @return array{status:int, body:array<string, mixed>, log:WebhookLog}
     */
    public function handleOrderWebhook(Request $request): array
    {
        $startedAt = microtime(true);
        $payload = $request->all();
        $bookingNo = is_string($payload['booking_no'] ?? null) ? trim($payload['booking_no']) : null;
        if ($bookingNo === '') {
            $bookingNo = null;
        }

        $providedToken = $this->extractBearerToken($request);
        if (! $this->settings->validateBearerToken($providedToken)) {
            return $this->finalize(
                startedAt: $startedAt,
                request: $request,
                bookingNo: $bookingNo,
                payload: $payload,
                status: WebhookLog::STATUS_UNAUTHORIZED,
                httpStatus: 401,
                response: ['message' => 'Invalid or missing bearer token.'],
                errorMessage: 'Bearer token validation failed.',
            );
        }

        if ($bookingNo === null) {
            return $this->finalize(
                startedAt: $startedAt,
                request: $request,
                bookingNo: null,
                payload: $payload,
                status: WebhookLog::STATUS_FAILED,
                httpStatus: 422,
                response: [
                    'message' => 'The booking_no field is required.',
                    'errors' => ['booking_no' => ['The booking_no field is required.']],
                ],
                errorMessage: 'Missing booking_no in webhook payload.',
            );
        }

        try {
            $result = $this->bookingSync->processBookingPayload($payload);

            return $this->finalize(
                startedAt: $startedAt,
                request: $request,
                bookingNo: $bookingNo,
                payload: $payload,
                status: WebhookLog::STATUS_PROCESSED,
                httpStatus: 200,
                response: [
                    'message' => $result['created'] ? 'SB order created from webhook.' : 'SB order updated from webhook.',
                    'data' => $result,
                ],
                sbOrderId: $result['sb_order_id'],
            );
        } catch (\Throwable $exception) {
            Log::warning('SB order webhook processing failed.', [
                'booking_no' => $bookingNo,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->finalize(
                startedAt: $startedAt,
                request: $request,
                bookingNo: $bookingNo,
                payload: $payload,
                status: WebhookLog::STATUS_FAILED,
                httpStatus: 422,
                response: ['message' => $exception->getMessage()],
                errorMessage: $exception->getMessage(),
            );
        }
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = trim((string) $request->header('Authorization', ''));
        if ($header === '') {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $response
     * @return array{status:int, body:array<string, mixed>, log:WebhookLog}
     */
    private function finalize(
        float $startedAt,
        Request $request,
        ?string $bookingNo,
        array $payload,
        string $status,
        int $httpStatus,
        array $response,
        ?string $errorMessage = null,
        ?int $sbOrderId = null,
    ): array {
        $processingMs = (int) round((microtime(true) - $startedAt) * 1000);

        $log = WebhookLog::query()->create([
            'booking_no' => $bookingNo,
            'http_status' => $httpStatus,
            'status' => $status,
            'payload' => $payload,
            'response' => $response,
            'error_message' => $errorMessage,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'processing_ms' => $processingMs,
            'sb_order_id' => $sbOrderId,
        ]);

        return [
            'status' => $httpStatus,
            'body' => $response,
            'log' => $log,
        ];
    }
}
