<?php

namespace App\Services\Xs2;

use App\Models\SbOrderXs2SyncLog;
use Illuminate\Support\Facades\Schema;

class SbOrderXs2SyncLogService
{
    public function recordNotQueued(int $sbOrderId, string $reason): void
    {
        $this->upsert($sbOrderId, [
            'status' => SbOrderXs2SyncLog::STATUS_NOT_QUEUED,
            'skip_reason' => $reason,
            'error' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $reservationRequest
     */
    public function recordQueued(int $sbOrderId, ?array $reservationRequest = null): void
    {
        $attributes = [
            'status' => SbOrderXs2SyncLog::STATUS_QUEUED,
            'skip_reason' => null,
            'error' => null,
        ];

        if ($reservationRequest !== null) {
            $attributes['reservation_request'] = $reservationRequest;
        }

        $this->upsert($sbOrderId, $attributes);
    }

    /**
     * @param  array<string, mixed>|null  $reservationRequest
     */
    public function recordProcessing(int $sbOrderId, ?array $reservationRequest = null): void
    {
        $attributes = [
            'status' => SbOrderXs2SyncLog::STATUS_PROCESSING,
            'skip_reason' => null,
            'error' => null,
        ];

        if ($reservationRequest !== null) {
            $attributes['reservation_request'] = $reservationRequest;
        }

        $this->upsert($sbOrderId, $attributes);
    }

    public function recordSkipped(int $sbOrderId, string $reason, ?int $xs2OrderId = null): void
    {
        $this->upsert($sbOrderId, [
            'status' => SbOrderXs2SyncLog::STATUS_SKIPPED,
            'skip_reason' => $reason,
            'xs2_order_id' => $xs2OrderId,
            'error' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array{
     *     success: bool,
     *     status: int|null,
     *     data: array<string, mixed>,
     *     headers: array<string, list<string>>,
     *     message: string|null
     * }  $response
     */
    public function recordReservationExchange(int $sbOrderId, array $request, array $response): void
    {
        $this->upsert($sbOrderId, [
            'reservation_request' => $request,
            'reservation_response' => $this->normalizeExchangeResponse($response),
            'reservation_response_status' => $response['status'],
            'reservation_response_headers' => $response['headers'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array{
     *     success: bool,
     *     status: int|null,
     *     data: array<string, mixed>,
     *     headers: array<string, list<string>>,
     *     message: string|null
     * }  $response
     */
    public function recordBookingExchange(int $sbOrderId, array $request, array $response): void
    {
        $this->upsert($sbOrderId, [
            'booking_request' => $request,
            'booking_response' => $this->normalizeExchangeResponse($response),
            'booking_response_status' => $response['status'],
            'booking_response_headers' => $response['headers'],
        ]);
    }

    public function recordSuccess(int $sbOrderId, int $xs2OrderId): void
    {
        $this->upsert($sbOrderId, [
            'status' => SbOrderXs2SyncLog::STATUS_SUCCESS,
            'xs2_order_id' => $xs2OrderId,
            'skip_reason' => null,
            'error' => null,
        ]);
    }

    public function recordFailure(int $sbOrderId, string $error, ?int $xs2OrderId = null): void
    {
        $this->upsert($sbOrderId, [
            'status' => SbOrderXs2SyncLog::STATUS_FAILED,
            'xs2_order_id' => $xs2OrderId,
            'error' => mb_substr($error, 0, 2000),
        ]);
    }

    /**
     * @param  array{
     *     success: bool,
     *     status: int|null,
     *     data: array<string, mixed>,
     *     headers: array<string, list<string>>,
     *     message: string|null
     * }  $response
     * @return array<string, mixed>|null
     */
    private function normalizeExchangeResponse(array $response): ?array
    {
        if ($response['data'] !== []) {
            return $response['data'];
        }

        $payload = [];
        if ($response['message'] !== null && $response['message'] !== '') {
            $payload['message'] = $response['message'];
        }
        if ($response['status'] !== null) {
            $payload['http_status'] = $response['status'];
        }
        if (($response['success'] ?? false) === false) {
            $payload['success'] = false;
        }

        return $payload !== [] ? $payload : null;
    }

    /** @param array<string, mixed> $attributes */
    private function upsert(int $sbOrderId, array $attributes): ?SbOrderXs2SyncLog
    {
        if (! Schema::hasTable('sb_order_xs2_sync_logs')) {
            return null;
        }

        $existing = SbOrderXs2SyncLog::query()->where('sb_order_id', $sbOrderId)->first();
        if ($existing === null && ! isset($attributes['status'])) {
            $attributes['status'] = SbOrderXs2SyncLog::STATUS_QUEUED;
        }

        return SbOrderXs2SyncLog::query()->updateOrCreate(
            ['sb_order_id' => $sbOrderId],
            $attributes,
        );
    }
}
