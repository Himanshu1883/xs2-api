<?php

namespace App\Services\Xs2;

use App\Exceptions\Integrations\Xs2RequestException;
use App\Models\SbOrder;
use App\Models\SbOrderAttendee;
use App\Models\Xs2Order;
use App\Models\Xs2OrderAttendee;
use App\Models\Xs2OrderGuestDataLog;
use App\Models\Xs2SyncState;
use App\Models\Xs2Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Push SB order attendee_details to the linked XS2 booking order guest-data API.
 */
class SbOrderXs2GuestDataSyncService
{
    public const SYNC_RESOURCE = 'sb-order-guest-data:sync';

    public function __construct(
        private readonly Xs2SandboxService $sandbox,
        private readonly Xs2Client $client,
        private readonly Xs2GuestDataPayloadBuilder $payloadBuilder,
    ) {}

    /**
     * @return array{
     *     synced: int,
     *     skipped: int,
     *     failed: int,
     *     errors: list<array{sb_order_id: int|null, xs2_order_id: int|null, message: string}>
     * }
     */
    public function syncPending(?int $limit = null): array
    {
        if (Schema::hasTable('xs2_sync_states')) {
            Xs2SyncState::query()->firstOrCreate(['resource' => self::SYNC_RESOURCE])->update([
                'status' => 'running',
                'last_attempted_at' => now(),
                'last_error' => null,
            ]);
        }

        $summary = [
            'synced' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        try {
            $limit = $limit ?? max(1, (int) config('xs2.sb_order_guest_data_sync.batch_limit', 50));

            foreach ($this->pendingOrdersQuery()->limit($limit)->get() as $xs2Order) {
                $result = $this->syncForXs2Order($xs2Order);

                if ($result['skipped'] ?? false) {
                    $summary['skipped']++;

                    continue;
                }

                if ($result['synced'] ?? false) {
                    $summary['synced']++;

                    continue;
                }

                $summary['failed']++;
                $summary['errors'][] = [
                    'sb_order_id' => $result['sb_order_id'] ?? null,
                    'xs2_order_id' => $result['xs2_order_id'] ?? null,
                    'message' => (string) ($result['error'] ?? 'Guest data sync failed.'),
                ];
            }

            $this->finalizeRun($summary);

            return $summary;
        } catch (Throwable $exception) {
            $this->finalizeRun($summary, $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * @return array{
     *     synced: bool,
     *     skipped: bool,
     *     sb_order_id: int|null,
     *     xs2_order_id: int|null,
     *     error: string|null,
     *     reason: string|null
     * }
     */
    public function syncForSbOrder(SbOrder $order): array
    {
        $order->loadMissing(['attendees', 'xs2Order']);
        $xs2Order = $order->xs2Order;

        if ($xs2Order === null) {
            return $this->skipResult(null, $order->id, 'No linked XS2 order.');
        }

        return $this->syncForXs2Order($xs2Order);
    }

    /**
     * Copy SB attendee rows onto the linked XS2 order without calling the XS2 API.
     *
     * @return array{copied: bool, skipped: bool, sb_order_id: int|null, xs2_order_id: int|null, error: string|null, reason: string|null}
     */
    public function copyAttendeesFromSbOrder(SbOrder $order): array
    {
        $order->loadMissing(['attendees', 'xs2Order']);

        if ($order->attendees->isEmpty()) {
            return [
                'copied' => false,
                'skipped' => false,
                'sb_order_id' => $order->id,
                'xs2_order_id' => $order->xs2Order?->id,
                'error' => 'Fetch attendee details from Seats Broker first.',
                'reason' => null,
            ];
        }

        $xs2Order = $order->xs2Order;
        if ($xs2Order === null) {
            return [
                'copied' => false,
                'skipped' => false,
                'sb_order_id' => $order->id,
                'xs2_order_id' => null,
                'error' => 'No linked XS2 order. Create the XS2 order before moving attendees.',
                'reason' => null,
            ];
        }

        $this->syncAttendees($xs2Order, $order);

        $updates = [];
        if (Schema::hasColumn('xs2_orders', 'attendees_copied_from_sb_at')) {
            $updates['attendees_copied_from_sb_at'] = now();
        }
        if ($updates !== []) {
            $xs2Order->forceFill($updates)->save();
        }

        return [
            'copied' => true,
            'skipped' => false,
            'sb_order_id' => $order->id,
            'xs2_order_id' => $xs2Order->id,
            'error' => null,
            'reason' => null,
        ];
    }

    /**
     * Manual admin push: send attendees already stored on the XS2 order to the XS2 guest-data API
     * and persist a request/response log row.
     *
     * @return array{synced: bool, skipped: bool, sb_order_id: int|null, xs2_order_id: int|null, error: string|null, reason: string|null, log_id: int|null}
     */
    public function pushGuestDataForXs2Order(Xs2Order $xs2Order): array
    {
        $xs2Order->loadMissing(['attendees', 'sbOrder.attendees']);

        if ($xs2Order->attendees->isEmpty()) {
            return [
                'synced' => false,
                'skipped' => false,
                'sb_order_id' => $xs2Order->sb_order_id,
                'xs2_order_id' => $xs2Order->id,
                'error' => 'Move attendee details onto this XS2 order first.',
                'reason' => null,
                'log_id' => null,
            ];
        }

        $bookingOrderId = $this->resolveBookingOrderId($xs2Order);
        if ($bookingOrderId === null) {
            $this->persistGuestDataLog($xs2Order, null, null, null, 'XS2 bookingorder_id is missing.');

            return $this->failResult($xs2Order, $xs2Order->sb_order_id, 'XS2 bookingorder_id is missing.') + ['log_id' => $xs2Order->guestDataLogs()->latest('id')->value('id')];
        }

        $sbOrder = $xs2Order->sbOrder;

        if (! $this->guestApiConfigured($xs2Order)) {
            $this->persistGuestDataLog($xs2Order, null, null, null, 'XS2 guest-data API credentials are not configured.');

            return $this->failResult($xs2Order, $xs2Order->sb_order_id, 'XS2 guest-data API credentials are not configured.') + ['log_id' => $xs2Order->guestDataLogs()->latest('id')->value('id')];
        }

        $requestPayload = null;

        try {
            $guestPayload = $this->fetchBookingOrderGuestDataWithRetry($xs2Order, $bookingOrderId);
            $ticketId = $this->resolveGuestDataTicketId($xs2Order, $sbOrder, $guestPayload);
            if ($ticketId === null) {
                $message = 'XS2 ticket_id could not be resolved.';
                $this->persistGuestDataLog($xs2Order, null, null, null, $message);

                return $this->failResult($xs2Order, $xs2Order->sb_order_id, $message) + ['log_id' => $xs2Order->guestDataLogs()->latest('id')->value('id')];
            }

            $requirements = $this->guestRequirements($guestPayload, $ticketId);
            if ($requirements === []) {
                $ticketPayload = $this->fetchTicketGuestRequirements($xs2Order, $ticketId);
                $ticketRequirements = $ticketPayload['guest_data_requirements'] ?? [];
                if (is_array($ticketRequirements)) {
                    $requirements = array_values(array_filter($ticketRequirements, is_string(...)));
                }
            }

            if ($requirements !== []) {
                $missingFieldsReason = $this->missingRequiredXs2GuestFieldReason($xs2Order->attendees, $requirements);
                if ($missingFieldsReason !== null) {
                    $this->persistGuestDataLog($xs2Order, null, null, null, $missingFieldsReason);

                    return $this->skipResult($xs2Order->id, $xs2Order->sb_order_id, $missingFieldsReason) + ['log_id' => $xs2Order->guestDataLogs()->latest('id')->value('id')];
                }
            }

            if ($xs2Order->quantity !== null && $xs2Order->attendees->count() !== (int) $xs2Order->quantity) {
                $message = 'Guest count on the XS2 order does not match booking quantity.';
                $this->persistGuestDataLog($xs2Order, null, null, null, $message);

                return $this->failResult($xs2Order, $xs2Order->sb_order_id, $message) + ['log_id' => $xs2Order->guestDataLogs()->latest('id')->value('id')];
            }

            $expectedCount = max(1, $xs2Order->attendees->count());
            $existingGuests = $this->extractExistingGuests($guestPayload, $ticketId, $expectedCount);
            $requestPayload = $this->payloadBuilder->build(
                $ticketId,
                $xs2Order->attendees,
                $existingGuests,
                $this->resolveGuestDataDefaultCity($xs2Order, $sbOrder),
            );

            $response = $this->updateBookingGuestData(
                $xs2Order,
                $bookingOrderId,
                $ticketId,
                $requestPayload['items'][0]['guests'] ?? [],
            );

            $fingerprint = $sbOrder !== null ? $this->attendeeFingerprint($sbOrder) : null;
            $xs2Order->fill([
                'guest_data_synced_at' => now(),
                'guest_data_sync_error' => null,
                'guest_data_source_fingerprint' => $fingerprint,
                'order_status_text' => $this->nullableString(
                    $response['guestdata_status']
                        ?? data_get($response, 'items.0.guestdata_status')
                        ?? $xs2Order->order_status_text,
                ),
            ])->save();

            $log = $this->persistGuestDataLog($xs2Order, $requestPayload, 200, $response, null);

            return [
                'synced' => true,
                'skipped' => false,
                'sb_order_id' => $xs2Order->sb_order_id,
                'xs2_order_id' => $xs2Order->id,
                'error' => null,
                'reason' => null,
                'log_id' => $log?->id,
            ];
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 2000);
            $xs2Order->fill([
                'guest_data_sync_error' => $message,
            ])->save();

            $status = $exception instanceof Xs2RequestException ? $exception->status : null;
            $responseBody = $exception instanceof Xs2RequestException ? $exception->responseBody : null;
            $log = $this->persistGuestDataLog($xs2Order, $requestPayload, $status, $responseBody, $message);

            return $this->failResult($xs2Order, $xs2Order->sb_order_id, $message) + ['log_id' => $log?->id];
        }
    }

    /**
     * @param  array<string, mixed>|null  $requestPayload
     * @param  array<string, mixed>|null  $responseBody
     */
    private function persistGuestDataLog(
        Xs2Order $xs2Order,
        ?array $requestPayload,
        ?int $responseStatus,
        ?array $responseBody,
        ?string $error,
    ): ?Xs2OrderGuestDataLog {
        if (! Schema::hasTable('xs2_order_guest_data_logs')) {
            return null;
        }

        return Xs2OrderGuestDataLog::query()->create([
            'xs2_order_id' => $xs2Order->id,
            'request_payload' => $requestPayload,
            'response_status' => $responseStatus,
            'response_body' => $responseBody,
            'error' => $error,
            'pushed_at' => now(),
        ]);
    }

    /**
     * @param  Collection<int, Xs2OrderAttendee>  $attendees
     * @param  list<string>  $requirements
     */
    private function missingRequiredXs2GuestFieldReason(Collection $attendees, array $requirements): ?string
    {
        if ($requirements === []) {
            return null;
        }

        $missingLabels = [];
        foreach ($attendees->values() as $index => $attendee) {
            foreach ($requirements as $requirement) {
                if (! $this->xs2AttendeeHasGuestField($attendee, $requirement)) {
                    $missingLabels[] = sprintf('guest %d %s', $index + 1, str_replace('_', ' ', $requirement));
                }
            }
        }

        if ($missingLabels === []) {
            return null;
        }

        return 'XS2 order attendees missing required guest fields: '
            .implode(', ', array_values(array_unique($missingLabels))).'.';
    }

    private function xs2AttendeeHasGuestField(Xs2OrderAttendee $attendee, string $requirement): bool
    {
        return $this->payloadBuilder->attendeeHasField($attendee, $requirement);
    }

    /**
     * @return array{
     *     synced: bool,
     *     skipped: bool,
     *     sb_order_id: int|null,
     *     xs2_order_id: int|null,
     *     error: string|null,
     *     reason: string|null
     * }
     */
    public function syncForXs2Order(Xs2Order $xs2Order): array
    {
        $xs2Order->loadMissing(['sbOrder.attendees']);

        if ($xs2Order->sb_order_id === null || $xs2Order->sbOrder === null) {
            return $this->skipResult($xs2Order->id, null, 'XS2 order is not linked to an SB order.');
        }

        $sbOrder = $xs2Order->sbOrder;
        $sbOrder->loadMissing('attendees');

        if ($this->isCancelled($sbOrder)) {
            return $this->skipResult($xs2Order->id, $sbOrder->id, 'SB order is cancelled.');
        }

        if ($sbOrder->attendees->isEmpty()) {
            return $this->skipResult($xs2Order->id, $sbOrder->id, 'SB order has no attendee_details.');
        }

        $bookingOrderId = $this->resolveBookingOrderId($xs2Order);
        if ($bookingOrderId === null) {
            return $this->failResult($xs2Order, $sbOrder->id, 'XS2 bookingorder_id is missing.');
        }

        $fingerprint = $this->attendeeFingerprint($sbOrder);
        if ($fingerprint !== null
            && $fingerprint === $xs2Order->guest_data_source_fingerprint
            && $xs2Order->guest_data_synced_at !== null
            && blank($xs2Order->guest_data_sync_error)) {
            return $this->skipResult($xs2Order->id, $sbOrder->id, 'Guest data already synced for current SB attendees.');
        }

        if (! $this->guestApiConfigured($xs2Order)) {
            return $this->failResult($xs2Order, $sbOrder->id, 'XS2 guest-data API credentials are not configured.');
        }

        try {
            $guestPayload = $this->fetchBookingOrderGuestDataWithRetry($xs2Order, $bookingOrderId);
            $ticketId = $this->resolveGuestDataTicketId($xs2Order, $sbOrder, $guestPayload);
            if ($ticketId === null) {
                return $this->failResult($xs2Order, $sbOrder->id, 'XS2 ticket_id could not be resolved.');
            }

            $requirements = $this->guestRequirements($guestPayload, $ticketId);
            if ($requirements === []) {
                $ticketPayload = $this->fetchTicketGuestRequirements($xs2Order, $ticketId);
                $ticketRequirements = $ticketPayload['guest_data_requirements'] ?? [];
                if (is_array($ticketRequirements)) {
                    $requirements = array_values(array_filter($ticketRequirements, is_string(...)));
                }
            }

            if ($requirements === []) {
                return $this->skipResult($xs2Order->id, $sbOrder->id, 'XS2 ticket does not require guest data.');
            }

            $missingFieldsReason = $this->missingRequiredGuestFieldReason($sbOrder->attendees, $requirements);
            if ($missingFieldsReason !== null) {
                return $this->skipResult($xs2Order->id, $sbOrder->id, $missingFieldsReason);
            }

            $expectedCount = max(1, (int) ($sbOrder->quantity ?? $xs2Order->quantity ?? 1));
            if ($sbOrder->attendees->count() !== $expectedCount) {
                return $this->failResult(
                    $xs2Order,
                    $sbOrder->id,
                    'Guest count from SB order does not match booking quantity.',
                );
            }

            $existingGuests = $this->extractExistingGuests($guestPayload, $ticketId, $expectedCount);
            $requestPayload = $this->payloadBuilder->build(
                $ticketId,
                $sbOrder->attendees,
                $existingGuests,
                $this->resolveGuestDataDefaultCity($xs2Order, $sbOrder),
            );

            $response = $this->updateBookingGuestData(
                $xs2Order,
                $bookingOrderId,
                $ticketId,
                $requestPayload['items'][0]['guests'] ?? [],
            );

            $this->syncAttendees($xs2Order, $sbOrder);

            $xs2Order->fill([
                'guest_data_synced_at' => now(),
                'guest_data_sync_error' => null,
                'guest_data_source_fingerprint' => $fingerprint,
                'order_status_text' => $this->nullableString(
                    $response['guestdata_status']
                        ?? data_get($response, 'items.0.guestdata_status')
                        ?? $xs2Order->order_status_text,
                ),
            ])->save();

            return [
                'synced' => true,
                'skipped' => false,
                'sb_order_id' => $sbOrder->id,
                'xs2_order_id' => $xs2Order->id,
                'error' => null,
                'reason' => null,
            ];
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 2000);
            $xs2Order->fill([
                'guest_data_sync_error' => $message,
            ])->save();

            return $this->failResult($xs2Order, $sbOrder->id, $message);
        }
    }

    public function queueIfEligible(SbOrder $order): bool
    {
        if (! (bool) config('xs2.sb_order_guest_data_sync.enabled', true)) {
            return false;
        }

        $order->loadMissing(['attendees', 'xs2Order']);
        if ($order->attendees->isEmpty() || $order->xs2Order === null) {
            return false;
        }

        $xs2Order = $order->xs2Order;
        if ($this->resolveBookingOrderId($xs2Order) === null) {
            return false;
        }

        if ($this->isCancelled($order)) {
            return false;
        }

        $fingerprint = $this->attendeeFingerprint($order);
        if ($fingerprint === null) {
            return false;
        }

        if ($fingerprint === $xs2Order->guest_data_source_fingerprint
            && $xs2Order->guest_data_synced_at !== null
            && blank($xs2Order->guest_data_sync_error)) {
            return false;
        }

        return true;
    }

    /** @param array{synced:int, skipped:int, failed:int, errors:list<mixed>} $summary */
    private function finalizeRun(array $summary, ?string $error = null): void
    {
        if (! Schema::hasTable('xs2_sync_states')) {
            return;
        }

        $state = Xs2SyncState::query()->firstOrCreate(['resource' => self::SYNC_RESOURCE]);
        $state->update([
            'status' => $error !== null || $summary['failed'] > 0 ? 'failed' : 'idle',
            'last_attempted_at' => now(),
            'last_successful_at' => $error === null && $summary['failed'] === 0 ? now() : $state->last_successful_at,
            'last_error' => $error ?? ($summary['errors'][0]['message'] ?? null),
        ]);
    }

    private function pendingOrdersQuery()
    {
        return Xs2Order::query()
            ->with(['sbOrder.attendees'])
            ->whereNotNull('sb_order_id')
            ->where(function ($query): void {
                $query->whereNotNull('xs2_bookingorder_id')
                    ->orWhereNotNull('external_order_id');
            })
            ->whereHas('sbOrder', function ($sbOrder): void {
                $sbOrder->activeSold()->has('attendees');
            })
            ->where(function ($query): void {
                $query->whereNull('guest_data_synced_at')
                    ->orWhereNotNull('guest_data_sync_error')
                    ->orWhereNull('guest_data_source_fingerprint')
                    ->orWhereHas('sbOrder', function ($sbOrder): void {
                        $sbOrder->whereColumn('sb_orders.updated_at', '>', 'xs2_orders.guest_data_synced_at');
                    });
            })
            ->orderBy('id');
    }

    private function guestApiConfigured(Xs2Order $xs2Order): bool
    {
        if ((bool) $xs2Order->is_sandbox) {
            return $this->sandbox->isConfigured();
        }

        return $this->client->isConfigured();
    }

    /** @return array<string, mixed> */
    private function fetchBookingOrderGuestData(Xs2Order $xs2Order, string $bookingOrderId): array
    {
        if ((bool) $xs2Order->is_sandbox) {
            return $this->sandbox->fetchBookingOrderGuestData($bookingOrderId);
        }

        return $this->client->getBookingOrderGuestData($bookingOrderId);
    }

    /** @return array<string, mixed> */
    private function fetchTicketGuestRequirements(Xs2Order $xs2Order, string $ticketId): array
    {
        if ((bool) $xs2Order->is_sandbox) {
            return $this->sandbox->fetchTicketGuestRequirements($ticketId);
        }

        return $this->client->getTicketGuestData($ticketId);
    }

    /**
     * @param  list<array<string, mixed>>  $guests
     * @return array<string, mixed>
     */
    private function updateBookingGuestData(
        Xs2Order $xs2Order,
        string $bookingOrderId,
        string $ticketId,
        array $guests,
    ): array {
        if ((bool) $xs2Order->is_sandbox) {
            return $this->sandbox->updateBookingGuestData($bookingOrderId, $ticketId, $guests);
        }

        return $this->client->updateBookingOrderGuestData($bookingOrderId, $ticketId, $guests);
    }

    private function resolveBookingOrderId(Xs2Order $xs2Order): ?string
    {
        $bookingId = $this->nullableString($xs2Order->xs2_booking_id);
        $stored = $this->nullableString($xs2Order->xs2_bookingorder_id)
            ?? $this->nullableString($xs2Order->external_order_id);

        if ($stored !== null && ($bookingId === null || $stored !== $bookingId)) {
            return $stored;
        }

        if ($bookingId === null) {
            return $stored;
        }

        if ((bool) $xs2Order->is_sandbox && $this->sandbox->isConfigured()) {
            return $this->resolveSandboxBookingOrderIdFromApi($bookingId, $xs2Order);
        }

        return $stored;
    }

    private function resolveSandboxBookingOrderIdFromApi(string $bookingId, ?Xs2Order $xs2Order = null): ?string
    {
        try {
            $response = $this->sandbox->fetchBookingOrdersByBookingId($bookingId);
        } catch (Xs2RequestException) {
            return null;
        }

        $orders = $response['bookingorders'] ?? [];
        if (! is_array($orders)) {
            return null;
        }

        foreach ($orders as $order) {
            if (! is_array($order)) {
                continue;
            }

            $responseBookingId = $this->nullableString($order['booking_id'] ?? null);
            if ($responseBookingId !== null && $responseBookingId !== $bookingId) {
                continue;
            }

            $bookingOrderId = $this->nullableString($order['bookingorder_id'] ?? null);
            if ($bookingOrderId !== null) {
                if ($xs2Order !== null) {
                    $this->persistBookingOrderId($xs2Order, $bookingOrderId);
                }

                return $bookingOrderId;
            }
        }

        return null;
    }

    private function persistBookingOrderId(Xs2Order $xs2Order, string $bookingOrderId): void
    {
        $updates = [];
        if ($this->nullableString($xs2Order->xs2_bookingorder_id) !== $bookingOrderId) {
            $updates['xs2_bookingorder_id'] = $bookingOrderId;
        }
        if ($this->nullableString($xs2Order->external_order_id) !== $bookingOrderId) {
            $updates['external_order_id'] = $bookingOrderId;
        }

        if ($updates !== []) {
            $xs2Order->fill($updates)->save();
        }
    }

    /** @return array<string, mixed> */
    private function fetchBookingOrderGuestDataWithRetry(Xs2Order $xs2Order, string $bookingOrderId): array
    {
        try {
            return $this->fetchBookingOrderGuestData($xs2Order, $bookingOrderId);
        } catch (Xs2RequestException $exception) {
            $bookingId = $this->nullableString($xs2Order->xs2_booking_id);
            if ($exception->status !== 404 || $bookingId === null || ! (bool) $xs2Order->is_sandbox) {
                throw $exception;
            }

            $resolved = $this->resolveSandboxBookingOrderIdFromApi($bookingId, $xs2Order);
            if ($resolved === null || $resolved === $bookingOrderId) {
                throw $exception;
            }

            return $this->fetchBookingOrderGuestData($xs2Order, $resolved);
        }
    }

    /**
     * Resolve the XS2 ticket_id for guest-data PUT requests.
     *
     * Prefer split-aware xs2_listing_id from the SB order listing, then the ticket_id
     * on the booking-order guest-data GET, then stored xs2_order.external_ticket_id.
     */
    private function resolveGuestDataTicketId(Xs2Order $xs2Order, ?SbOrder $sbOrder, array $guestPayload): ?string
    {
        $fromListing = $sbOrder !== null ? $this->resolveTicketIdFromSbListing($sbOrder) : null;
        if ($fromListing !== null) {
            return $fromListing;
        }

        $fromPayload = $this->ticketIdFromGuestPayload($guestPayload);
        if ($fromPayload !== null) {
            return $fromPayload;
        }

        return $this->nullableString($xs2Order->external_ticket_id);
    }

    private function resolveGuestDataDefaultCity(Xs2Order $xs2Order, ?SbOrder $sbOrder): string
    {
        $ticketIds = [];
        if ($sbOrder !== null) {
            $fromListing = $this->resolveTicketIdFromSbListing($sbOrder);
            if ($fromListing !== null) {
                $ticketIds[] = $fromListing;
            }
        }

        $externalTicketId = $this->nullableString($xs2Order->external_ticket_id);
        if ($externalTicketId !== null) {
            $ticketIds[] = $externalTicketId;
        }

        foreach (array_unique($ticketIds) as $ticketId) {
            $ticket = Xs2Ticket::query()
                ->where('external_ticket_id', $ticketId)
                ->with('xs2Event')
                ->first();

            $city = $this->nullableString($ticket?->xs2Event?->city);
            if ($city !== null) {
                return $city;
            }
        }

        return 'Barcelona';
    }

    private function resolveTicketIdFromSbListing(SbOrder $sbOrder): ?string
    {
        $resolutions = app(SbOrderXs2SandboxOrderService::class)
            ->resolveXs2ListingResolutionsForOrders([$sbOrder]);

        return $this->nullableString($resolutions[$sbOrder->id]['xs2_listing_id'] ?? null);
    }

    private function ticketIdFromGuestPayload(array $payload): ?string
    {
        $items = $payload['items'] ?? [];
        if (! is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $ticketId = $this->nullableString($item['ticket_id'] ?? null);
            if ($ticketId !== null) {
                return $ticketId;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function marketplaceListingIds(SbOrder $order): array
    {
        $ids = [];
        if ($order->ticket_id !== null) {
            $ids[] = (string) $order->ticket_id;
        }
        if (is_string($order->listing_id) && $order->listing_id !== '') {
            $ids[] = $order->listing_id;
        }

        return array_values(array_unique($ids));
    }

    private function syncAttendees(Xs2Order $xs2Order, SbOrder $order): void
    {
        Xs2OrderAttendee::query()->where('xs2_order_id', $xs2Order->id)->delete();

        foreach ($order->attendees as $index => $attendee) {
            Xs2OrderAttendee::query()->create([
                'xs2_order_id' => $xs2Order->id,
                'position' => $index,
                'first_name' => $attendee->first_name,
                'last_name' => $attendee->last_name,
                'dob' => $attendee->dob,
                'nationality' => $attendee->nationality,
                'province' => $attendee->province,
                'email' => $attendee->email,
                'phone' => $attendee->phone,
                'passport' => $attendee->passport,
                'gender' => $attendee->gender,
                'raw_payload' => $attendee->raw_payload,
            ]);
        }
    }

    private function attendeeFingerprint(SbOrder $order): ?string
    {
        $order->loadMissing('attendees');
        if ($order->attendees->isEmpty()) {
            return null;
        }

        $parts = $order->attendees
            ->sortBy('position')
            ->map(fn (SbOrderAttendee $attendee): string => implode('|', [
                (string) ($attendee->first_name ?? ''),
                (string) ($attendee->last_name ?? ''),
                (string) ($attendee->dob ?? ''),
                (string) ($attendee->nationality ?? ''),
                (string) ($attendee->province ?? ''),
                (string) ($attendee->email ?? ''),
                (string) ($attendee->phone ?? ''),
                (string) ($attendee->passport ?? ''),
                (string) ($attendee->gender ?? ''),
            ]))
            ->values()
            ->all();

        return hash('sha256', implode("\n", $parts));
    }

    /** @return list<string> */
    private function guestRequirements(array $payload, string $ticketId): array
    {
        $requirements = [];
        $items = $payload['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemTicketId = $this->nullableString($item['ticket_id'] ?? null);
            if ($itemTicketId !== null && $itemTicketId !== $ticketId) {
                continue;
            }

            foreach ($item['guests'] ?? [] as $guest) {
                if (! is_array($guest)) {
                    continue;
                }
                foreach ($guest as $field => $value) {
                    if (! is_string($field) || in_array($field, ['lead_guest', 'guest_id', 'conditions'], true)) {
                        continue;
                    }
                    if (is_array($value) && filled($value['condition'] ?? null)) {
                        $requirements[] = $field;
                    }
                }
            }
        }

        return array_values(array_unique($requirements));
    }

    /**
     * @param  Collection<int, SbOrderAttendee>  $attendees
     * @param  list<string>  $requirements
     */
    private function missingRequiredGuestFieldReason(Collection $attendees, array $requirements): ?string
    {
        if ($requirements === []) {
            return null;
        }

        $missingLabels = [];
        foreach ($attendees->values() as $index => $attendee) {
            foreach ($requirements as $requirement) {
                if (! $this->attendeeHasGuestField($attendee, $requirement)) {
                    $missingLabels[] = sprintf('guest %d %s', $index + 1, str_replace('_', ' ', $requirement));
                }
            }
        }

        if ($missingLabels === []) {
            return null;
        }

        return 'SB order attendee_details missing required XS2 guest fields: '
            .implode(', ', array_values(array_unique($missingLabels))).'.';
    }

    private function attendeeHasGuestField(SbOrderAttendee $attendee, string $requirement): bool
    {
        return $this->payloadBuilder->attendeeHasField($attendee, $requirement);
    }

    /** @return list<array<string, mixed>> */
    private function extractExistingGuests(array $payload, string $ticketId, int $quantity): array
    {
        $items = $payload['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemTicketId = $this->nullableString($item['ticket_id'] ?? null);
            if ($itemTicketId !== null && $itemTicketId !== $ticketId) {
                continue;
            }

            $guests = $item['guests'] ?? [];
            if (! is_array($guests)) {
                return [];
            }

            return array_values(array_filter($guests, is_array(...)));
        }

        return [];
    }

    private function isCancelled(SbOrder $order): bool
    {
        if ((int) $order->booking_status === SbOrder::STATUS_CANCELLED) {
            return true;
        }

        $text = strtolower((string) ($order->booking_status_text ?? ''));

        return str_contains($text, 'cancel');
    }

    /**
     * @return array{
     *     synced: bool,
     *     skipped: bool,
     *     sb_order_id: int|null,
     *     xs2_order_id: int|null,
     *     error: string|null,
     *     reason: string|null
     * }
     */
    private function skipResult(?int $xs2OrderId, ?int $sbOrderId, string $reason): array
    {
        return [
            'synced' => false,
            'skipped' => true,
            'sb_order_id' => $sbOrderId,
            'xs2_order_id' => $xs2OrderId,
            'error' => null,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{
     *     synced: bool,
     *     skipped: bool,
     *     sb_order_id: int|null,
     *     xs2_order_id: int|null,
     *     error: string|null,
     *     reason: string|null
     * }
     */
    private function failResult(Xs2Order $xs2Order, ?int $sbOrderId, string $error): array
    {
        return [
            'synced' => false,
            'skipped' => false,
            'sb_order_id' => $sbOrderId,
            'xs2_order_id' => $xs2Order->id,
            'error' => $error,
            'reason' => null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
