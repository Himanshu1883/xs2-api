<?php

namespace App\Services\Xs2;

use App\Models\Xs2SandboxTestOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Xs2SandboxTestFlowService
{
    private const CUSTOMER_NAME = 'XS2 Sandbox Test Customer';

    private const CUSTOMER_EMAIL = 'xs2-sandbox@example.com';

    public function __construct(private readonly Xs2SandboxService $sandbox) {}

    /** @param array<string, mixed> $event @param array<string, mixed> $listing */
    public function createDummySeatsBrokerOrder(array $event, array $listing, int $quantity = 1): Xs2SandboxTestOrder
    {
        $eventId = (string) ($event['external_event_id'] ?? '');
        $ticketId = (string) ($listing['ticket_id'] ?? '');

        if ($eventId === '' || $ticketId === '') {
            throw new \InvalidArgumentException('Sandbox event and listing must include external IDs.');
        }

        $quantity = $this->resolveOrderQuantity($quantity, $listing);

        return Xs2SandboxTestOrder::query()->create([
            'seatsbroker_order_id' => $this->generateSeatsBrokerOrderId(),
            'environment' => Xs2SandboxTestOrder::ENVIRONMENT,
            'is_sandbox' => true,
            'status' => Xs2SandboxTestOrder::STATUS_SB_ORDER_CREATED,
            'customer_name' => self::CUSTOMER_NAME,
            'customer_email' => self::CUSTOMER_EMAIL,
            'quantity' => $quantity,
            'xs2_event_id' => $eventId,
            'xs2_event_payload' => $event,
            'xs2_ticket_id' => $ticketId,
            'xs2_ticket_payload' => $listing,
            'sb_order_created_at' => now(),
        ]);
    }

    /**
     * @return array{order: Xs2SandboxTestOrder, already_created: bool}
     */
    public function createXs2Order(Xs2SandboxTestOrder $order): array
    {
        if ($order->hasXs2Order()) {
            return ['order' => $order->fresh(), 'already_created' => true];
        }

        if ($order->status !== Xs2SandboxTestOrder::STATUS_SB_ORDER_CREATED) {
            throw new \RuntimeException('Create the dummy SeatsBroker order before placing an XS2 sandbox booking.');
        }

        $ticket = is_array($order->xs2_ticket_payload) ? $order->xs2_ticket_payload : [];
        $ticketId = (string) ($ticket['ticket_id'] ?? $order->xs2_ticket_id ?? '');
        $netRate = (int) ($ticket['net_rate'] ?? 0);
        $currency = (string) ($ticket['currency_code'] ?? 'EUR');
        $salesPrice = (int) ($ticket['sales_price'] ?? $netRate);

        if ($ticketId === '' || $netRate <= 0) {
            throw new \RuntimeException('Sandbox listing is missing ticket_id or net_rate.');
        }

        $reservationRequest = [
            'items' => [[
                'ticket_id' => $ticketId,
                'quantity' => $order->quantity,
                'net_rate' => $netRate,
                'currency_code' => $currency,
                'sales_price' => $salesPrice > 0 ? $salesPrice : $netRate,
            ]],
            'booking_email' => self::CUSTOMER_EMAIL,
            'notify_me' => false,
            'notes' => 'SeatsBroker sandbox test order '.$order->seatsbroker_order_id,
            'external_reference_id' => $order->seatsbroker_order_id,
            'target_currency' => $currency,
        ];

        return DB::transaction(function () use ($order, $reservationRequest): array {
            /** @var Xs2SandboxTestOrder $locked */
            $locked = Xs2SandboxTestOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->hasXs2Order()) {
                return ['order' => $locked, 'already_created' => true];
            }

            $reservationResponse = null;
            $reservationId = null;
            $bookingRequest = null;
            $bookingResponse = null;

            try {
                $reservationResponse = $this->sandbox->createReservation($reservationRequest);
                $reservationId = $this->extractReservationId($reservationResponse);

                $bookingRequest = [
                    'reservation_id' => $reservationId,
                    'booking_email' => self::CUSTOMER_EMAIL,
                    'booking_reference' => $locked->seatsbroker_order_id,
                    'invoice_reference' => $locked->seatsbroker_order_id,
                    'payment_method' => 'invoice',
                    'is_test_booking' => true,
                ];

                $bookingResponse = $this->sandbox->createBooking($bookingRequest);
                $bookingId = $this->nullableString($bookingResponse['booking_id'] ?? null);
                $bookingOrderId = $this->resolveBookingOrderIdFromApi($bookingId);
                if ($bookingOrderId === null) {
                    throw new \RuntimeException('XS2 sandbox booking was created but bookingorder_id could not be resolved.');
                }

                $locked->fill([
                    'status' => Xs2SandboxTestOrder::STATUS_XS2_ORDER_CREATED,
                    'xs2_reservation_id' => $reservationId,
                    'xs2_booking_id' => $bookingId,
                    'xs2_bookingorder_id' => $bookingOrderId,
                    'xs2_booking_code' => $this->nullableString($bookingResponse['booking_code'] ?? null),
                    'xs2_reservation_request' => $reservationRequest,
                    'xs2_reservation_response' => $reservationResponse,
                    'xs2_booking_request' => $bookingRequest,
                    'xs2_booking_response' => $bookingResponse,
                    'last_error' => null,
                    'xs2_order_created_at' => now(),
                ])->save();
            } catch (\Throwable $exception) {
                $locked->fill([
                    'status' => Xs2SandboxTestOrder::STATUS_FAILED,
                    'xs2_reservation_id' => $reservationId,
                    'xs2_reservation_request' => $reservationRequest,
                    'xs2_reservation_response' => $reservationResponse,
                    'xs2_booking_request' => $bookingRequest,
                    'xs2_booking_response' => $bookingResponse,
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                ])->save();

                throw $exception;
            }

            return ['order' => $locked->fresh(), 'already_created' => false];
        });
    }

    public function refreshFromXs2(Xs2SandboxTestOrder $order): Xs2SandboxTestOrder
    {
        $bookingId = $this->nullableString($order->xs2_booking_id);
        if ($bookingId === null) {
            throw new \RuntimeException('This sandbox test order does not have an XS2 booking_id yet.');
        }

        $bookingResponse = $this->sandbox->fetchBooking($bookingId);
        $bookingOrderId = $this->resolveBookingOrderId($order, refreshFromApi: true);
        $bookingOrderResponse = $this->sandbox->fetchBookingOrder($bookingOrderId);

        $order->fill([
            'xs2_booking_response' => $bookingOrderResponse,
            'xs2_booking_code' => $this->nullableString(
                $bookingOrderResponse['booking_code']
                    ?? $bookingResponse['booking_code']
                    ?? $order->xs2_booking_code,
            ),
            'last_error' => null,
        ])->save();

        return $order->fresh();
    }

    /**
     * Import a remote XS2 sandbox booking order into xs2_sandbox_test_orders.
     *
     * @param  array{bookingorder_id?: string|null, booking_id?: string|null}  $input
     * @return array{order: Xs2SandboxTestOrder, already_imported: bool}
     */
    public function importFromXs2(array $input): array
    {
        $bookingOrderId = $this->nullableString($input['bookingorder_id'] ?? null);
        $bookingId = $this->nullableString($input['booking_id'] ?? null);

        if ($bookingOrderId === null && $bookingId === null) {
            throw new \InvalidArgumentException('Provide bookingorder_id or booking_id to import from XS2.');
        }

        $existing = Xs2SandboxTestOrder::query()
            ->when(
                $bookingOrderId !== null,
                fn ($query) => $query->where('xs2_bookingorder_id', $bookingOrderId),
                fn ($query) => $query->where('xs2_booking_id', $bookingId),
            )
            ->first();

        if ($existing !== null) {
            return ['order' => $existing, 'already_imported' => true];
        }

        if ($bookingOrderId === null) {
            $bookingOrderId = $this->resolveBookingOrderIdFromApi($bookingId);
            if ($bookingOrderId === null) {
                throw new \RuntimeException(sprintf(
                    'Could not resolve XS2 bookingorder_id for booking %s.',
                    $bookingId,
                ));
            }
        }

        $bookingOrderResponse = $this->sandbox->fetchBookingOrder($bookingOrderId);
        $resolvedBookingId = $this->nullableString($bookingOrderResponse['booking_id'] ?? $bookingId);
        $items = $this->normalizeBookingOrderItems($bookingOrderResponse);
        $firstItem = $items[0] ?? null;
        $ticketId = is_array($firstItem) ? $this->nullableString($firstItem['ticket_id'] ?? null) : null;
        $eventId = $this->nullableString($bookingOrderResponse['event_id'] ?? null);
        $eventName = $this->nullableString($bookingOrderResponse['event_name'] ?? null);
        $quantity = $this->resolveImportedOrderQuantity($items);
        $reference = $this->resolveImportedSeatsBrokerOrderId($bookingOrderResponse);
        $createdAt = $this->parseImportedTimestamp(
            $bookingOrderResponse['created']
                ?? $bookingOrderResponse['created_at']
                ?? null,
        );

        $order = Xs2SandboxTestOrder::query()->create([
            'seatsbroker_order_id' => $reference,
            'environment' => Xs2SandboxTestOrder::ENVIRONMENT,
            'is_sandbox' => true,
            'status' => Xs2SandboxTestOrder::STATUS_XS2_ORDER_CREATED,
            'customer_name' => self::CUSTOMER_NAME,
            'customer_email' => $this->nullableString($bookingOrderResponse['booking_email'] ?? null)
                ?? self::CUSTOMER_EMAIL,
            'quantity' => $quantity,
            'xs2_event_id' => $eventId,
            'xs2_event_payload' => array_filter([
                'external_event_id' => $eventId,
                'event_name' => $eventName,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'xs2_ticket_id' => $ticketId,
            'xs2_ticket_payload' => is_array($firstItem) ? $firstItem : null,
            'xs2_reservation_id' => $this->nullableString($bookingOrderResponse['reservation_id'] ?? null),
            'xs2_booking_id' => $resolvedBookingId,
            'xs2_bookingorder_id' => $bookingOrderId,
            'xs2_booking_code' => $this->nullableString($bookingOrderResponse['booking_code'] ?? null),
            'xs2_booking_response' => $bookingOrderResponse,
            'last_error' => null,
            'sb_order_created_at' => $createdAt ?? now(),
            'xs2_order_created_at' => $createdAt ?? now(),
        ]);

        return ['order' => $order->fresh(), 'already_imported' => false];
    }

    /**
     * @return array{
     *     quantity: int,
     *     xs2_ticket_id: string,
     *     xs2_booking_id: string,
     *     xs2_bookingorder_id: string,
     *     guest_data_requirements: list<string>,
     *     guests: list<array<string, mixed>>|null,
     *     customer_name: string|null,
     *     customer_email: string|null
     * }
     */
    public function guestDataForm(Xs2SandboxTestOrder $order): array
    {
        $bookingId = $this->nullableString($order->xs2_booking_id);
        if ($bookingId === null) {
            throw new \RuntimeException('This sandbox test order does not have an XS2 booking_id yet.');
        }

        $bookingOrderId = $this->resolveBookingOrderId($order);

        $ticketId = $this->nullableString($order->xs2_ticket_id);
        if ($ticketId === null) {
            throw new \RuntimeException('Sandbox test order is missing xs2_ticket_id.');
        }

        $bookingOrderGuestPayload = $this->sandbox->fetchBookingOrderGuestData($bookingOrderId);
        $existingGuests = $this->extractGuestsForTicket($bookingOrderGuestPayload, $ticketId, $order->quantity);
        $requirements = $this->parseGuestRequirementsFromBookingOrder($bookingOrderGuestPayload, $ticketId);

        if ($requirements === []) {
            $requirementsPayload = $this->sandbox->fetchTicketGuestRequirements($ticketId);
            $ticketRequirements = $requirementsPayload['guest_data_requirements'] ?? [];
            if (is_array($ticketRequirements)) {
                $requirements = array_values(array_filter($ticketRequirements, is_string(...)));
            }
        }

        if ($existingGuests === []) {
            $savedRequest = $order->xs2_guest_data_request;
            if (is_array($savedRequest)) {
                $items = $savedRequest['items'] ?? [];
                $firstItem = $items[0] ?? null;
                if (is_array($firstItem) && is_array($firstItem['guests'] ?? null)) {
                    $existingGuests = array_values($firstItem['guests']);
                }
            }
        }

        return [
            'quantity' => $order->quantity,
            'xs2_ticket_id' => $ticketId,
            'xs2_booking_id' => $bookingId,
            'xs2_bookingorder_id' => $bookingOrderId,
            'guest_data_requirements' => $requirements,
            'guests' => $existingGuests !== [] ? $existingGuests : null,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $guests
     */
    public function updateGuestData(Xs2SandboxTestOrder $order, array $guests): Xs2SandboxTestOrder
    {
        $bookingOrderId = $this->resolveBookingOrderId($order);

        $ticketId = $this->nullableString($order->xs2_ticket_id);
        if ($ticketId === null) {
            throw new \RuntimeException('Sandbox test order is missing xs2_ticket_id.');
        }

        $guests = array_values($guests);
        if (count($guests) !== $order->quantity) {
            throw new \InvalidArgumentException(sprintf(
                'Expected %d guest(s) for this order but received %d.',
                $order->quantity,
                count($guests),
            ));
        }

        $bookingOrderGuestPayload = $this->sandbox->fetchBookingOrderGuestData($bookingOrderId);
        $requirements = $this->parseGuestRequirementsFromBookingOrder($bookingOrderGuestPayload, $ticketId);
        if ($requirements === []) {
            $requirementsPayload = $this->sandbox->fetchTicketGuestRequirements($ticketId);
            $ticketRequirements = $requirementsPayload['guest_data_requirements'] ?? [];
            if (is_array($ticketRequirements)) {
                $requirements = array_values(array_filter($ticketRequirements, is_string(...)));
            }
        }

        $this->validateGuestsAgainstRequirements($guests, $requirements);

        $existingGuests = $this->extractGuestsForTicket($bookingOrderGuestPayload, $ticketId, $order->quantity);
        $normalizedGuests = $this->mergeGuestIds($this->normalizeGuests($guests), $existingGuests);
        $requestPayload = [
            'items' => [[
                'ticket_id' => $ticketId,
                'guests' => $normalizedGuests,
            ]],
        ];

        $response = $this->sandbox->updateBookingGuestData($bookingOrderId, $ticketId, $normalizedGuests);

        $order->fill([
            'xs2_guest_data_request' => $requestPayload,
            'xs2_guest_data_response' => $response,
            'last_error' => null,
        ])->save();

        return $order->fresh();
    }

    /**
     * @return array{
     *     body: string,
     *     content_type: string,
     *     filename: string,
     *     order: Xs2SandboxTestOrder
     * }
     */
    public function downloadEticket(Xs2SandboxTestOrder $order, int $itemIndex = 0): array
    {
        $bookingId = $this->nullableString($order->xs2_booking_id);
        if ($bookingId === null) {
            throw new \RuntimeException('This sandbox test order does not have an XS2 booking_id yet.');
        }

        if ($order->status !== Xs2SandboxTestOrder::STATUS_XS2_ORDER_CREATED) {
            throw new \RuntimeException('E-tickets can only be downloaded after the XS2 sandbox order has been created.');
        }

        $bookingPayload = $this->resolveBookingPayloadForEticket($order, $bookingId);
        $targets = $this->collectEticketTargets(
            $bookingPayload,
            $bookingId,
            $this->nullableString($order->xs2_ticket_id),
        );

        if ($targets === []) {
            $logisticStatus = $this->nullableString($bookingPayload['logistic_status'] ?? null);

            throw new \RuntimeException(match (true) {
                $logisticStatus !== null && $logisticStatus !== 'completed' => sprintf(
                    'E-ticket is not ready yet (logistic_status=%s). Refresh from XS2 after the booking order is completed.',
                    $logisticStatus,
                ),
                default => 'No downloadable e-ticket links were found in the XS2 booking response. Refresh from XS2 and try again.',
            });
        }

        if (! array_key_exists($itemIndex, $targets)) {
            throw new \InvalidArgumentException(sprintf(
                'E-ticket index %d is out of range. This order has %d downloadable e-ticket(s).',
                $itemIndex,
                count($targets),
            ));
        }

        $target = $targets[$itemIndex];
        $requestPayload = [
            'bookingorder_id' => $target['bookingorder_id'],
            'orderitem_id' => $target['orderitem_id'],
            'download_link' => $target['download_link'],
            'item_index' => $itemIndex,
            'requested_at' => now()->toIso8601String(),
        ];

        if ($target['distribution_channel'] !== null && $target['distribution_channel'] !== 'xs2event') {
            throw new \RuntimeException(sprintf(
                'E-ticket download is not available for distribution channel "%s".',
                $target['distribution_channel'],
            ));
        }

        try {
            $response = $this->sandbox->downloadEticketPdf(
                $target['bookingorder_id'],
                $target['orderitem_id'],
                $target['download_link'],
            );

            $contentType = $this->firstHeaderValue($response['headers'], 'Content-Type') ?? 'application/pdf';
            $filename = $this->resolveDownloadFilename(
                $this->firstHeaderValue($response['headers'], 'Content-Disposition'),
                $target['download_link'],
                $order,
                $itemIndex,
            );

            $order->fill([
                'xs2_eticket_request' => $requestPayload,
                'xs2_eticket_response' => [
                    'success' => true,
                    'content_type' => $contentType,
                    'filename' => $filename,
                    'byte_size' => strlen($response['body']),
                    'fetched_at' => now()->toIso8601String(),
                ],
                'last_error' => null,
            ])->save();

            return [
                'body' => $response['body'],
                'content_type' => $contentType,
                'filename' => $filename,
                'order' => $order->fresh(),
            ];
        } catch (\Throwable $exception) {
            $order->fill([
                'xs2_eticket_request' => $requestPayload,
                'xs2_eticket_response' => [
                    'success' => false,
                    'error' => mb_substr($exception->getMessage(), 0, 2000),
                    'fetched_at' => now()->toIso8601String(),
                ],
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function resolveBookingPayloadForEticket(Xs2SandboxTestOrder $order, string $bookingId): array
    {
        $stored = $order->xs2_booking_response;
        if (is_array($stored) && $this->collectEticketTargets($stored, $bookingId, $this->nullableString($order->xs2_ticket_id)) !== []) {
            return $stored;
        }

        $bookingPayload = $this->sandbox->fetchBooking($bookingId);
        $order->fill([
            'xs2_booking_response' => $bookingPayload,
            'xs2_booking_code' => $this->nullableString($bookingPayload['booking_code'] ?? $order->xs2_booking_code),
            'last_error' => null,
        ])->save();

        if ($this->collectEticketTargets($bookingPayload, $bookingId, $this->nullableString($order->xs2_ticket_id)) !== []) {
            return $bookingPayload;
        }

        $bookingOrderId = $this->resolveBookingOrderId($order);
        $bookingOrderPayload = $this->sandbox->fetchBookingOrder($bookingOrderId);
        $order->fill(['xs2_booking_response' => $bookingOrderPayload])->save();

        return $bookingOrderPayload;
    }

    /**
     * @return list<array{
     *     bookingorder_id: string,
     *     orderitem_id: string,
     *     download_link: string,
     *     distribution_channel: string|null,
     *     ticket_id: string|null
     * }>
     */
    private function collectEticketTargets(array $bookingPayload, string $fallbackBookingId, ?string $preferredTicketId): array
    {
        $bookingOrderId = $this->nullableString(
            $bookingPayload['bookingorder_id']
            ?? $bookingPayload['booking_id']
            ?? $fallbackBookingId,
        );

        if ($bookingOrderId === null) {
            return [];
        }

        $items = $bookingPayload['items'] ?? $bookingPayload['tickets'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $targets = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $ticketId = $this->nullableString($item['ticket_id'] ?? null);
            if ($preferredTicketId !== null && $ticketId !== null && $ticketId !== $preferredTicketId) {
                continue;
            }

            $orderItemId = $this->nullableString($item['orderitem_id'] ?? $item['order_item_id'] ?? $item['ticket_id'] ?? null);
            $distributionChannel = $this->nullableString($item['distribution_channel'] ?? null);
            $downloadLinks = $this->downloadLinksForItem($item);

            foreach ($downloadLinks as $downloadLink) {
                if ($orderItemId === null) {
                    continue;
                }

                $targets[] = [
                    'bookingorder_id' => $bookingOrderId,
                    'orderitem_id' => $orderItemId,
                    'download_link' => $downloadLink,
                    'distribution_channel' => $distributionChannel,
                    'ticket_id' => $ticketId,
                ];
            }
        }

        return $targets;
    }

    /** @param array<string, mixed> $item @return list<string> */
    private function downloadLinksForItem(array $item): array
    {
        $links = [];

        $itemDownloadLink = $this->normalizeDownloadLink($item['download_link'] ?? null);
        if ($itemDownloadLink !== null) {
            $links[] = $itemDownloadLink;
        }

        $downloadItems = $item['download_items'] ?? [];
        if (is_array($downloadItems)) {
            foreach ($downloadItems as $downloadItem) {
                if (! is_array($downloadItem)) {
                    continue;
                }

                $downloadLink = $this->normalizeDownloadLink($downloadItem['download_link'] ?? null);
                if ($downloadLink !== null) {
                    $links[] = $downloadLink;
                }
            }
        }

        if ($links === []) {
            $legacyUrl = $this->normalizeDownloadLink($item['download_url'] ?? null);
            if ($legacyUrl !== null) {
                $links[] = $legacyUrl;
            }
        }

        return array_values(array_unique($links));
    }

    private function normalizeDownloadLink(mixed $value): ?string
    {
        $link = $this->nullableString($value);
        if ($link === null) {
            return null;
        }

        if (str_contains($link, '://')) {
            $path = parse_url($link, PHP_URL_PATH);
            if (! is_string($path) || $path === '') {
                return null;
            }

            $basename = basename($path);

            return $basename !== '' && $basename !== '/' ? $basename : null;
        }

        return ltrim($link, '/');
    }

    /** @param array<string, list<string>> $headers */
    private function firstHeaderValue(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $values) {
            if (strcasecmp((string) $headerName, $name) !== 0 || ! is_array($values) || $values === []) {
                continue;
            }

            $value = $this->nullableString($values[0] ?? null);

            return $value;
        }

        return null;
    }

    private function resolveDownloadFilename(
        ?string $contentDisposition,
        string $downloadLink,
        Xs2SandboxTestOrder $order,
        int $itemIndex,
    ): string {
        if (is_string($contentDisposition) && preg_match('/filename=\"?([^\";]+)\"?/i', $contentDisposition, $matches) === 1) {
            $filename = $this->nullableString($matches[1] ?? null);
            if ($filename !== null) {
                return $filename;
            }
        }

        $fallback = basename($downloadLink);
        if ($fallback !== '' && $fallback !== '.') {
            return $fallback;
        }

        $bookingCode = $this->nullableString($order->xs2_booking_code) ?? 'xs2-sandbox-ticket';

        return sprintf('%s-%d.pdf', $bookingCode, $itemIndex + 1);
    }

    /** @param array<string, mixed> $payload @return list<string> */
    private function parseGuestRequirementsFromBookingOrder(array $payload, string $ticketId): array
    {
        $requirements = [];

        foreach ($this->bookingOrderGuestItems($payload, $ticketId) as $item) {
            foreach ($item['guests'] ?? [] as $guest) {
                if (! is_array($guest)) {
                    continue;
                }

                foreach ($guest as $field => $value) {
                    if (! is_string($field) || $this->isGuestMetaField($field)) {
                        continue;
                    }

                    if (is_array($value) && filled($value['condition'] ?? null)) {
                        $requirements[] = $field;
                    }
                }

                $conditions = $guest['conditions'] ?? null;
                if (is_array($conditions)) {
                    foreach ($conditions as $field => $condition) {
                        if (is_string($field) && is_string($condition) && $condition !== '') {
                            $requirements[] = $field;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($requirements));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractGuestsForTicket(array $payload, string $ticketId, int $quantity): array
    {
        $guests = [];

        foreach ($this->bookingOrderGuestItems($payload, $ticketId) as $item) {
            foreach ($item['guests'] ?? [] as $guest) {
                if (! is_array($guest)) {
                    continue;
                }

                $guests[] = $this->flattenBookingOrderGuest($guest);
            }
        }

        return array_slice($guests, 0, max(0, $quantity));
    }

    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    private function bookingOrderGuestItems(array $payload, string $ticketId): array
    {
        $items = $payload['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $matched = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemTicketId = $this->nullableString($item['ticket_id'] ?? null);
            if ($itemTicketId !== null && $itemTicketId !== $ticketId) {
                continue;
            }

            $matched[] = $item;
        }

        return $matched !== [] ? $matched : array_values(array_filter($items, is_array(...)));
    }

    /** @param array<string, mixed> $guest @return array<string, mixed> */
    private function flattenBookingOrderGuest(array $guest): array
    {
        $flat = [];

        foreach ($guest as $field => $value) {
            if (! is_string($field) || in_array($field, ['conditions', 'ticket_id', 'reservation_id'], true)) {
                continue;
            }

            if ($field === 'lead_guest') {
                $flat[$field] = (bool) $value;

                continue;
            }

            if ($field === 'guest_id') {
                $guestId = $this->nullableString($value);
                if ($guestId !== null) {
                    $flat[$field] = $guestId;
                }

                continue;
            }

            if (is_array($value) && array_key_exists('value', $value)) {
                $scalar = $this->nullableString($value['value'] ?? null);
                if ($scalar !== null) {
                    $flat[$field] = $scalar;
                }

                continue;
            }

            if (is_scalar($value)) {
                $scalar = $this->nullableString($value);
                if ($scalar !== null) {
                    $flat[$field] = $scalar;
                }
            }
        }

        return $flat;
    }

    /** @param list<array<string, mixed>> $guests @param list<string> $requirements */
    private function validateGuestsAgainstRequirements(array $guests, array $requirements): void
    {
        if ($requirements === []) {
            return;
        }

        foreach ($guests as $index => $guest) {
            if (! is_array($guest)) {
                throw new \InvalidArgumentException(sprintf('Guest %d must be an object.', $index + 1));
            }

            foreach ($requirements as $field) {
                if ($this->guestFieldValue($guest, $field) === null) {
                    throw new \InvalidArgumentException(sprintf(
                        '%s is required for guest %d.',
                        $field,
                        $index + 1,
                    ));
                }
            }
        }
    }

    /** @param array<string, mixed> $guest */
    private function guestFieldValue(array $guest, string $field): ?string
    {
        $aliases = [
            'nationality' => ['country_of_residence', 'nationality', 'country'],
            'email' => ['contact_email', 'email'],
            'phone' => ['contact_phone', 'phone'],
            'dob' => ['date_of_birth', 'dob'],
        ];

        foreach ($aliases[$field] ?? [$field] as $key) {
            $value = $this->nullableString($guest[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
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

    private function isGuestMetaField(string $field): bool
    {
        return in_array($field, ['lead_guest', 'guest_id', 'conditions'], true);
    }

    /** @param list<array<string, mixed>> $guests @return list<array<string, mixed>> */
    private function normalizeGuests(array $guests): array
    {
        $allowedKeys = [
            'guest_id',
            'first_name',
            'last_name',
            'passport_number',
            'contact_email',
            'contact_phone',
            'date_of_birth',
            'gender',
            'country_of_residence',
            'street_name',
            'city',
            'zip',
        ];

        $normalized = [];

        foreach ($guests as $index => $guest) {
            if (! is_array($guest)) {
                continue;
            }

            $entry = [];
            foreach ($allowedKeys as $key) {
                $value = $this->nullableString($guest[$key] ?? null);
                if ($value !== null) {
                    $entry[$key] = $value;
                }
            }

            if (! isset($entry['contact_email'])) {
                $email = $this->nullableString($guest['email'] ?? null);
                if ($email !== null) {
                    $entry['contact_email'] = $email;
                }
            }

            if (! isset($entry['contact_phone'])) {
                $phone = $this->nullableString($guest['phone'] ?? null);
                if ($phone !== null) {
                    $entry['contact_phone'] = $phone;
                }
            }

            if (! isset($entry['country_of_residence'])) {
                $nationality = $this->nullableString($guest['nationality'] ?? null);
                if ($nationality !== null) {
                    $entry['country_of_residence'] = strtoupper($nationality);
                }
            }
            if (isset($entry['country_of_residence'])) {
                $entry['country_of_residence'] = strtoupper($entry['country_of_residence']);
            }

            if (! isset($entry['date_of_birth'])) {
                $dob = $this->nullableString($guest['dob'] ?? null);
                if ($dob !== null) {
                    $entry['date_of_birth'] = $dob;
                }
            }

            if (isset($entry['gender'])) {
                $gender = strtolower($entry['gender']);
                $entry['gender'] = match ($gender) {
                    'other' => 'unknown',
                    default => $gender,
                };
            }

            $entry['lead_guest'] = $index === 0;

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $listing */
    private function resolveOrderQuantity(int $quantity, array $listing): int
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Order quantity must be at least 1.');
        }

        $maxQuantity = (int) config('xs2.sandbox.max_order_quantity', 20);
        if ($maxQuantity < 1) {
            $maxQuantity = 20;
        }

        $stock = $listing['stock'] ?? null;
        if (is_numeric($stock)) {
            $stockQuantity = (int) $stock;
            if ($stockQuantity > 0) {
                $maxQuantity = min($maxQuantity, $stockQuantity);
            }
        }

        if ($quantity > $maxQuantity) {
            throw new \InvalidArgumentException(sprintf(
                'Order quantity %d exceeds the maximum allowed (%d) for this sandbox listing.',
                $quantity,
                $maxQuantity,
            ));
        }

        return $quantity;
    }

    private function generateSeatsBrokerOrderId(): string
    {
        do {
            $reference = 'SB-SANDBOX-'.strtoupper(Str::random(8));
        } while (Xs2SandboxTestOrder::query()->where('seatsbroker_order_id', $reference)->exists());

        return $reference;
    }

    /** @param array<string, mixed> $bookingOrderResponse */
    private function resolveImportedSeatsBrokerOrderId(array $bookingOrderResponse): string
    {
        foreach (['booking_reference', 'invoice_reference', 'payment_reference'] as $field) {
            $candidate = $this->nullableString($bookingOrderResponse[$field] ?? null);
            if ($candidate === null) {
                continue;
            }

            if (! str_starts_with(strtoupper($candidate), 'SB-SANDBOX-')) {
                continue;
            }

            if (! Xs2SandboxTestOrder::query()->where('seatsbroker_order_id', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $this->generateSeatsBrokerOrderId();
    }

    /** @param array<string, mixed> $bookingOrderResponse @return list<array<string, mixed>> */
    private function normalizeBookingOrderItems(array $bookingOrderResponse): array
    {
        $items = $bookingOrderResponse['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, is_array(...)));
    }

    /** @param list<array<string, mixed>> $items */
    private function resolveImportedOrderQuantity(array $items): int
    {
        if ($items === []) {
            return 1;
        }

        $quantity = 0;
        foreach ($items as $item) {
            $itemQuantity = (int) ($item['quantity'] ?? 1);
            $quantity += max(1, $itemQuantity);
        }

        return max(1, $quantity);
    }

    private function parseImportedTimestamp(mixed $value): ?\Illuminate\Support\Carbon
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $response */
    private function extractReservationId(array $response): string
    {
        $reservationId = $this->nullableString($response['reservation_id'] ?? null);
        if ($reservationId === null) {
            throw new \RuntimeException('XS2 sandbox reservation response did not include reservation_id.');
        }

        return $reservationId;
    }

    private function resolveBookingOrderId(Xs2SandboxTestOrder $order, bool $refreshFromApi = false): string
    {
        $bookingId = $this->nullableString($order->xs2_booking_id);

        if (! $refreshFromApi) {
            $stored = $this->nullableString($order->xs2_bookingorder_id);
            if ($stored !== null && ! $this->looksLikeBookingIdNotBookingOrderId($stored, $bookingId)) {
                return $stored;
            }

            $bookingResponse = $order->xs2_booking_response;
            if (is_array($bookingResponse)) {
                $fromResponse = $this->nullableString($bookingResponse['bookingorder_id'] ?? null);
                if ($fromResponse !== null && ! $this->looksLikeBookingIdNotBookingOrderId($fromResponse, $bookingId)) {
                    $this->persistBookingOrderId($order, $fromResponse);

                    return $fromResponse;
                }
            }
        }

        if ($bookingId === null) {
            throw new \RuntimeException('This sandbox test order does not have an XS2 booking_id yet.');
        }

        $bookingOrderId = $this->resolveBookingOrderIdFromApi($bookingId);
        $this->persistBookingOrderId($order, $bookingOrderId);

        return $bookingOrderId;
    }

    private function looksLikeBookingIdNotBookingOrderId(string $candidate, ?string $bookingId): bool
    {
        if ($bookingId !== null && $candidate === $bookingId) {
            return true;
        }

        return str_ends_with($candidate, '_bkn') && ! str_ends_with($candidate, '_bko');
    }

    private function resolveBookingOrderIdFromApi(?string $bookingId): ?string
    {
        if ($bookingId === null) {
            return null;
        }

        return $this->extractBookingOrderId(
            $this->sandbox->fetchBookingOrdersByBookingId($bookingId),
            $bookingId,
        );
    }

    /** @param array<string, mixed>|list<mixed> $response */
    private function extractBookingOrderId(array $response, string $bookingId): ?string
    {
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
                return $bookingOrderId;
            }
        }

        return null;
    }

    private function persistBookingOrderId(Xs2SandboxTestOrder $order, ?string $bookingOrderId): void
    {
        if ($bookingOrderId === null) {
            throw new \RuntimeException(sprintf(
                'Could not resolve XS2 bookingorder_id for booking %s.',
                $order->xs2_booking_id ?? 'unknown',
            ));
        }

        if ($order->xs2_bookingorder_id !== $bookingOrderId) {
            $order->fill(['xs2_bookingorder_id' => $bookingOrderId])->save();
        }
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
