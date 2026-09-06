<?php

namespace App\Services\Xs2;

use App\Exceptions\Integrations\Xs2RequestException;
use App\Models\Xs2Order;
use App\Support\Xs2BookingOrderIdentity;
use Throwable;

/**
 * Fetch an XS2 e-ticket for an xs2_order using the same download endpoint as sandbox test orders.
 */
class Xs2OrderEticketService
{
    public function __construct(
        private readonly Xs2SandboxService $sandbox,
        private readonly Xs2Client $client,
        private readonly SbOrderXs2GuestDataSyncService $guestDataSync,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     order: Xs2Order,
     *     filename: string|null,
     *     byte_size: int|null,
     *     body: string,
     *     content_type: string
     * }
     */
    public function fetchTicket(Xs2Order $xs2Order, ?string $format = null): array
    {
        $ticketFormat = $format === null
            ? null
            : $this->normalizeTicketFormat($format);

        if (Xs2BookingOrderIdentity::orderHasPendingBookingOrderId($xs2Order)) {
            throw new \RuntimeException(Xs2BookingOrderIdentity::pendingTicketMessage($xs2Order));
        }

        $bookingOrderId = Xs2BookingOrderIdentity::resolvedBookingOrderId(
            $this->nullableString($xs2Order->xs2_bookingorder_id),
            $this->nullableString($xs2Order->external_order_id),
        );
        $bookingId = $this->nullableString($xs2Order->xs2_booking_id);

        if ($bookingOrderId === null && $bookingId === null) {
            throw new \RuntimeException('This XS2 order is missing a bookingorder_id, so a ticket cannot be fetched yet.');
        }

        $requestPayload = [
            'bookingorder_id' => $bookingOrderId,
            'booking_id' => $bookingId,
            'format' => $ticketFormat,
            'requested_at' => now()->toIso8601String(),
        ];

        try {
            $preferredTicketId = $this->nullableString($xs2Order->external_ticket_id);
            [$bookingPayload, $targets] = $this->resolveBookingPayloadWithTargets(
                $xs2Order,
                $bookingOrderId,
                $bookingId,
                $preferredTicketId,
            );

            if ($targets === [] && $this->shouldAttemptGuestDataPush($xs2Order, $bookingPayload)) {
                $pushResult = $this->guestDataSync->pushGuestDataForXs2Order($xs2Order->fresh(['attendees', 'sbOrder.attendees']));
                $requestPayload['guest_data_push_attempted'] = true;
                $requestPayload['guest_data_push_synced'] = (bool) ($pushResult['synced'] ?? false);
                if (! ($pushResult['synced'] ?? false)) {
                    $requestPayload['guest_data_push_error'] = $this->nullableString(
                        $pushResult['error'] ?? $pushResult['reason'] ?? null,
                    );
                }

                if ($pushResult['synced'] ?? false) {
                    [$bookingPayload, $targets] = $this->resolveBookingPayloadWithTargets(
                        $xs2Order->fresh(['attendees', 'sbOrder']),
                        $bookingOrderId,
                        $bookingId,
                        $preferredTicketId,
                    );
                }
            }

            $ticketFormat ??= $this->inferTicketFormat($xs2Order, $bookingPayload);
            $requestPayload['format'] = $ticketFormat;

            if ($targets === [] && ($ticketFormat === null || $ticketFormat === 'pdf' || $ticketFormat === 'mobile')) {
                $zipDownload = $this->tryDownloadZipArchive($xs2Order, $bookingPayload, $bookingOrderId ?? $bookingId);
                if ($zipDownload !== null) {
                    $byteSize = strlen($zipDownload['body']);
                    $xs2Order->fill([
                        'xs2_eticket_request' => [
                            ...$requestPayload,
                            'download_mode' => 'zip',
                        ],
                        'xs2_eticket_response' => [
                            'success' => true,
                            'filename' => $zipDownload['filename'],
                            'byte_size' => $byteSize,
                            'content_type' => 'application/zip',
                            'http_status' => $zipDownload['status'] ?? 200,
                            'fetched_at' => now()->toIso8601String(),
                        ],
                        'eticket_fetched_at' => now(),
                        'eticket_error' => null,
                    ])->save();

                    return [
                        'ok' => true,
                        'message' => sprintf('Ticket fetched (%s, %s).', $zipDownload['filename'], $this->formatBytes($byteSize)),
                        'order' => $xs2Order->fresh(['attendees', 'sbOrder', 'latestGuestDataLog']),
                        'filename' => $zipDownload['filename'],
                        'byte_size' => $byteSize,
                        'body' => $zipDownload['body'],
                        'content_type' => 'application/zip',
                    ];
                }
            }

            $downloadableTargets = array_values(array_filter(
                $targets,
                fn (array $target): bool => $target['distribution_channel'] === null
                    || $target['distribution_channel'] === 'xs2event',
            ));

            if ($ticketFormat !== null) {
                $downloadableTargets = $this->filterTargetsByFormat($downloadableTargets, $ticketFormat);
            }

            if ($downloadableTargets === []) {
                if ($targets !== [] && $ticketFormat === null) {
                    $blockedChannel = $targets[0]['distribution_channel'] ?? 'unknown';
                    throw new \RuntimeException(sprintf(
                        'E-ticket download is not available for distribution channel "%s".',
                        $blockedChannel,
                    ));
                }

                $activationLink = $this->firstExternalActivationLink($bookingPayload);
                if ($activationLink !== null) {
                    throw new \RuntimeException(sprintf(
                        'This ticket must be activated externally before download. Activation link: %s',
                        $activationLink,
                    ));
                }

                $logisticStatus = $this->nullableString($bookingPayload['logistic_status'] ?? null);
                $guestDataStatus = $this->nullableString($bookingPayload['guestdata_status'] ?? null);
                $message = $this->missingTicketMessage(
                    $xs2Order,
                    $ticketFormat,
                    $logisticStatus,
                    $guestDataStatus,
                    $targets,
                    $this->inferTicketFormat($xs2Order, $bookingPayload),
                );

                $xs2Order->fill([
                    'xs2_eticket_request' => $requestPayload,
                    'xs2_eticket_response' => [
                        'success' => false,
                        'error' => $message,
                        'logistic_status' => $logisticStatus,
                        'guestdata_status' => $guestDataStatus,
                        'debug' => $this->buildMissingLinkDebug(
                            $bookingPayload,
                            $preferredTicketId,
                            $bookingOrderId,
                            $bookingId,
                        ),
                        'fetched_at' => now()->toIso8601String(),
                    ],
                    'eticket_fetched_at' => null,
                    'eticket_error' => $message,
                ])->save();

                throw new \RuntimeException($message);
            }

            $requestPayload = [
                ...$requestPayload,
                'bookingorder_id' => $downloadableTargets[0]['bookingorder_id'],
                'orderitem_id' => $downloadableTargets[0]['orderitem_id'],
                'download_link' => $downloadableTargets[0]['download_link'],
                'download_targets' => array_map(
                    fn (array $target): array => [
                        'orderitem_id' => $target['orderitem_id'],
                        'download_link' => $target['download_link'],
                        'type_ticket' => $target['type_ticket'],
                    ],
                    $downloadableTargets,
                ),
            ];

            $download = $this->downloadTargets($xs2Order, $downloadableTargets);
            $filename = $download['filename'];
            $byteSize = strlen($download['body']);

            $xs2Order->fill([
                'xs2_eticket_request' => $requestPayload,
                'xs2_eticket_response' => [
                    'success' => true,
                    'filename' => $filename,
                    'byte_size' => $byteSize,
                    'content_type' => $download['content_type'] ?? 'application/pdf',
                    'http_status' => $download['status'] ?? 200,
                    'fetched_at' => now()->toIso8601String(),
                ],
                'eticket_fetched_at' => now(),
                'eticket_error' => null,
            ])->save();

            return [
                'ok' => true,
                'message' => sprintf('Ticket fetched (%s, %s).', $filename, $this->formatBytes($byteSize)),
                'order' => $xs2Order->fresh(['attendees', 'sbOrder', 'latestGuestDataLog']),
                'filename' => $filename,
                'byte_size' => $byteSize,
                'body' => $download['body'],
                'content_type' => $download['content_type'] ?? 'application/pdf',
            ];
        } catch (Throwable $exception) {
            if (! $xs2Order->wasChanged() && $xs2Order->eticket_error !== $exception->getMessage()) {
                $xs2Order->fill([
                    'xs2_eticket_request' => $requestPayload,
                    'xs2_eticket_response' => [
                        'success' => false,
                        'error' => mb_substr($exception->getMessage(), 0, 2000),
                        'fetched_at' => now()->toIso8601String(),
                    ],
                    'eticket_error' => mb_substr($exception->getMessage(), 0, 2000),
                ])->save();
            }

            throw $exception instanceof \RuntimeException
                ? $exception
                : new \RuntimeException($exception->getMessage(), 0, $exception);
        }
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function resolveBookingPayloadWithTargets(
        Xs2Order $xs2Order,
        ?string $bookingOrderId,
        ?string $bookingId,
        ?string $preferredTicketId,
    ): array {
        $candidates = [];

        try {
            $candidates[] = $this->normalizeBookingPayload(
                $this->resolveBookingPayload($xs2Order, $bookingOrderId, $bookingId),
                $bookingOrderId,
                $bookingId,
            );
        } catch (Throwable) {
            // Fall through to supplemental payload sources below.
        }

        if ($bookingOrderId !== null) {
            $listByBookingOrderId = $this->fetchBookingOrderFromListByBookingOrderId($xs2Order, $bookingOrderId);
            if ($listByBookingOrderId !== null) {
                $candidates[] = $listByBookingOrderId;
            }
        }

        if ($bookingId !== null) {
            $listByBookingId = $this->fetchBookingOrderFromList($xs2Order, $bookingId, $bookingOrderId);
            if ($listByBookingId !== null) {
                $candidates[] = $listByBookingId;
            }

            if ($bookingOrderId !== null) {
                $supplementalPayload = $this->fetchSupplementalBookingOrderPayload(
                    $xs2Order,
                    $bookingId,
                    $bookingOrderId,
                );
                if ($supplementalPayload !== null) {
                    $candidates[] = $supplementalPayload;
                }
            }
        }

        $storedPayload = $this->storedRawBookingPayload($xs2Order, $bookingOrderId, $bookingId);
        if ($storedPayload !== null) {
            $candidates[] = $storedPayload;
        }

        if ($candidates === []) {
            throw new \RuntimeException('Could not load the XS2 booking order for this ticket.');
        }

        return $this->selectBestBookingPayload(
            $candidates,
            $bookingOrderId ?? $bookingId ?? '',
            $preferredTicketId,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function selectBestBookingPayload(
        array $candidates,
        string $fallbackBookingOrderId,
        ?string $preferredTicketId,
    ): array {
        $bestPayload = $candidates[0];
        $bestTargets = [];
        $bestScore = -1;

        foreach ($candidates as $candidate) {
            $targets = $this->collectEticketTargets($candidate, $fallbackBookingOrderId, $preferredTicketId);
            $score = $this->payloadFulfillmentScore($candidate, $targets);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPayload = $candidate;
                $bestTargets = $targets;
            }
        }

        return [$bestPayload, $bestTargets];
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     */
    private function payloadFulfillmentScore(array $payload, array $targets): int
    {
        $score = count($targets) * 100;

        foreach ($this->resolveOrderItems($payload) as $item) {
            if ($this->nullableString($item['orderitem_id'] ?? $item['order_item_id'] ?? null) !== null) {
                $score += 10;
            }
            if ($this->downloadLinksForItem($item) !== []) {
                $score += 20;
            }
            if ($this->nullableString($item['external_activation_link'] ?? null) !== null) {
                $score += 5;
            }
        }

        if ($this->nullableString($payload['zip_sha'] ?? null) !== null) {
            $score += 5;
        }

        if ($this->nullableString($payload['bookingorder_id'] ?? null) !== null) {
            $score += 1;
        }

        return $score;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedRawBookingPayload(
        Xs2Order $xs2Order,
        ?string $bookingOrderId,
        ?string $bookingId,
    ): ?array {
        $rawPayload = $xs2Order->raw_payload;
        if (! is_array($rawPayload) || $rawPayload === []) {
            return null;
        }

        $normalized = $this->normalizeBookingPayload($rawPayload, $bookingOrderId, $bookingId);
        if ($this->resolveOrderItems($normalized) === []) {
            return null;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchBookingOrderFromListByBookingOrderId(
        Xs2Order $xs2Order,
        string $bookingOrderId,
    ): ?array {
        try {
            $response = (bool) $xs2Order->is_sandbox
                ? $this->sandbox->fetchBookingOrders(['bookingorder_id' => $bookingOrderId])
                : $this->client->fetchBookingOrders(['bookingorder_id' => $bookingOrderId]);
        } catch (Throwable) {
            return null;
        }

        $bookingOrders = $response['bookingorders'] ?? null;
        if (! is_array($bookingOrders)) {
            if ($this->nullableString($response['bookingorder_id'] ?? null) !== null) {
                return $this->injectBookingOrderId($response, $bookingOrderId);
            }

            return null;
        }

        foreach ($bookingOrders as $bookingOrder) {
            if (! is_array($bookingOrder)) {
                continue;
            }

            $candidateBookingOrderId = $this->nullableString($bookingOrder['bookingorder_id'] ?? null);
            if ($candidateBookingOrderId !== null && $candidateBookingOrderId !== $bookingOrderId) {
                continue;
            }

            return $this->injectBookingOrderId($bookingOrder, $bookingOrderId);
        }

        return null;
    }

    /** @param array<string, mixed> $bookingPayload */
    private function shouldAttemptGuestDataPush(Xs2Order $xs2Order, array $bookingPayload): bool
    {
        if ($xs2Order->guest_data_synced_at !== null) {
            return false;
        }

        $xs2Order->loadMissing(['attendees', 'sbOrder.attendees']);
        if ($xs2Order->attendees->isEmpty() && ($xs2Order->sbOrder?->attendees?->isNotEmpty() ?? false)) {
            return true;
        }

        if ($xs2Order->attendees->isEmpty()) {
            return false;
        }

        if ($this->guestDataStatusNeedsDistributor($bookingPayload)) {
            return true;
        }

        return $this->resolveOrderItems($bookingPayload) !== []
            && ! $this->itemsHaveDownloadableLinks($this->resolveOrderItems($bookingPayload));
    }

    /** @param array<string, mixed> $bookingPayload */
    private function guestDataStatusNeedsDistributor(array $bookingPayload): bool
    {
        $status = strtolower((string) ($bookingPayload['guestdata_status'] ?? ''));

        return str_contains($status, 'waiting')
            || str_contains($status, 'missing')
            || str_contains($status, 'required');
    }

    /** @return array<string, mixed> */
    private function resolveBookingPayload(Xs2Order $xs2Order, ?string $bookingOrderId, ?string $bookingId): array
    {
        if ($bookingOrderId !== null) {
            try {
                return $this->normalizeBookingPayload(
                    $this->fetchBookingOrder($xs2Order, $bookingOrderId),
                    $bookingOrderId,
                );
            } catch (Xs2RequestException $exception) {
                if ($exception->status !== 404 || $bookingId === null) {
                    throw $exception;
                }
            }
        }

        if ($bookingId === null) {
            throw new \RuntimeException('Could not load the XS2 booking order for this ticket.');
        }

        return $this->normalizeBookingPayload(
            $this->fetchBooking($xs2Order, $bookingId),
            $bookingOrderId,
            $bookingId,
        );
    }

    /**
     * XS2 may return booking-order items on the parent booking payload even when the
     * bookingorder detail response has no download links yet.
     *
     * @return array<string, mixed>|null
     */
    private function fetchSupplementalBookingOrderPayload(
        Xs2Order $xs2Order,
        string $bookingId,
        string $bookingOrderId,
    ): ?array {
        try {
            $bookingPayload = $this->normalizeBookingPayload(
                $this->fetchBooking($xs2Order, $bookingId),
                $bookingOrderId,
                $bookingId,
            );
        } catch (Throwable) {
            return null;
        }

        if ($this->resolveOrderItems($bookingPayload) === []) {
            return null;
        }

        return $bookingPayload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeBookingPayload(array $payload, ?string $bookingOrderId = null, ?string $bookingId = null): array
    {
        foreach (['bookingorder', 'data', 'result'] as $wrapperKey) {
            $wrapped = $payload[$wrapperKey] ?? null;
            if (is_array($wrapped)) {
                $payload = $wrapped;
                break;
            }
        }

        $topLevelItems = $this->resolveOrderItems($payload);
        if ($topLevelItems !== [] && $this->itemsHaveDownloadableLinks($topLevelItems)) {
            return $this->injectBookingOrderId($payload, $bookingOrderId);
        }

        $bookingOrders = $payload['bookingorders'] ?? null;
        if (! is_array($bookingOrders)) {
            return $this->injectBookingOrderId($payload, $bookingOrderId);
        }

        $matched = null;
        $matchedWithDownloads = null;
        foreach ($bookingOrders as $bookingOrder) {
            if (! is_array($bookingOrder)) {
                continue;
            }

            $candidateBookingOrderId = $this->nullableString($bookingOrder['bookingorder_id'] ?? null);
            if ($bookingOrderId !== null && $candidateBookingOrderId !== null && $candidateBookingOrderId !== $bookingOrderId) {
                continue;
            }

            if ($bookingId !== null) {
                $candidateBookingId = $this->nullableString($bookingOrder['booking_id'] ?? null);
                if ($candidateBookingId !== null && $candidateBookingId !== $bookingId) {
                    continue;
                }
            }

            $candidateItems = $this->resolveOrderItems($bookingOrder);
            if ($candidateItems !== [] && $this->itemsHaveDownloadableLinks($candidateItems)) {
                $matchedWithDownloads = $bookingOrder;
                break;
            }

            if ($candidateItems !== [] && $matched === null) {
                $matched = $bookingOrder;
            }

            if ($matched === null) {
                $matched = $bookingOrder;
            }
        }

        $resolved = is_array($matchedWithDownloads)
            ? $matchedWithDownloads
            : (is_array($matched) ? $matched : $payload);

        return $this->injectBookingOrderId($resolved, $bookingOrderId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function injectBookingOrderId(array $payload, ?string $bookingOrderId): array
    {
        if ($bookingOrderId !== null && $this->nullableString($payload['bookingorder_id'] ?? null) === null) {
            $payload['bookingorder_id'] = $bookingOrderId;
        }

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function itemsHaveDownloadableLinks(array $items): bool
    {
        foreach ($items as $item) {
            if ($this->downloadLinksForItem($item) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * XS2 list endpoint can include download links before the detail payload does.
     *
     * @return array<string, mixed>|null
     */
    private function fetchBookingOrderFromList(
        Xs2Order $xs2Order,
        string $bookingId,
        ?string $bookingOrderId,
    ): ?array {
        try {
            $response = (bool) $xs2Order->is_sandbox
                ? $this->sandbox->fetchBookingOrders(['booking_id' => $bookingId])
                : $this->client->fetchBookingOrdersByBookingId($bookingId);
        } catch (Throwable) {
            return null;
        }

        $bookingOrders = $response['bookingorders'] ?? null;
        if (! is_array($bookingOrders)) {
            return null;
        }

        $matched = null;
        $matchedWithDownloads = null;
        foreach ($bookingOrders as $bookingOrder) {
            if (! is_array($bookingOrder)) {
                continue;
            }

            $candidateBookingOrderId = $this->nullableString($bookingOrder['bookingorder_id'] ?? null);
            if ($bookingOrderId !== null && $candidateBookingOrderId !== null && $candidateBookingOrderId !== $bookingOrderId) {
                continue;
            }

            $items = $this->resolveOrderItems($bookingOrder);
            if ($items !== [] && $this->itemsHaveDownloadableLinks($items)) {
                $matchedWithDownloads = $bookingOrder;
                break;
            }

            if ($items !== [] && $matched === null) {
                $matched = $bookingOrder;
            }
        }

        $resolved = $matchedWithDownloads ?? $matched;

        return is_array($resolved)
            ? $this->injectBookingOrderId($resolved, $bookingOrderId)
            : null;
    }

    /**
     * @param  array<string, mixed>  $bookingPayload
     */
    private function firstExternalActivationLink(array $bookingPayload): ?string
    {
        foreach ($this->resolveOrderItems($bookingPayload) as $item) {
            foreach ([
                'external_activation_link',
                'activation_url',
                'activation_link',
            ] as $field) {
                $link = $this->nullableString($item[$field] ?? null);
                if ($link !== null) {
                    return $link;
                }
            }

            $downloadItems = $item['download_items'] ?? [];
            if (! is_array($downloadItems)) {
                continue;
            }

            foreach ($downloadItems as $downloadItem) {
                if (! is_array($downloadItem)) {
                    continue;
                }

                foreach ([
                    'external_activation_link',
                    'activation_url',
                    'activation_link',
                ] as $field) {
                    $nestedLink = $this->nullableString($downloadItem[$field] ?? null);
                    if ($nestedLink !== null) {
                        return $nestedLink;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $bookingPayload
     */
    private function inferTicketFormat(Xs2Order $xs2Order, array $bookingPayload): ?string
    {
        if ($this->sbOrderPrefersMobileTicket($xs2Order)) {
            return 'mobile';
        }

        foreach ($this->resolveOrderItems($bookingPayload) as $item) {
            $typeTicket = strtolower((string) ($item['type_ticket'] ?? $item['ticket_type'] ?? ''));
            if ($typeTicket === 'appticket' || str_contains($typeTicket, 'mobile')) {
                return 'mobile';
            }
        }

        return null;
    }

    private function sbOrderPrefersMobileTicket(Xs2Order $xs2Order): bool
    {
        if (! $xs2Order->relationLoaded('sbOrder')) {
            $xs2Order->loadMissing('sbOrder');
        }

        $ticketType = strtolower((string) ($xs2Order->sbOrder?->ticket_types_name ?? ''));

        return str_contains($ticketType, 'mobile') || $ticketType === 'appticket';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function resolveOrderItems(array $payload): array
    {
        foreach (['items', 'orderitems', 'order_items', 'tickets'] as $key) {
            $items = $payload[$key] ?? null;
            if (! is_array($items)) {
                continue;
            }

            return array_values(array_filter($items, is_array(...)));
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $bookingPayload
     * @return array{status: int, body: string, filename: string}|null
     */
    private function tryDownloadZipArchive(Xs2Order $xs2Order, array $bookingPayload, ?string $bookingOrderId): ?array
    {
        $zipSha = $this->nullableString($bookingPayload['zip_sha'] ?? null);
        $resolvedBookingOrderId = $this->nullableString(
            $bookingPayload['bookingorder_id']
            ?? $bookingOrderId
            ?? $xs2Order->xs2_bookingorder_id
            ?? $xs2Order->external_order_id,
        );

        if ($zipSha === null || $resolvedBookingOrderId === null) {
            return null;
        }

        if ((bool) $xs2Order->is_sandbox || ! $this->client->isOrdersConfigured()) {
            return null;
        }

        $zipUrlResponse = $this->client->resolveEticketZipDownloadUrlViaOrdersApi($resolvedBookingOrderId);
        $zipResponse = $this->client->downloadEticketZipFromUrl($zipUrlResponse['download_url']);

        return [
            'status' => $zipResponse['status'],
            'body' => $zipResponse['body'],
            'filename' => 'xs2-order-'.$xs2Order->id.'-tickets.zip',
        ];
    }

    /**
     * @param  array<string, mixed>  $bookingPayload
     * @return array<string, mixed>
     */
    private function buildMissingLinkDebug(
        array $bookingPayload,
        ?string $preferredTicketId,
        ?string $resolvedBookingOrderId = null,
        ?string $bookingId = null,
    ): array {
        $items = $this->resolveOrderItems($bookingPayload);

        return [
            'bookingorder_id' => $this->nullableString(
                $bookingPayload['bookingorder_id']
                ?? $resolvedBookingOrderId,
            ),
            'resolved_bookingorder_id' => $resolvedBookingOrderId,
            'booking_id' => $this->nullableString($bookingPayload['booking_id'] ?? $bookingId),
            'guestdata_status' => $this->nullableString($bookingPayload['guestdata_status'] ?? null),
            'item_count' => count($items),
            'preferred_ticket_id' => $preferredTicketId,
            'zip_sha' => $this->nullableString($bookingPayload['zip_sha'] ?? null),
            'items' => array_map(function (array $item): array {
                return [
                    'orderitem_id' => $this->nullableString($item['orderitem_id'] ?? $item['order_item_id'] ?? null),
                    'ticket_id' => $this->nullableString($item['ticket_id'] ?? null),
                    'type_ticket' => $this->nullableString($item['type_ticket'] ?? $item['ticket_type'] ?? null),
                    'distribution_channel' => $this->nullableString($item['distribution_channel'] ?? null),
                    'download_link' => $this->nullableString($item['download_link'] ?? null),
                    'download_item_count' => is_array($item['download_items'] ?? null)
                        ? count($item['download_items'])
                        : 0,
                    'has_external_activation_link' => $this->nullableString($item['external_activation_link'] ?? null) !== null,
                ];
            }, $items),
        ];
    }

    /** @return array<string, mixed> */
    private function fetchBookingOrder(Xs2Order $xs2Order, string $bookingOrderId): array
    {
        if ((bool) $xs2Order->is_sandbox) {
            return $this->sandbox->fetchBookingOrder($bookingOrderId);
        }

        if (! $this->client->isOrdersConfigured()) {
            throw new \RuntimeException(
                'XS2 production order API is not configured. Set XS2_BASE_URL and XS2_API_KEY in .env (or Admin → API Config).',
            );
        }

        return $this->client->getBookingOrderViaOrdersApi($bookingOrderId);
    }

    /** @return array<string, mixed> */
    private function fetchBooking(Xs2Order $xs2Order, string $bookingId): array
    {
        if ((bool) $xs2Order->is_sandbox) {
            return $this->sandbox->fetchBooking($bookingId);
        }

        if (! $this->client->isOrdersConfigured()) {
            throw new \RuntimeException(
                'XS2 production order API is not configured. Set XS2_BASE_URL and XS2_API_KEY in .env (or Admin → API Config).',
            );
        }

        return $this->client->getBookingViaOrdersApi($bookingId);
    }

    /**
     * @param  list<array{
     *     bookingorder_id: string,
     *     orderitem_id: string,
     *     download_link: string,
     *     distribution_channel: string|null,
     *     ticket_id: string|null,
     *     type_ticket: string|null
     * }>  $targets
     * @return array{status: int, body: string, content_type: string|null, filename: string}
     */
    private function downloadTargets(Xs2Order $xs2Order, array $targets): array
    {
        if ($targets === []) {
            throw new \RuntimeException('No downloadable e-ticket links were found in the XS2 booking response.');
        }

        if (count($targets) === 1) {
            $target = $targets[0];
            $response = $this->downloadEticket(
                $xs2Order,
                $target['bookingorder_id'],
                $target['orderitem_id'],
                $target['download_link'],
            );
            $contentType = $this->firstHeaderValue($response['headers'], 'Content-Type')
                ?? $this->guessContentType($target['download_link']);

            return [
                'status' => $response['status'],
                'body' => $response['body'],
                'content_type' => $contentType,
                'filename' => $this->resolveFilename($target['download_link'], $xs2Order, $contentType),
            ];
        }

        $zip = new \ZipArchive;
        $tmpPath = tempnam(sys_get_temp_dir(), 'xs2-etickets-');
        if ($tmpPath === false) {
            throw new \RuntimeException('Could not create a temporary file for the XS2 ticket download.');
        }

        $opened = $zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            @unlink($tmpPath);

            throw new \RuntimeException('Could not create a ZIP archive for the XS2 ticket download.');
        }

        $usedNames = [];
        foreach ($targets as $index => $target) {
            $response = $this->downloadEticket(
                $xs2Order,
                $target['bookingorder_id'],
                $target['orderitem_id'],
                $target['download_link'],
            );
            $contentType = $this->firstHeaderValue($response['headers'], 'Content-Type')
                ?? $this->guessContentType($target['download_link']);
            $filename = $this->resolveFilename($target['download_link'], $xs2Order, $contentType, $index + 1);
            while (isset($usedNames[$filename])) {
                $filename = $this->resolveFilename($target['download_link'], $xs2Order, $contentType, $index + 1, true);
                break;
            }
            $usedNames[$filename] = true;
            $zip->addFromString($filename, $response['body']);
        }

        $zip->close();
        $body = (string) file_get_contents($tmpPath);
        @unlink($tmpPath);

        return [
            'status' => 200,
            'body' => $body,
            'content_type' => 'application/zip',
            'filename' => 'xs2-order-'.$xs2Order->id.'-tickets.zip',
        ];
    }

    /**
     * @return array{status: int, body: string, headers: array<string, list<string>>}
     */
    private function downloadEticket(Xs2Order $xs2Order, string $bookingOrderId, string $orderItemId, string $downloadLink): array
    {
        if ((bool) $xs2Order->is_sandbox) {
            $response = $this->sandbox->downloadEticketPdf($bookingOrderId, $orderItemId, $downloadLink);

            return [
                'status' => $response['status'],
                'body' => $response['body'],
                'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
            ];
        }

        if (! $this->client->isOrdersConfigured()) {
            throw new \RuntimeException(
                'XS2 production order API is not configured. Set XS2_BASE_URL and XS2_API_KEY in .env (or Admin → API Config).',
            );
        }

        $response = $this->client->downloadEticketPdfViaOrdersApi($bookingOrderId, $orderItemId, $downloadLink);

        return [
            'status' => $response['status'],
            'body' => $response['body'],
            'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
        ];
    }

    /**
     * @return list<array{
     *     bookingorder_id: string,
     *     orderitem_id: string,
     *     download_link: string,
     *     distribution_channel: string|null,
     *     ticket_id: string|null,
     *     type_ticket: string|null
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

        $items = $this->resolveOrderItems($bookingPayload);
        if ($items === []) {
            return [];
        }

        $targets = $this->buildEticketTargets($items, $bookingOrderId, $preferredTicketId);
        if ($targets !== [] || $preferredTicketId === null) {
            return $this->sortEticketTargets($targets);
        }

        return $this->sortEticketTargets($this->buildEticketTargets($items, $bookingOrderId, null));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{
     *     bookingorder_id: string,
     *     orderitem_id: string,
     *     download_link: string,
     *     distribution_channel: string|null,
     *     ticket_id: string|null,
     *     type_ticket: string|null
     * }>
     */
    private function buildEticketTargets(array $items, string $bookingOrderId, ?string $preferredTicketId): array
    {
        $targets = [];

        foreach ($items as $item) {
            $ticketId = $this->nullableString($item['ticket_id'] ?? null);
            if ($preferredTicketId !== null && $ticketId !== null && $ticketId !== $preferredTicketId) {
                continue;
            }

            $orderItemId = $this->nullableString(
                $item['orderitem_id']
                ?? $item['order_item_id']
                ?? $item['downloaditem_id']
                ?? null,
            );
            $distributionChannel = $this->nullableString($item['distribution_channel'] ?? null);
            $typeTicket = $this->nullableString($item['type_ticket'] ?? $item['ticket_type'] ?? null);

            foreach ($this->downloadLinksForItem($item) as $downloadLink) {
                if ($orderItemId === null) {
                    continue;
                }

                $targets[] = [
                    'bookingorder_id' => $bookingOrderId,
                    'orderitem_id' => $orderItemId,
                    'download_link' => $downloadLink,
                    'distribution_channel' => $distributionChannel,
                    'ticket_id' => $ticketId,
                    'type_ticket' => $typeTicket,
                ];
            }
        }

        return $targets;
    }

    /**
     * Prefer mobile/appticket PKPASS files first, then PDFs, so Get ticket works for both formats.
     *
     * @param  list<array{
     *     bookingorder_id: string,
     *     orderitem_id: string,
     *     download_link: string,
     *     distribution_channel: string|null,
     *     ticket_id: string|null,
     *     type_ticket: string|null
     * }>  $targets
     * @return list<array{
     *     bookingorder_id: string,
     *     orderitem_id: string,
     *     download_link: string,
     *     distribution_channel: string|null,
     *     ticket_id: string|null,
     *     type_ticket: string|null
     * }>
     */
    private function sortEticketTargets(array $targets): array
    {
        usort($targets, function (array $left, array $right): int {
            return $this->eticketTargetPriority($right) <=> $this->eticketTargetPriority($left);
        });

        return $targets;
    }

    private function eticketTargetPriority(array $target): int
    {
        $link = strtolower($target['download_link']);
        $typeTicket = strtolower((string) ($target['type_ticket'] ?? ''));

        if (str_ends_with($link, '.pkpass') || $typeTicket === 'appticket') {
            return 3;
        }

        if (str_ends_with($link, '.pdf') || $typeTicket === 'eticket') {
            return 2;
        }

        return 1;
    }

    /**
     * @param  list<array{
     *     bookingorder_id: string,
     *     orderitem_id: string,
     *     download_link: string,
     *     distribution_channel: string|null,
     *     ticket_id: string|null,
     *     type_ticket: string|null
     * }>  $targets
     * @return list<array{
     *     bookingorder_id: string,
     *     orderitem_id: string,
     *     download_link: string,
     *     distribution_channel: string|null,
     *     ticket_id: string|null,
     *     type_ticket: string|null
     * }>
     */
    private function filterTargetsByFormat(array $targets, string $format): array
    {
        return array_values(array_filter(
            $targets,
            fn (array $target): bool => $this->targetMatchesFormat($target, $format),
        ));
    }

    /**
     * @param  array{
     *     bookingorder_id: string,
     *     orderitem_id: string,
     *     download_link: string,
     *     distribution_channel: string|null,
     *     ticket_id: string|null,
     *     type_ticket: string|null
     * }  $target
     */
    private function targetMatchesFormat(array $target, string $format): bool
    {
        if ($format === 'mobile') {
            return $this->isMobileTarget($target);
        }

        return $this->isPdfTarget($target);
    }

    /** @param array{download_link: string, type_ticket: string|null} $target */
    private function isMobileTarget(array $target): bool
    {
        $link = strtolower($target['download_link']);
        $typeTicket = strtolower((string) ($target['type_ticket'] ?? ''));

        return str_ends_with($link, '.pkpass') || $typeTicket === 'appticket';
    }

    /** @param array{download_link: string, type_ticket: string|null} $target */
    private function isPdfTarget(array $target): bool
    {
        if ($this->isMobileTarget($target)) {
            return false;
        }

        $link = strtolower($target['download_link']);
        $typeTicket = strtolower((string) ($target['type_ticket'] ?? ''));

        return str_ends_with($link, '.pdf')
            || in_array($typeTicket, ['eticket', 'etickets', 'e-tickets', 'e_tickets'], true);
    }

    private function normalizeTicketFormat(?string $format): ?string
    {
        if ($format === null) {
            return null;
        }

        $normalized = strtolower(trim($format));

        return match ($normalized) {
            'pdf', 'eticket', 'e-ticket', 'e_tickets', 'e-tickets', 'etickets' => 'pdf',
            'mobile', 'appticket', 'pkpass' => 'mobile',
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported ticket format "%s". Use pdf, eticket, mobile, or appticket.',
                $format,
            )),
        };
    }

    /**
     * @param  list<array{
     *     bookingorder_id: string,
     *     orderitem_id: string,
     *     download_link: string,
     *     distribution_channel: string|null,
     *     ticket_id: string|null,
     *     type_ticket: string|null
     * }>  $targets
     */
    private function missingTicketMessage(
        Xs2Order $xs2Order,
        ?string $format,
        ?string $logisticStatus,
        ?string $guestDataStatus,
        array $targets = [],
        ?string $inferredFormat = null,
    ): string {
        if ($logisticStatus !== null && $logisticStatus !== 'completed') {
            return sprintf('E-ticket is not ready yet (logistic_status=%s).', $logisticStatus);
        }

        if ($format === 'pdf' && $this->filterTargetsByFormat($targets, 'mobile') !== []) {
            return 'No PDF e-ticket was found in the XS2 booking response. This order has a mobile wallet pass — use Get ticket → Mobile (PKPASS).';
        }

        if ($format === 'mobile' && $this->filterTargetsByFormat($targets, 'pdf') !== []) {
            return 'No mobile wallet pass was found in the XS2 booking response. This order has a PDF e-ticket — use Get ticket → E-ticket (PDF).';
        }

        if ($format === 'pdf' && $inferredFormat === 'mobile') {
            return 'No PDF e-ticket was found in the XS2 booking response. This is a mobile ticket order — use Get ticket → Mobile (PKPASS).';
        }

        if (
            $xs2Order->guest_data_synced_at === null
            && (
                $this->guestDataStatusNeedsDistributor(['guestdata_status' => $guestDataStatus])
                || ($guestDataStatus !== null && $guestDataStatus !== '' && $guestDataStatus !== 'completed')
            )
        ) {
            return 'Guest data has not been pushed to XS2 yet. Push attendee details to the XS2 guest-data API, then retry Get ticket.';
        }

        if ($xs2Order->guest_data_synced_at === null) {
            $xs2Order->loadMissing(['attendees', 'sbOrder.attendees']);
            if ($xs2Order->attendees->isNotEmpty() || ($xs2Order->sbOrder?->attendees?->isNotEmpty() ?? false)) {
                return 'XS2 returned no downloadable ticket links yet. Push attendee details to the XS2 guest-data API first, then retry Get ticket.';
            }
        }

        return match ($format) {
            'pdf' => 'No PDF e-ticket was found in the XS2 booking response.',
            'mobile' => 'No mobile wallet pass was found in the XS2 booking response.',
            default => 'No downloadable e-ticket links were found in the XS2 booking response.',
        };
    }

    /** @param array<string, mixed> $item @return list<string> */
    private function downloadLinksForItem(array $item): array
    {
        $links = [];

        foreach ([
            'download_link',
            'download_url',
            'pkpass_link',
            'mobile_download_link',
            'ticket_url',
            'file_name',
            'filename',
        ] as $field) {
            $downloadLink = $this->normalizeDownloadLink($item[$field] ?? null);
            if ($downloadLink !== null) {
                $links[] = $downloadLink;
            }
        }

        $downloadItems = $item['download_items'] ?? [];
        if (is_array($downloadItems)) {
            foreach ($downloadItems as $downloadItem) {
                if (is_string($downloadItem)) {
                    $downloadLink = $this->normalizeDownloadLink($downloadItem);
                    if ($downloadLink !== null) {
                        $links[] = $downloadLink;
                    }

                    continue;
                }

                if (! is_array($downloadItem)) {
                    continue;
                }

                foreach ([
                    'download_link',
                    'download_url',
                    'pkpass_link',
                    'mobile_download_link',
                    'ticket_url',
                    'file_name',
                    'filename',
                ] as $field) {
                    $downloadLink = $this->normalizeDownloadLink($downloadItem[$field] ?? null);
                    if ($downloadLink !== null) {
                        $links[] = $downloadLink;
                    }
                }
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

    private function resolveFilename(string $downloadLink, Xs2Order $order, ?string $contentType = null, int $sequence = 1, bool $forceSequence = false): string
    {
        $base = basename($downloadLink);

        if (! $forceSequence && $base !== '' && $base !== '/' && str_contains($base, '.')) {
            return $base;
        }

        $extension = $this->extensionForContentType($contentType)
            ?? $this->extensionForDownloadLink($downloadLink)
            ?? 'pdf';

        $suffix = $sequence > 1 || $forceSequence ? '-'.$sequence : '';

        return 'xs2-order-'.$order->id.'-eticket'.$suffix.'.'.$extension;
    }

    private function extensionForDownloadLink(string $downloadLink): ?string
    {
        $extension = strtolower((string) pathinfo($downloadLink, PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : null;
    }

    private function extensionForContentType(?string $contentType): ?string
    {
        if ($contentType === null) {
            return null;
        }

        $normalized = strtolower(trim(explode(';', $contentType)[0]));

        return match ($normalized) {
            'application/pdf' => 'pdf',
            'application/vnd.apple.pkpass' => 'pkpass',
            'application/zip' => 'zip',
            default => null,
        };
    }

    private function guessContentType(string $downloadLink): string
    {
        $extension = $this->extensionForDownloadLink($downloadLink);

        return match ($extension) {
            'pkpass' => 'application/vnd.apple.pkpass',
            'zip' => 'application/zip',
            default => 'application/pdf',
        };
    }

    /** @param array<string, list<string>> $headers */
    private function firstHeaderValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header => $values) {
            if (strcasecmp((string) $header, $name) === 0 && isset($values[0]) && is_string($values[0])) {
                return $values[0];
            }
        }

        return null;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        return number_format($bytes / 1024, 1).' KB';
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
