<?php

namespace App\Services\Xs2;

use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Services\Admin\ApiEnvironmentService;
use App\Models\ListingSplit;
use App\Models\SbOrder;
use App\Models\SbOrderAttendee;
use App\Models\Xs2Event;
use App\Models\Xs2Order;
use App\Models\Xs2OrderAttendee;
use App\Models\Xs2Ticket;
use App\Support\Xs2BookingOrderIdentity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When an SB marketplace booking is synced locally, create a matching reservation
 * and booking on the XS2 API (sandbox or production per XS2_ORDERS_ACTIVE_ENVIRONMENT)
 * and store it in xs2_orders.
 */
class SbOrderXs2SandboxOrderService
{
    public function __construct(
        private readonly Xs2SandboxService $sandbox,
        private readonly Xs2Client $client,
        private readonly ApiEnvironmentService $apiEnvironment,
        private readonly SbOrderXs2GuestDataSyncService $guestDataSync,
        private readonly SbOrderXs2SyncLogService $syncLogs,
    ) {}

    /**
     * @return array{
     *     order: Xs2Order|null,
     *     created: bool,
     *     updated: bool,
     *     skipped: bool,
     *     reason: string|null
     * }
     */
    public function createFromSbOrder(SbOrder $order): array
    {
        if (! $this->isEnabled()) {
            return $this->skip(null, $this->autoOrderDisabledReason(), $order);
        }

        if (! $this->orderApiConfigured()) {
            return $this->skip(null, $this->orderApiNotConfiguredReason(), $order);
        }

        if ($this->isCancelled($order)) {
            return $this->skip(null, 'SB order is cancelled.', $order);
        }

        $existing = $this->existingOrder($order);
        if ($existing !== null && filled($existing->xs2_booking_id)) {
            $this->syncLogs->recordSkipped(
                $order->id,
                $this->existingOrderSkipReason(),
                $existing->id,
            );

            return [
                'order' => $existing,
                'created' => false,
                'updated' => false,
                'skipped' => true,
                'reason' => $this->existingOrderSkipReason(),
            ];
        }

        $linkedExisting = $this->linkExistingXs2Order($order, $existing);
        if ($linkedExisting !== null) {
            return $linkedExisting;
        }

        $ticket = $this->resolveMappedTicket($order);
        if ($ticket === null) {
            return $this->skip(
                $existing,
                $this->resolveTicketMappingSkipReason($order)
                    ?? $this->noTicketMappingSkipReason(),
                $order,
            );
        }

        $quantity = max(1, (int) ($order->quantity ?? 1));
        $netRate = $this->resolveReservationNetRate($order, $ticket);
        if ($netRate === null || $netRate <= 0) {
            return $this->skip($existing, 'Mapped XS2 ticket is missing net_rate.', $order);
        }

        $currency = (string) ($ticket->currency_code ?? $order->currency_type ?? 'EUR');
        $salesPrice = $this->resolveReservationSalesPrice($ticket, $netRate);
        $bookingEmail = $this->resolveBookingEmail($order);

        $reservationRequest = [
            'items' => [[
                'ticket_id' => $ticket->external_ticket_id,
                'quantity' => $quantity,
                'net_rate' => $netRate,
                'currency_code' => $currency,
                'sales_price' => $salesPrice > 0 ? $salesPrice : $netRate,
            ]],
            'booking_email' => $bookingEmail,
            'notify_me' => false,
            'notes' => 'SeatsBroker SB order '.$order->booking_no,
            'external_reference_id' => $order->booking_no,
            'target_currency' => $currency,
        ];

        try {
            return DB::transaction(function () use ($order, $ticket, $existing, $reservationRequest, $quantity, $bookingEmail): array {
                $reservationResult = $this->createReservationDetailed($reservationRequest);
                $this->syncLogs->recordReservationExchange($order->id, $reservationRequest, $reservationResult);
                if (! $reservationResult['success']) {
                    throw new \RuntimeException((string) ($reservationResult['message'] ?? 'XS2 reservation failed.'));
                }

                $reservationResponse = $reservationResult['data'];
                $reservationId = $this->nullableString($reservationResponse['reservation_id'] ?? null);
                if ($reservationId === null) {
                    throw new \RuntimeException('XS2 reservation response did not include reservation_id.');
                }

                $bookingRequest = [
                    'reservation_id' => $reservationId,
                    'booking_email' => $bookingEmail,
                    'booking_reference' => $order->booking_no,
                    'invoice_reference' => $order->booking_no,
                    'payment_method' => 'invoice',
                ];
                if ($this->isSandboxEnvironment()) {
                    $bookingRequest['is_test_booking'] = true;
                }

                $bookingResult = $this->createBookingDetailed($bookingRequest);
                $this->syncLogs->recordBookingExchange($order->id, $bookingRequest, $bookingResult);
                if (! $bookingResult['success']) {
                    throw new \RuntimeException((string) ($bookingResult['message'] ?? 'XS2 booking failed.'));
                }

                $bookingResponse = $bookingResult['data'];
                $bookingId = $this->nullableString($bookingResponse['booking_id'] ?? null);
                if ($bookingId === null) {
                    throw new \RuntimeException('XS2 booking response did not include booking_id.');
                }

                $bookingOrderId = $this->resolveBookingOrderId($bookingId, $bookingResponse);
                if ($bookingOrderId === null) {
                    throw new \RuntimeException('XS2 booking was created but bookingorder_id could not be resolved.');
                }

                $bookingOrderResponse = $this->fetchBookingOrder($bookingOrderId);
                $attributes = $this->orderAttributes(
                    $order,
                    $ticket,
                    $bookingOrderResponse,
                    $bookingResponse,
                    $reservationId,
                    $bookingId,
                    $bookingOrderId,
                    $quantity,
                );

                if ($existing === null) {
                    $xs2Order = Xs2Order::query()->create($attributes);
                    $created = true;
                    $updated = false;
                } else {
                    $existing->fill($attributes)->save();
                    $xs2Order = $existing;
                    $created = false;
                    $updated = true;
                }

                $this->syncAttendees($xs2Order, $order);

                // Guest-data push is best-effort; failures are tracked on guest_data_sync_error.
                $this->trySubmitGuestData($xs2Order, $order);

                $this->syncLogs->recordSuccess($order->id, $xs2Order->id);

                return [
                    'order' => $xs2Order->fresh(['attendees']),
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => false,
                    'reason' => null,
                ];
            });
        } catch (\Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 2000);

            if ($existing === null) {
                $existing = Xs2Order::query()->create([
                    'external_order_id' => $this->pendingExternalOrderId($order),
                    'is_sandbox' => $this->isSandboxEnvironment(),
                    'sb_order_id' => $order->id,
                    'event_name' => $order->match_name,
                    'venue_name' => $order->stadium_name,
                    'event_date' => $order->match_date,
                    'event_time' => $order->match_time,
                    'external_ticket_id' => $ticket->external_ticket_id,
                    'quantity' => $quantity,
                    'order_status' => 'failed',
                    'order_status_text' => 'XS2 sync failed',
                    'sandbox_sync_error' => $message,
                    'synced_at' => now(),
                ]);
            } else {
                $existing->fill([
                    'order_status' => 'failed',
                    'order_status_text' => 'XS2 sync failed',
                    'sandbox_sync_error' => $message,
                    'synced_at' => now(),
                ])->save();
            }

            $this->syncLogs->recordFailure($order->id, $message, $existing->id);

            return [
                'order' => $existing->fresh(),
                'created' => false,
                'updated' => true,
                'skipped' => false,
                'reason' => $message,
            ];
        }
    }

    /**
     * @param  iterable<SbOrder>  $orders
     */
    public function attachXs2ListingResolutions(iterable $orders): void
    {
        $resolutions = $this->resolveXs2ListingResolutionsForOrders($orders);

        foreach ($orders as $order) {
            $order->setAttribute(
                'xs2_listing_resolution',
                $resolutions[$order->id] ?? ['xs2_listing_id' => null, 'external_ticket_id' => null],
            );
        }
    }

    /**
     * @param  iterable<SbOrder>  $orders
     * @return array<int, array{xs2_listing_id: string|null, external_ticket_id: string|null}>
     */
    public function resolveXs2ListingResolutionsForOrders(iterable $orders): array
    {
        $ordersList = collect($orders);
        if ($ordersList->isEmpty()) {
            return [];
        }

        $listingIds = [];
        foreach ($ordersList as $order) {
            foreach ($this->marketplaceListingIds($order) as $listingId) {
                $listingIds[] = $listingId;
            }
        }
        $listingIds = array_values(array_unique($listingIds));

        $mappingsByListingId = ExternalListingMapping::query()
            ->whereIn('seller_listing_id', $listingIds)
            ->get()
            ->keyBy('seller_listing_id');

        $splitsByListingId = collect();
        if (Schema::hasTable('listing_splits')) {
            $splitsByListingId = ListingSplit::query()
                ->whereIn('seatsbroker_listing_id', $listingIds)
                ->with('masterListing')
                ->get()
                ->keyBy('seatsbroker_listing_id');
        }

        $ticketIds = $mappingsByListingId->pluck('xs2_ticket_id')
            ->merge($splitsByListingId->pluck('master_listing_id'))
            ->filter()
            ->unique()
            ->values();

        $ticketsById = $ticketIds->isEmpty()
            ? collect()
            : Xs2Ticket::query()->whereIn('id', $ticketIds)->get()->keyBy('id');

        $resolutions = [];
        foreach ($ordersList as $order) {
            $resolutions[$order->id] = $this->resolveXs2ListingResolutionFromLookups(
                $order,
                $mappingsByListingId,
                $splitsByListingId,
                $ticketsById,
            );
        }

        return $resolutions;
    }

    public function resolveMappedTicket(SbOrder $order): ?Xs2Ticket
    {
        $ticket = $this->resolveMappedTicketFromListing($order);
        if ($ticket !== null) {
            return $ticket;
        }

        return $this->resolveMappedTicketFromEvent($order);
    }

    /**
     * Reservation net_rate in XS2 minor units. Prefer synced ticket pricing, then SB order amount.
     */
    public function resolveReservationNetRate(SbOrder $order, Xs2Ticket $ticket): ?int
    {
        foreach ($this->reservationRateCandidates($order, $ticket) as $rate) {
            if ($rate > 0) {
                return $rate;
            }
        }

        return null;
    }

    public function resolveReservationSalesPrice(Xs2Ticket $ticket, int $netRate): int
    {
        $faceValue = (int) ($ticket->face_value ?? 0);
        if ($faceValue > 0) {
            return $faceValue;
        }

        $fromPayload = $this->positiveIntFromPayload($ticket->raw_payload, 'face_value');
        if ($fromPayload > 0) {
            return $fromPayload;
        }

        return $netRate;
    }

    /** @return list<int> */
    private function reservationRateCandidates(SbOrder $order, Xs2Ticket $ticket): array
    {
        $payload = is_array($ticket->raw_payload) ? $ticket->raw_payload : [];

        return [
            (int) ($ticket->net_rate ?? 0),
            (int) ($ticket->face_value ?? 0),
            (int) ($ticket->package_price ?? 0),
            $this->positiveIntFromPayload($payload, 'net_rate'),
            $this->positiveIntFromPayload($payload, 'face_value'),
            $this->positiveIntFromPayload($payload, 'gross_rate'),
            $this->minorRateFromSbOrderTicketAmount($order),
        ];
    }

    private function minorRateFromSbOrderTicketAmount(SbOrder $order): int
    {
        if ($order->ticket_amount === null) {
            return 0;
        }

        $quantity = max(1, (int) ($order->quantity ?? 1));
        $divisor = max(1, (int) config('services.xs2.minor_unit_divisor', 100));
        $totalMinor = (int) round((float) $order->ticket_amount * $divisor);

        return (int) round($totalMinor / $quantity);
    }

    /** @param  array<string, mixed>|null  $payload */
    private function positiveIntFromPayload(?array $payload, string $key): int
    {
        if (! is_array($payload) || ! array_key_exists($key, $payload)) {
            return 0;
        }

        $value = $payload[$key];
        if (! is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

    private function resolveMappedTicketFromListing(SbOrder $order): ?Xs2Ticket
    {
        foreach ($this->marketplaceListingIds($order) as $listingId) {
            $mapping = ExternalListingMapping::query()
                ->where('seller_listing_id', $listingId)
                ->first();
            if ($mapping !== null) {
                $ticket = Xs2Ticket::query()->find($mapping->xs2_ticket_id);
                if ($this->isEligibleTicket($ticket)) {
                    return $ticket;
                }
            }

            if (Schema::hasTable('listing_splits')) {
                $split = ListingSplit::query()
                    ->where('seatsbroker_listing_id', $listingId)
                    ->first();
                if ($split !== null) {
                    $ticket = Xs2Ticket::query()->find($split->master_listing_id);
                    if ($this->isEligibleTicket($ticket)) {
                        return $ticket;
                    }
                }
            }
        }

        return null;
    }

    private function resolveMappedTicketFromEvent(SbOrder $order): ?Xs2Ticket
    {
        $event = $this->resolveXs2EventForSbOrder($order);
        if ($event === null) {
            return null;
        }

        $tickets = Xs2Ticket::query()
            ->where('xs2_event_id', $event->id)
            ->get();

        return $this->selectBestTicketForSbOrder($order, $tickets);
    }

    /** @deprecated Use resolveMappedTicket() */
    public function resolveSandboxTicket(SbOrder $order): ?Xs2Ticket
    {
        return $this->resolveMappedTicket($order);
    }

    public function queueIfEligible(SbOrder $order): bool
    {
        return $this->resolveQueueSkipReason($order) === null;
    }

    public function resolveQueueSkipReason(SbOrder $order): ?string
    {
        if (! $this->isEnabled()) {
            return $this->autoOrderDisabledReason();
        }

        if (! $this->orderApiConfigured()) {
            return $this->orderApiNotConfiguredReason();
        }

        if ($this->isCancelled($order)) {
            return 'SB order is cancelled.';
        }

        if ($this->existingOrder($order)?->xs2_booking_id) {
            return $this->existingOrderSkipReason();
        }

        if ($this->findUnlinkedXs2OrderMatchingSbBooking($order) !== null) {
            return null;
        }

        if ($this->resolveMappedTicket($order) === null) {
            return $this->resolveTicketMappingSkipReason($order)
                ?? $this->noTicketMappingSkipReason();
        }

        return null;
    }

    /**
     * Synchronous validation for manual Create XS2 order (mapping + net_rate).
     * XS2 reservation/booking API calls run inline via dispatchSync on the manual admin endpoint.
     */
    public function resolveManualCreateSkipReason(SbOrder $order): ?string
    {
        $reason = $this->resolveQueueSkipReason($order);
        if ($reason !== null) {
            return $reason;
        }

        if ($this->findUnlinkedXs2OrderMatchingSbBooking($order) !== null) {
            return null;
        }

        $ticket = $this->resolveMappedTicket($order);
        if ($ticket === null) {
            return $this->noTicketMappingSkipReason();
        }

        $netRate = $this->resolveReservationNetRate($order, $ticket);
        if ($netRate === null || $netRate <= 0) {
            return 'Mapped XS2 ticket is missing net_rate.';
        }

        return null;
    }

    public function resolveTicketMappingSkipReason(SbOrder $order): ?string
    {
        foreach ($this->marketplaceListingIds($order) as $listingId) {
            $mapping = ExternalListingMapping::query()
                ->where('seller_listing_id', $listingId)
                ->first();
            if ($mapping !== null) {
                $ticket = Xs2Ticket::query()->find($mapping->xs2_ticket_id);
                if ($ticket === null) {
                    return "external_listing_mappings for seller_listing_id {$listingId} references missing xs2_ticket_id {$mapping->xs2_ticket_id}.";
                }

                $reason = $this->ticketIneligibleReason($ticket, 'external_listing_mappings', $listingId);
                if ($reason !== null) {
                    return $reason;
                }
            }

            if (Schema::hasTable('listing_splits')) {
                $split = ListingSplit::query()
                    ->where('seatsbroker_listing_id', $listingId)
                    ->first();
                if ($split !== null) {
                    $ticket = Xs2Ticket::query()->find($split->master_listing_id);
                    if ($ticket === null) {
                        return "listing_splits for seatsbroker_listing_id {$listingId} references missing master_listing_id {$split->master_listing_id}.";
                    }

                    $reason = $this->ticketIneligibleReason($ticket, 'listing_splits', $listingId);
                    if ($reason !== null) {
                        return $reason;
                    }
                }
            }
        }

        $eventReason = $this->resolveEventTicketMappingSkipReason($order);
        if ($eventReason !== null) {
            return $eventReason;
        }

        return null;
    }

    public function recordQueueDecision(SbOrder $order): void
    {
        $reason = $this->resolveQueueSkipReason($order);
        if ($reason !== null) {
            $this->syncLogs->recordNotQueued($order->id, $reason);

            return;
        }

        $this->syncLogs->recordQueued($order->id);
    }

    private function isEnabled(): bool
    {
        return (bool) config('xs2.sandbox.auto_create_orders_from_sb', true);
    }

    private function isCancelled(SbOrder $order): bool
    {
        if ((int) $order->booking_status === SbOrder::STATUS_CANCELLED) {
            return true;
        }

        $text = strtolower((string) ($order->booking_status_text ?? ''));

        return str_contains($text, 'cancel');
    }

    private function existingOrder(SbOrder $order): ?Xs2Order
    {
        if (! Schema::hasColumn('xs2_orders', 'sb_order_id')) {
            return null;
        }

        return Xs2Order::query()->where('sb_order_id', $order->id)->first();
    }

    /**
     * @param  Collection<string, ExternalListingMapping>  $mappingsByListingId
     * @param  Collection<string, ListingSplit>  $splitsByListingId
     * @param  Collection<int, Xs2Ticket>  $ticketsById
     * @return array{xs2_listing_id: string|null, external_ticket_id: string|null}
     */
    private function resolveXs2ListingResolutionFromLookups(
        SbOrder $order,
        Collection $mappingsByListingId,
        Collection $splitsByListingId,
        Collection $ticketsById,
    ): array {
        foreach ($this->marketplaceListingIds($order) as $listingId) {
            $mapping = $mappingsByListingId->get($listingId);
            if ($mapping !== null) {
                $ticket = $ticketsById->get($mapping->xs2_ticket_id);
                if ($ticket !== null && filled($ticket->external_ticket_id)) {
                    return [
                        'xs2_listing_id' => (string) $ticket->external_ticket_id,
                        'external_ticket_id' => (string) $ticket->external_ticket_id,
                    ];
                }
            }

            $split = $splitsByListingId->get($listingId);
            if ($split !== null) {
                $master = $split->relationLoaded('masterListing')
                    ? $split->masterListing
                    : $ticketsById->get($split->master_listing_id);
                if ($master !== null) {
                    $split->setRelation('masterListing', $master);
                }

                $xs2ListingId = $split->xs2ListingId();
                $externalTicketId = $master?->external_ticket_id;

                if ($xs2ListingId !== null || filled($externalTicketId)) {
                    return [
                        'xs2_listing_id' => $xs2ListingId ?? (string) $externalTicketId,
                        'external_ticket_id' => filled($externalTicketId) ? (string) $externalTicketId : null,
                    ];
                }
            }
        }

        return ['xs2_listing_id' => null, 'external_ticket_id' => null];
    }

    /** @return list<string> */
    public function marketplaceListingIds(SbOrder $order): array
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

    private function isEligibleTicket(?Xs2Ticket $ticket): bool
    {
        if ($ticket === null) {
            return false;
        }

        if ($this->isSandboxEnvironment()) {
            // Sandbox Create Order API accepts mapped tickets even when local is_sandbox=0
            // (historical inventory synced before sandbox flag separation).
            return filled($ticket->external_ticket_id);
        }

        if (Schema::hasColumn('xs2_tickets', 'is_sandbox')) {
            return ! (bool) $ticket->is_sandbox && filled($ticket->external_ticket_id);
        }

        return filled($ticket->external_ticket_id);
    }

    private function isSandboxTicket(?Xs2Ticket $ticket): bool
    {
        return $this->isEligibleTicket($ticket);
    }

    private function ticketIneligibleReason(Xs2Ticket $ticket, string $source, string $listingId): ?string
    {
        if ($this->isEligibleTicket($ticket)) {
            return null;
        }

        if (! filled($ticket->external_ticket_id)) {
            return "Mapped XS2 ticket #{$ticket->id} from {$source} (seller_listing_id {$listingId}) is missing external_ticket_id.";
        }

        if (
            Schema::hasColumn('xs2_tickets', 'is_sandbox')
            && (bool) $ticket->is_sandbox
            && ! $this->isSandboxEnvironment()
        ) {
            return "Mapped XS2 ticket #{$ticket->id} from {$source} (seller_listing_id {$listingId}) is marked is_sandbox and cannot be used for production order creation.";
        }

        if (
            Schema::hasColumn('xs2_tickets', 'is_sandbox')
            && ! (bool) $ticket->is_sandbox
            && $this->isSandboxEnvironment()
        ) {
            return "Mapped XS2 ticket #{$ticket->id} from {$source} (seller_listing_id {$listingId}) is not marked is_sandbox.";
        }

        return "Mapped XS2 ticket #{$ticket->id} from {$source} (seller_listing_id {$listingId}) is not eligible for XS2 order creation.";
    }

    /**
     * Link an XS2 order that was synced from the XS2 API but not yet tied to this SB booking.
     *
     * @return array{
     *     order: Xs2Order,
     *     created: bool,
     *     updated: bool,
     *     skipped: bool,
     *     reason: string|null
     * }|null
     */
    private function linkExistingXs2Order(SbOrder $order, ?Xs2Order $existing): ?array
    {
        $unlinked = $this->findUnlinkedXs2OrderMatchingSbBooking($order);
        if ($unlinked === null) {
            return null;
        }

        DB::transaction(function () use ($order, $existing, $unlinked): void {
            if (
                $existing !== null
                && $existing->id !== $unlinked->id
                && (
                    ! filled($existing->xs2_booking_id)
                    || Xs2BookingOrderIdentity::isPendingExternalOrderId($existing->external_order_id)
                )
            ) {
                $existing->delete();
            }

            $unlinked->update(['sb_order_id' => $order->id]);

            $this->syncAttendees($unlinked, $order);
        });

        $this->syncLogs->recordSuccess($order->id, $unlinked->id);

        return [
            'order' => $unlinked->fresh(['attendees']),
            'created' => false,
            'updated' => true,
            'skipped' => false,
            'reason' => null,
        ];
    }

    private function findUnlinkedXs2OrderMatchingSbBooking(SbOrder $order): ?Xs2Order
    {
        $bookingNo = $this->normalizedBookingReference($order->booking_no);
        if ($bookingNo === null) {
            return null;
        }

        $candidates = Xs2Order::query()
            ->whereNull('sb_order_id')
            ->where('is_sandbox', $this->isSandboxEnvironment())
            ->whereNotNull('xs2_booking_id')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $matches = $candidates
            ->filter(fn (Xs2Order $candidate): bool => $this->xs2OrderReferencesSbBooking($candidate, $bookingNo))
            ->sortByDesc(fn (Xs2Order $candidate): int => $this->linkExistingOrderPriority($candidate))
            ->values();

        return $matches->first();
    }

    private function linkExistingOrderPriority(Xs2Order $order): int
    {
        $priority = 0;

        if (Xs2BookingOrderIdentity::orderHasResolvableBookingOrderId($order)) {
            $priority += 1_000;
        }

        if (mb_strtolower((string) ($order->order_status ?? '')) === 'completed') {
            $priority += 100;
        }

        if (filled($order->xs2_booking_id)) {
            $priority += 10;
        }

        return ($priority * 1_000_000) + (int) $order->id;
    }

    private function xs2OrderReferencesSbBooking(Xs2Order $xs2Order, string $normalizedBookingNo): bool
    {
        foreach (['booking_reference', 'invoice_reference', 'payment_reference', 'external_reference_id', 'booking_code'] as $field) {
            $reference = $this->normalizedBookingReference(data_get($xs2Order->raw_payload, $field));
            if ($reference === $normalizedBookingNo) {
                return true;
            }
        }

        return false;
    }

    private function normalizedBookingReference(?string $value): ?string
    {
        $value = $this->nullableString($value);

        return $value !== null ? mb_strtoupper($value) : null;
    }

    private function resolveXs2EventForSbOrder(SbOrder $order): ?Xs2Event
    {
        if ($order->match_id !== null) {
            $mapping = EventMapping::query()
                ->where('m_id', $order->match_id)
                ->whereNotNull('xs2_event_id')
                ->first();
            if ($mapping !== null) {
                $event = Xs2Event::query()->find($mapping->xs2_event_id);
                if ($event !== null) {
                    return $event;
                }
            }
        }

        $matchName = $this->nullableString($order->match_name);
        if ($matchName === null) {
            return null;
        }

        $normalizer = app(Xs2TextNormalizer::class);
        $threshold = 80.0;
        $best = null;
        $bestScore = 0.0;

        $query = Xs2Event::query();
        if ($order->match_date !== null) {
            $query->whereDate('date_start_local', $order->match_date);
        }

        foreach ($query->get() as $event) {
            $score = $normalizer->similarity($matchName, $event->event_name);
            if ($score >= $threshold && $score > $bestScore) {
                $bestScore = $score;
                $best = $event;
            }
        }

        if ($best !== null) {
            return $best;
        }

        if ($order->match_date === null) {
            return null;
        }

        foreach (Xs2Event::query()->get() as $event) {
            $score = $normalizer->similarity($matchName, $event->event_name);
            if ($score < $threshold || $score <= $bestScore) {
                continue;
            }

            if (
                $event->date_start_local !== null
                && $event->date_start_local->toDateString() === $order->match_date->toDateString()
            ) {
                $bestScore = $score;
                $best = $event;
            }
        }

        return $best;
    }

    /**
     * @param  Collection<int, Xs2Ticket>  $tickets
     */
    private function selectBestTicketForSbOrder(SbOrder $order, Collection $tickets): ?Xs2Ticket
    {
        $eligible = $tickets->filter(fn (Xs2Ticket $ticket): bool => $this->isEligibleTicket($ticket));
        if ($eligible->isEmpty()) {
            return null;
        }

        $seatCategory = $this->nullableString($order->seat_category);
        if ($seatCategory !== null) {
            $normalizer = app(Xs2TextNormalizer::class);
            $categoryMatch = $eligible->first(function (Xs2Ticket $ticket) use ($seatCategory, $normalizer): bool {
                return $normalizer->similarity($seatCategory, $ticket->category_name) >= 80.0;
            });
            if ($categoryMatch !== null) {
                return $categoryMatch;
            }
        }

        foreach ($this->marketplaceListingIds($order) as $listingId) {
            $mapped = $eligible->first(function (Xs2Ticket $ticket) use ($listingId): bool {
                return ExternalListingMapping::query()
                    ->where('xs2_ticket_id', $ticket->id)
                    ->where('seller_listing_id', $listingId)
                    ->exists();
            });
            if ($mapped !== null) {
                return $mapped;
            }
        }

        $withRate = $eligible
            ->sortByDesc(fn (Xs2Ticket $ticket): int => (int) ($ticket->net_rate ?? 0))
            ->first(fn (Xs2Ticket $ticket): bool => (int) ($ticket->net_rate ?? 0) > 0);

        return $withRate ?? $eligible->first();
    }

    private function resolveEventTicketMappingSkipReason(SbOrder $order): ?string
    {
        $event = $this->resolveXs2EventForSbOrder($order);
        if ($event === null) {
            $matchName = $this->nullableString($order->match_name);
            if ($matchName === null) {
                return null;
            }

            return 'No XS2 event match found for SB order event "'.$matchName.'"'
                .($order->match_date ? ' on '.$order->match_date->toDateString() : '')
                .'.';
        }

        $tickets = Xs2Ticket::query()->where('xs2_event_id', $event->id)->get();
        if ($tickets->isEmpty()) {
            return 'XS2 event "'.$event->event_name.'" has no local tickets to map for manual order creation.';
        }

        $hasEligible = $tickets->contains(fn (Xs2Ticket $ticket): bool => $this->isEligibleTicket($ticket));
        if ($hasEligible) {
            return null;
        }

        foreach ($tickets as $ticket) {
            $reason = $this->ticketIneligibleReason($ticket, 'xs2_events', (string) $event->external_event_id);
            if ($reason !== null) {
                return $reason;
            }
        }

        return 'Mapped XS2 tickets for event "'.$event->event_name.'" are not eligible for '
            .($this->isSandboxEnvironment() ? 'sandbox' : 'production')
            .' order creation.';
    }

    private function resolveBookingEmail(SbOrder $order): string
    {
        $order->loadMissing('attendees');
        foreach ($order->attendees as $attendee) {
            $email = $this->nullableString($attendee->email);
            if ($email !== null) {
                return $email;
            }
        }

        foreach (['buyer_email', 'email'] as $key) {
            $raw = data_get($order->raw_payload, $key);
            $email = $this->nullableString($raw);
            if ($email !== null) {
                return $email;
            }
        }

        return 'xs2-sandbox@example.com';
    }

    /**
     * @param  array<string, mixed>  $bookingOrderResponse
     * @param  array<string, mixed>  $bookingResponse
     * @return array<string, mixed>
     */
    private function orderAttributes(
        SbOrder $order,
        Xs2Ticket $ticket,
        array $bookingOrderResponse,
        array $bookingResponse,
        string $reservationId,
        string $bookingId,
        string $bookingOrderId,
        int $quantity,
    ): array {
        $status = $this->nullableString(
            $bookingOrderResponse['booking_status']
                ?? $bookingOrderResponse['status']
                ?? $bookingResponse['status']
                ?? null,
        );

        return [
            'external_order_id' => $bookingOrderId,
            'is_sandbox' => $this->isSandboxEnvironment(),
            'sb_order_id' => $order->id,
            'xs2_reservation_id' => $reservationId,
            'xs2_booking_id' => $bookingId,
            'xs2_bookingorder_id' => $bookingOrderId,
            'order_status' => $status,
            'order_status_text' => $this->nullableString(
                $bookingOrderResponse['booking_status_text']
                    ?? $bookingOrderResponse['status_text']
                    ?? $bookingResponse['booking_code']
                    ?? null,
            ),
            'ticket_amount' => $order->ticket_amount,
            'currency_type' => $order->currency_type ?? $ticket->currency_code,
            'event_name' => $order->match_name
                ?? $this->nullableString($bookingOrderResponse['event_name'] ?? null)
                ?? $ticket->xs2Event?->event_name,
            'venue_name' => $order->stadium_name
                ?? $this->nullableString($bookingOrderResponse['venue_name'] ?? null),
            'event_date' => $order->match_date,
            'event_time' => $order->match_time,
            'external_event_id' => $ticket->xs2Event?->external_event_id
                ?? $this->nullableString($bookingOrderResponse['event_id'] ?? null),
            'external_ticket_id' => $ticket->external_ticket_id,
            'quantity' => $quantity,
            'seat_category' => $order->seat_category ?? $ticket->category_name,
            'ticket_block' => $order->ticket_block ?? $ticket->ticket_block,
            'row' => $order->row ?? $ticket->row,
            'section' => $order->section ?? $ticket->section,
            'buyer_first_name' => $order->buyer_first_name,
            'buyer_last_name' => $order->buyer_last_name,
            'buyer_email' => $this->resolveBookingEmail($order),
            'raw_payload' => $bookingOrderResponse,
            'sandbox_sync_error' => null,
            'synced_at' => now(),
        ];
    }

    private function syncAttendees(Xs2Order $xs2Order, SbOrder $order): void
    {
        $order->loadMissing('attendees');
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

    private function trySubmitGuestData(
        Xs2Order $xs2Order,
        SbOrder $order,
    ): ?string {
        $result = $this->guestDataSync->syncForXs2Order($xs2Order);

        if ($result['synced'] ?? false) {
            return null;
        }

        if ($result['skipped'] ?? false) {
            return null;
        }

        return $result['error'] ?? 'Guest data sync failed.';
    }

    /** @param array<string, mixed> $bookingResponse */
    private function resolveBookingOrderId(string $bookingId, array $bookingResponse): ?string
    {
        $fromResponse = $this->nullableString($bookingResponse['bookingorder_id'] ?? null);
        if ($fromResponse !== null) {
            return $fromResponse;
        }

        $response = $this->fetchBookingOrdersByBookingId($bookingId);
        $orders = $response['bookingorders'] ?? [];
        if (! is_array($orders)) {
            return null;
        }

        foreach ($orders as $order) {
            if (! is_array($order)) {
                continue;
            }
            $bookingOrderId = $this->nullableString($order['bookingorder_id'] ?? null);
            if ($bookingOrderId !== null) {
                return $bookingOrderId;
            }
        }

        return null;
    }

    private function isSandboxEnvironment(): bool
    {
        return $this->apiEnvironment->xs2OrdersEnvironment() === ApiEnvironmentService::ENV_SANDBOX;
    }

    private function orderApiConfigured(): bool
    {
        return $this->isSandboxEnvironment()
            ? $this->sandbox->isConfigured()
            : $this->client->isOrdersConfigured();
    }

    private function autoOrderDisabledReason(): string
    {
        return $this->isSandboxEnvironment()
            ? 'XS2 sandbox auto-order sync is disabled.'
            : 'XS2 production auto-order sync is disabled.';
    }

    private function orderApiNotConfiguredReason(): string
    {
        return $this->isSandboxEnvironment()
            ? 'XS2 sandbox credentials are not configured.'
            : 'XS2 production credentials are not configured.';
    }

    private function existingOrderSkipReason(): string
    {
        return $this->isSandboxEnvironment()
            ? 'XS2 sandbox order already exists for this SB order.'
            : 'XS2 production order already exists for this SB order.';
    }

    private function noTicketMappingSkipReason(): string
    {
        return $this->isSandboxEnvironment()
            ? 'No sandbox XS2 ticket mapping found for this SB order.'
            : 'No production XS2 ticket mapping found for this SB order.';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     success: bool,
     *     status: int|null,
     *     data: array<string, mixed>,
     *     headers: array<string, list<string>>,
     *     message: string|null
     * }
     */
    private function createReservationDetailed(array $payload): array
    {
        return $this->isSandboxEnvironment()
            ? $this->sandbox->createReservationDetailed($payload)
            : $this->client->createReservationDetailed($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     success: bool,
     *     status: int|null,
     *     data: array<string, mixed>,
     *     headers: array<string, list<string>>,
     *     message: string|null
     * }
     */
    private function createBookingDetailed(array $payload): array
    {
        return $this->isSandboxEnvironment()
            ? $this->sandbox->createBookingDetailed($payload)
            : $this->client->createBookingDetailed($payload);
    }

    /** @return array<string, mixed> */
    private function fetchBookingOrder(string $bookingOrderId): array
    {
        return $this->isSandboxEnvironment()
            ? $this->sandbox->fetchBookingOrder($bookingOrderId)
            : $this->client->getBookingOrderViaOrdersApi($bookingOrderId);
    }

    /** @return array<string, mixed> */
    private function fetchBookingOrdersByBookingId(string $bookingId): array
    {
        return $this->isSandboxEnvironment()
            ? $this->sandbox->fetchBookingOrdersByBookingId($bookingId)
            : $this->client->fetchBookingOrdersByBookingId($bookingId);
    }

    private function pendingExternalOrderId(SbOrder $order): string
    {
        return Xs2BookingOrderIdentity::pendingExternalOrderId((string) $order->booking_no);
    }

    /** @return array{order: Xs2Order|null, created: bool, updated: bool, skipped: bool, reason: string|null} */
    private function skip(?Xs2Order $order, string $reason, ?SbOrder $sbOrder = null): array
    {
        if ($sbOrder !== null) {
            $this->syncLogs->recordSkipped($sbOrder->id, $reason, $order?->id);
        }

        return [
            'order' => $order,
            'created' => false,
            'updated' => false,
            'skipped' => true,
            'reason' => $reason,
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
