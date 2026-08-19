<?php

namespace App\Services\Xs2;

use App\Models\ExternalListingMapping;
use App\Services\Admin\ApiEnvironmentService;
use App\Models\ListingSplit;
use App\Models\SbOrder;
use App\Models\SbOrderAttendee;
use App\Models\Xs2Order;
use App\Models\Xs2OrderAttendee;
use App\Models\Xs2Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When an SB marketplace booking is synced locally, create a matching reservation
 * and booking on the XS2 sandbox API (testapi.xs2event.com) and store it in xs2_orders.
 */
class SbOrderXs2SandboxOrderService
{
    public function __construct(
        private readonly Xs2SandboxService $sandbox,
        private readonly ApiEnvironmentService $apiEnvironment,
        private readonly SbOrderXs2GuestDataSyncService $guestDataSync,
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
        if ($this->apiEnvironment->xs2OrdersEnvironment() !== ApiEnvironmentService::ENV_SANDBOX) {
            return $this->skip(
                null,
                'XS2 production order creation is not implemented (UnsupportedXs2ReservationService); switch Create Order API to sandbox.',
            );
        }

        if (! $this->isEnabled()) {
            return $this->skip(null, 'XS2 sandbox auto-order sync is disabled.');
        }

        if (! $this->sandbox->isConfigured()) {
            return $this->skip(null, 'XS2 sandbox credentials are not configured.');
        }

        if ($this->isCancelled($order)) {
            return $this->skip(null, 'SB order is cancelled.');
        }

        $existing = $this->existingOrder($order);
        if ($existing !== null && filled($existing->xs2_booking_id)) {
            return [
                'order' => $existing,
                'created' => false,
                'updated' => false,
                'skipped' => true,
                'reason' => 'XS2 sandbox order already exists for this SB order.',
            ];
        }

        $ticket = $this->resolveSandboxTicket($order);
        if ($ticket === null) {
            return $this->skip($existing, 'No sandbox XS2 ticket mapping found for this SB order.');
        }

        $quantity = max(1, (int) ($order->quantity ?? 1));
        $netRate = (int) ($ticket->net_rate ?? 0);
        if ($netRate <= 0) {
            return $this->skip($existing, 'Sandbox ticket is missing net_rate.');
        }

        $currency = (string) ($ticket->currency_code ?? $order->currency_type ?? 'EUR');
        $salesPrice = (int) ($ticket->face_value ?? $netRate);
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
                $reservationResponse = $this->sandbox->createReservation($reservationRequest);
                $reservationId = $this->nullableString($reservationResponse['reservation_id'] ?? null);
                if ($reservationId === null) {
                    throw new \RuntimeException('XS2 sandbox reservation response did not include reservation_id.');
                }

                $bookingRequest = [
                    'reservation_id' => $reservationId,
                    'booking_email' => $bookingEmail,
                    'booking_reference' => $order->booking_no,
                    'invoice_reference' => $order->booking_no,
                    'payment_method' => 'invoice',
                    'is_test_booking' => true,
                ];

                $bookingResponse = $this->sandbox->createBooking($bookingRequest);
                $bookingId = $this->nullableString($bookingResponse['booking_id'] ?? null);
                if ($bookingId === null) {
                    throw new \RuntimeException('XS2 sandbox booking response did not include booking_id.');
                }

                $bookingOrderId = $this->resolveBookingOrderId($bookingId, $bookingResponse);
                if ($bookingOrderId === null) {
                    throw new \RuntimeException('XS2 sandbox booking was created but bookingorder_id could not be resolved.');
                }

                $bookingOrderResponse = $this->sandbox->fetchBookingOrder($bookingOrderId);
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
                    'is_sandbox' => true,
                    'sb_order_id' => $order->id,
                    'event_name' => $order->match_name,
                    'venue_name' => $order->stadium_name,
                    'event_date' => $order->match_date,
                    'event_time' => $order->match_time,
                    'external_ticket_id' => $ticket->external_ticket_id,
                    'quantity' => $quantity,
                    'order_status' => 'failed',
                    'order_status_text' => 'Sandbox sync failed',
                    'sandbox_sync_error' => $message,
                    'synced_at' => now(),
                ]);
            } else {
                $existing->fill([
                    'order_status' => 'failed',
                    'order_status_text' => 'Sandbox sync failed',
                    'sandbox_sync_error' => $message,
                    'synced_at' => now(),
                ])->save();
            }

            return [
                'order' => $existing->fresh(),
                'created' => false,
                'updated' => true,
                'skipped' => false,
                'reason' => $message,
            ];
        }
    }

    public function resolveSandboxTicket(SbOrder $order): ?Xs2Ticket
    {
        foreach ($this->marketplaceListingIds($order) as $listingId) {
            $mapping = ExternalListingMapping::query()
                ->where('seller_listing_id', $listingId)
                ->first();
            if ($mapping !== null) {
                $ticket = Xs2Ticket::query()->find($mapping->xs2_ticket_id);
                if ($this->isSandboxTicket($ticket)) {
                    return $ticket;
                }
            }

            if (Schema::hasTable('listing_splits')) {
                $split = ListingSplit::query()
                    ->where('seatsbroker_listing_id', $listingId)
                    ->first();
                if ($split !== null) {
                    $ticket = Xs2Ticket::query()->find($split->master_listing_id);
                    if ($this->isSandboxTicket($ticket)) {
                        return $ticket;
                    }
                }
            }
        }

        return null;
    }

    public function queueIfEligible(SbOrder $order): bool
    {
        if ($this->apiEnvironment->xs2OrdersEnvironment() !== ApiEnvironmentService::ENV_SANDBOX) {
            return false;
        }

        if (! $this->isEnabled() || ! $this->sandbox->isConfigured() || $this->isCancelled($order)) {
            return false;
        }

        if ($this->existingOrder($order)?->xs2_booking_id) {
            return false;
        }

        return $this->resolveSandboxTicket($order) !== null;
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

    private function isSandboxTicket(?Xs2Ticket $ticket): bool
    {
        if ($ticket === null) {
            return false;
        }

        if (Schema::hasColumn('xs2_tickets', 'is_sandbox')) {
            return (bool) $ticket->is_sandbox;
        }

        return true;
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
            'is_sandbox' => true,
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

        $response = $this->sandbox->fetchBookingOrdersByBookingId($bookingId);
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

    private function pendingExternalOrderId(SbOrder $order): string
    {
        return 'sb-pending:'.$order->booking_no;
    }

    /** @return array{order: Xs2Order|null, created: bool, updated: bool, skipped: bool, reason: string|null} */
    private function skip(?Xs2Order $order, string $reason): array
    {
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
