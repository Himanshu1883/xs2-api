<?php

namespace App\Services\Xs2;

use App\Exceptions\Integrations\Xs2RequestException;
use App\Models\ExternalListingMapping;
use App\Models\ListingSplit;
use App\Models\SbOrder;
use App\Models\SbOrderAttendee;
use App\Models\Xs2Order;
use App\Models\Xs2OrderAttendee;
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

        $ticketId = $this->resolveTicketId($xs2Order, $sbOrder);
        if ($ticketId === null) {
            return $this->failResult($xs2Order, $sbOrder->id, 'XS2 ticket_id could not be resolved.');
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

            $guests = $this->guestsFromSbAttendees($sbOrder->attendees, $requirements);
            $expectedCount = max(1, (int) ($sbOrder->quantity ?? $xs2Order->quantity ?? 1));
            if (count($guests) !== $expectedCount) {
                return $this->failResult(
                    $xs2Order,
                    $sbOrder->id,
                    'Guest count from SB order does not match booking quantity.',
                );
            }

            $existingGuests = $this->extractExistingGuests($guestPayload, $ticketId, $expectedCount);
            $normalizedGuests = $this->mergeGuestIds($guests, $existingGuests);

            $response = $this->updateBookingGuestData(
                $xs2Order,
                $bookingOrderId,
                $ticketId,
                $normalizedGuests,
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

    private function resolveTicketId(Xs2Order $xs2Order, SbOrder $sbOrder): ?string
    {
        $fromOrder = $this->nullableString($xs2Order->external_ticket_id);
        if ($fromOrder !== null) {
            return $fromOrder;
        }

        foreach ($this->marketplaceListingIds($sbOrder) as $listingId) {
            $mapping = ExternalListingMapping::query()
                ->where('seller_listing_id', $listingId)
                ->first();
            if ($mapping !== null) {
                $ticket = Xs2Ticket::query()->find($mapping->xs2_ticket_id);
                $ticketId = $this->nullableString($ticket?->external_ticket_id);
                if ($ticketId !== null) {
                    return $ticketId;
                }
            }

            if (Schema::hasTable('listing_splits')) {
                $split = ListingSplit::query()
                    ->where('seatsbroker_listing_id', $listingId)
                    ->first();
                if ($split !== null) {
                    $ticket = Xs2Ticket::query()->find($split->master_listing_id);
                    $ticketId = $this->nullableString($ticket?->external_ticket_id);
                    if ($ticketId !== null) {
                        return $ticketId;
                    }
                }
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
     * @return list<array<string, mixed>>
     */
    private function guestsFromSbAttendees(Collection $attendees, array $requirements): array
    {
        $guests = [];
        foreach ($attendees->values() as $index => $attendee) {
            $entry = [
                'first_name' => $attendee->first_name,
                'last_name' => $attendee->last_name,
                'contact_email' => $attendee->email,
                'contact_phone' => $attendee->phone,
                'date_of_birth' => $attendee->dob,
                'passport_number' => $attendee->passport,
                'country_of_residence' => $attendee->nationality
                    ? strtoupper((string) $attendee->nationality)
                    : null,
                'province' => $attendee->province,
                'gender' => $attendee->gender,
                'lead_guest' => $index === 0,
            ];

            $normalized = [];
            foreach ($entry as $key => $value) {
                $string = $this->nullableString($value);
                if ($string !== null) {
                    $normalized[$key] = $string;
                }
            }
            if (isset($normalized['gender'])) {
                $normalized['gender'] = match (strtolower($normalized['gender'])) {
                    'other' => 'unknown',
                    default => strtolower($normalized['gender']),
                };
            }

            $guests[] = $normalized;
        }

        return $guests;
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
        $value = match ($requirement) {
            'first_name' => $attendee->first_name,
            'last_name' => $attendee->last_name,
            'date_of_birth', 'dob' => $attendee->dob,
            'passport_number', 'passport' => $attendee->passport,
            'country_of_residence', 'nationality' => $attendee->nationality,
            'contact_email', 'email' => $attendee->email,
            'contact_phone', 'phone', 'mobile' => $attendee->phone,
            'province', 'state' => $attendee->province,
            'gender' => $attendee->gender,
            default => data_get($attendee->raw_payload, $requirement),
        };

        return filled($value);
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

    /**
     * @param  list<array<string, mixed>>  $normalizedGuests
     * @param  list<array<string, mixed>>  $existingGuests
     * @return list<array<string, mixed>>
     */
    private function mergeGuestIds(array $normalizedGuests, array $existingGuests): array
    {
        foreach ($normalizedGuests as $index => &$guest) {
            if (isset($guest['guest_id'])) {
                continue;
            }

            $existingGuestId = $this->nullableString($existingGuests[$index]['guest_id'] ?? null);
            if ($existingGuestId !== null) {
                $guest['guest_id'] = $existingGuestId;
            }
        }
        unset($guest);

        return $normalizedGuests;
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
