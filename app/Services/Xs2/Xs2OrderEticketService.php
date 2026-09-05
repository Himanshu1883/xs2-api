<?php

namespace App\Services\Xs2;

use App\Exceptions\Integrations\Xs2RequestException;
use App\Models\Xs2Order;
use Throwable;

/**
 * Fetch an XS2 e-ticket for an xs2_order using the same download endpoint as sandbox test orders.
 */
class Xs2OrderEticketService
{
    public function __construct(
        private readonly Xs2SandboxService $sandbox,
        private readonly Xs2Client $client,
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
    public function fetchTicket(Xs2Order $xs2Order): array
    {
        $xs2Order->loadMissing('attendees');

        if ($xs2Order->attendees->isEmpty()) {
            throw new \RuntimeException('Move attendee details onto this XS2 order before getting a ticket.');
        }

        $bookingOrderId = $this->nullableString($xs2Order->xs2_bookingorder_id)
            ?? $this->nullableString($xs2Order->external_order_id);
        $bookingId = $this->nullableString($xs2Order->xs2_booking_id);

        if ($bookingOrderId === null && $bookingId === null) {
            throw new \RuntimeException('This XS2 order is missing a bookingorder_id, so a ticket cannot be fetched yet.');
        }

        $requestPayload = [
            'bookingorder_id' => $bookingOrderId,
            'booking_id' => $bookingId,
            'requested_at' => now()->toIso8601String(),
        ];

        try {
            $bookingPayload = $this->resolveBookingPayload($xs2Order, $bookingOrderId, $bookingId);
            $targets = $this->collectEticketTargets(
                $bookingPayload,
                $bookingOrderId ?? $bookingId ?? '',
                $this->nullableString($xs2Order->external_ticket_id),
            );

            if ($targets === []) {
                $logisticStatus = $this->nullableString($bookingPayload['logistic_status'] ?? null);
                $message = $logisticStatus !== null && $logisticStatus !== 'completed'
                    ? sprintf('E-ticket is not ready yet (logistic_status=%s).', $logisticStatus)
                    : 'No downloadable e-ticket links were found in the XS2 booking response.';

                $xs2Order->fill([
                    'xs2_eticket_request' => $requestPayload,
                    'xs2_eticket_response' => [
                        'success' => false,
                        'error' => $message,
                        'logistic_status' => $logisticStatus,
                        'fetched_at' => now()->toIso8601String(),
                    ],
                    'eticket_fetched_at' => null,
                    'eticket_error' => $message,
                ])->save();

                throw new \RuntimeException($message);
            }

            $target = $targets[0];
            $requestPayload = [
                ...$requestPayload,
                'bookingorder_id' => $target['bookingorder_id'],
                'orderitem_id' => $target['orderitem_id'],
                'download_link' => $target['download_link'],
            ];

            if ($target['distribution_channel'] !== null && $target['distribution_channel'] !== 'xs2event') {
                throw new \RuntimeException(sprintf(
                    'E-ticket download is not available for distribution channel "%s".',
                    $target['distribution_channel'],
                ));
            }

            $response = $this->downloadPdf(
                $xs2Order,
                $target['bookingorder_id'],
                $target['orderitem_id'],
                $target['download_link'],
            );

            $filename = $this->resolveFilename($target['download_link'], $xs2Order);
            $byteSize = strlen($response['body']);

            $xs2Order->fill([
                'xs2_eticket_request' => $requestPayload,
                'xs2_eticket_response' => [
                    'success' => true,
                    'filename' => $filename,
                    'byte_size' => $byteSize,
                    'content_type' => $response['content_type'] ?? 'application/pdf',
                    'http_status' => $response['status'] ?? 200,
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
                'body' => $response['body'],
                'content_type' => $response['content_type'] ?? 'application/pdf',
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

    /** @return array<string, mixed> */
    private function resolveBookingPayload(Xs2Order $xs2Order, ?string $bookingOrderId, ?string $bookingId): array
    {
        if ($bookingOrderId !== null) {
            try {
                return $this->fetchBookingOrder($xs2Order, $bookingOrderId);
            } catch (Xs2RequestException $exception) {
                if ($exception->status !== 404 || $bookingId === null) {
                    throw $exception;
                }
            }
        }

        if ($bookingId === null) {
            throw new \RuntimeException('Could not load the XS2 booking order for this ticket.');
        }

        return $this->fetchBooking($xs2Order, $bookingId);
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
     * @return array{status: int, body: string, content_type: string|null}
     */
    private function downloadPdf(Xs2Order $xs2Order, string $bookingOrderId, string $orderItemId, string $downloadLink): array
    {
        if ((bool) $xs2Order->is_sandbox) {
            $response = $this->sandbox->downloadEticketPdf($bookingOrderId, $orderItemId, $downloadLink);

            return [
                'status' => $response['status'],
                'body' => $response['body'],
                'content_type' => $this->firstHeaderValue($response['headers'], 'Content-Type'),
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
            'content_type' => $this->firstHeaderValue($response['headers'], 'Content-Type'),
        ];
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

    private function resolveFilename(string $downloadLink, Xs2Order $order): string
    {
        $base = basename($downloadLink);

        if ($base !== '' && $base !== '/' && str_contains($base, '.')) {
            return $base;
        }

        return 'xs2-order-'.$order->id.'-eticket.pdf';
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
