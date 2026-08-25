<?php

namespace App\Services\Xs2;

use App\Exceptions\Integrations\Xs2ConfigurationException;
use App\Exceptions\Integrations\Xs2RequestException;
use App\Exceptions\Integrations\Xs2ResponseException;
use App\Services\Admin\ApiEnvironmentService;
use App\Support\EndpointTemplateResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Isolated XS2 HTTP client for the admin sandbox test flow.
 *
 * Reads config('xs2.sandbox.*') with integration_settings overrides for api_url/api_key,
 * plus shared endpoint templates from config('xs2.*').
 * Never uses production XS2 credentials.
 */
class Xs2SandboxService
{
    public function __construct(
        private readonly EndpointTemplateResolver $endpoints,
        private readonly Xs2ApiRequestDebugger $debugger,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->setting('api_url')) && filled($this->setting('api_key'));
    }

    /**
     * Fetch one sandbox catalog event in the raw XS2 shape expected by Xs2EventNormalizer.
     *
     * @return array<string, mixed>
     */
    public function fetchEventCatalogPayload(string $eventId): array
    {
        $this->validateConfig();

        $eventId = trim($eventId);
        if ($eventId === '') {
            throw new \InvalidArgumentException('XS2 sandbox external event id is required.');
        }

        $normalized = $this->fetchEventById($eventId);
        $payload = $normalized['catalog_payload'] ?? null;
        if (! is_array($payload) || ! is_scalar($payload['event_id'] ?? null)) {
            throw new Xs2ResponseException(sprintf('XS2 sandbox event %s was not found in the catalog.', $eventId));
        }

        return $payload;
    }

    /**
     * @return array{
     *     environment: string,
     *     is_sandbox: true,
     *     source: string,
     *     request_url: string,
     *     events_tried: int,
     *     max_event_attempts: int,
     *     skipped_events: list<array{external_event_id: string|null, event_name: string|null, reason: string}>,
     *     event: array<string, mixed>,
     *     listing: array<string, mixed>,
     *     listing_request_url: string
     * }
     */
    public function fetchSandboxEventWithListing(?string $eventName = null): array
    {
        $this->validateConfig();

        $maxAttempts = max(1, (int) $this->setting('max_event_attempts', 15));
        $skippedEvents = [];
        $eventsTried = 0;
        $triedEventIds = [];

        $testEventId = $this->nullableString($this->setting('test_event_id'));
        if ($testEventId !== null) {
            $result = $this->tryEventForListing($testEventId, 'configured_test_event_id');
            $eventsTried++;

            if ($result !== null) {
                return $this->buildEventWithListingResult(
                    $result,
                    $skippedEvents,
                    $eventsTried,
                    $maxAttempts,
                );
            }

            $skippedEvents[] = $this->skippedEventEntry(
                $this->fetchEventById($testEventId),
                'no_available_tickets',
            );
            $triedEventIds[$testEventId] = true;
        }

        $eventName = $this->nullableString($eventName);
        if ($eventName !== null) {
            $nameSearchResult = $this->searchCatalogEventsForListing(
                ['event_name' => $eventName],
                'event_name_search',
                $maxAttempts,
                $skippedEvents,
                $triedEventIds,
                $eventsTried,
            );

            if ($nameSearchResult !== null) {
                return $nameSearchResult;
            }
        }

        $discovered = $this->discoverEventFromAvailableTickets();
        if ($discovered !== null) {
            $eventId = $this->nullableString($discovered['event']['external_event_id'] ?? null);
            if ($eventId !== null && ! isset($triedEventIds[$eventId])) {
                $listingResult = $this->tryFetchListingForEvent($eventId);
                if ($listingResult !== null) {
                    return $this->buildEventWithListingResult(
                        [
                            'source' => 'first_available_ticket_event',
                            'request_url' => $discovered['request_url'],
                            'event' => $discovered['event'],
                            'listing' => $listingResult['listing'],
                            'listing_request_url' => $listingResult['request_url'],
                        ],
                        $skippedEvents,
                        $eventsTried + 1,
                        $maxAttempts,
                    );
                }

                $skippedEvents[] = $this->skippedEventEntry($discovered['event'], 'no_available_tickets');
                $triedEventIds[$eventId] = true;
                $eventsTried++;
            }
        }

        $catalogResult = $this->searchCatalogEventsForListing(
            [],
            'catalog_event_with_available_tickets',
            $maxAttempts,
            $skippedEvents,
            $triedEventIds,
            $eventsTried,
        );

        if ($catalogResult !== null) {
            return $catalogResult;
        }

        $skippedCount = count($skippedEvents);

        throw new Xs2ResponseException(sprintf(
            'No sandbox event with available tickets was found after trying %d event(s) (limit %d). Skipped %d event(s) with no available tickets (ticket_status=available, stock>0). Set XS2_SANDBOX_TEST_EVENT_ID to an event with inventory or increase XS2_SANDBOX_MAX_EVENT_ATTEMPTS.',
            $eventsTried,
            $maxAttempts,
            $skippedCount,
        ));
    }

    /**
     * @return array{
     *     environment: string,
     *     is_sandbox: true,
     *     source: string,
     *     request_url: string,
     *     event: array<string, mixed>
     * }
     */
    public function fetchSandboxEvent(?string $eventName = null): array
    {
        $result = $this->fetchSandboxEventWithListing($eventName);

        return [
            'environment' => $result['environment'],
            'is_sandbox' => $result['is_sandbox'],
            'source' => $result['source'],
            'request_url' => $result['request_url'],
            'event' => $result['event'],
            'listing' => $result['listing'],
            'listing_request_url' => $result['listing_request_url'],
            'events_tried' => $result['events_tried'],
            'max_event_attempts' => $result['max_event_attempts'],
            'skipped_events' => $result['skipped_events'],
        ];
    }

    /**
     * @return array{
     *     environment: string,
     *     is_sandbox: true,
     *     request_url: string,
     *     listing: array<string, mixed>
     * }
     */
    public function fetchSandboxListing(string $externalEventId): array
    {
        $this->validateConfig();

        $endpoint = $this->endpoint('tickets_endpoint');
        $query = array_merge(
            ['event_id' => $externalEventId, 'page_size' => 25, 'page' => 1],
            $this->availableTicketQuery(),
        );
        $response = $this->send('GET', $endpoint, ['query' => $query], 'sandbox_list_tickets');
        $tickets = $this->collectionFrom($response, ['tickets', 'data']) ?? [];
        $listing = $this->firstAvailableTicket($tickets);

        if ($listing === null) {
            $availableCount = $this->paginationTotal($response);
            $totalCount = $this->countTicketsForEvent($externalEventId, []);

            throw new Xs2ResponseException(sprintf(
                'No available sandbox tickets were found for event %s. XS2 returned %d ticket(s) with ticket_status=available and stock>0 (%d total ticket(s) for this event). Set XS2_SANDBOX_TEST_EVENT_ID to an event with inventory, or fetch a sandbox event that includes available tickets.',
                $externalEventId,
                $availableCount,
                $totalCount,
            ));
        }

        return [
            'environment' => 'sandbox',
            'is_sandbox' => true,
            'request_url' => $this->absoluteUrl($endpoint).'?'.http_build_query($query),
            'listing' => $this->normalizeListingPayload($listing),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createReservation(array $payload): array
    {
        $this->validateConfig();

        return $this->send(
            'POST',
            $this->endpoint('reservations_endpoint'),
            ['json' => $payload],
            'sandbox_create_reservation',
        );
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
    public function createReservationDetailed(array $payload): array
    {
        $this->validateConfig();

        return $this->sendDetailed(
            'POST',
            $this->endpoint('reservations_endpoint'),
            ['json' => $payload],
            'sandbox_create_reservation',
        );
    }

    /** @param array<string, mixed> $payload */
    public function createBooking(array $payload): array
    {
        $this->validateConfig();

        return $this->send(
            'POST',
            (string) $this->setting('bookings_endpoint', '/v1/bookings'),
            ['json' => $payload],
            'sandbox_create_booking',
        );
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
    public function createBookingDetailed(array $payload): array
    {
        $this->validateConfig();

        return $this->sendDetailed(
            'POST',
            (string) $this->setting('bookings_endpoint', '/v1/bookings'),
            ['json' => $payload],
            'sandbox_create_booking',
        );
    }

    /** @return array<string, mixed>|list<mixed> */
    public function fetchBooking(string $bookingId): array
    {
        $this->validateConfig();

        $template = (string) $this->setting('booking_detail_endpoint', '/v1/bookings/{booking_id}');
        $uri = $this->endpoints->resolve($template, ['booking_id' => $bookingId], 'XS2');

        return $this->send(
            'GET',
            $uri,
            [],
            'sandbox_get_booking',
        );
    }

    /**
     * List booking orders from the XS2 sandbox API (GET /v1/bookingorders).
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>|list<mixed>
     */
    public function fetchBookingOrders(array $query = []): array
    {
        $this->validateConfig();

        $filteredQuery = array_filter(
            $query,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return $this->send(
            'GET',
            (string) $this->setting('bookingorders_endpoint', '/v1/bookingorders'),
            ['query' => $filteredQuery],
            'sandbox_list_bookingorders',
        );
    }

    /** @return array<string, mixed>|list<mixed> */
    public function fetchBookingOrdersByBookingId(string $bookingId): array
    {
        return $this->fetchBookingOrders(['booking_id' => $bookingId]);
    }

    /** @return array<string, mixed>|list<mixed> */
    public function fetchBookingOrder(string $bookingOrderId): array
    {
        $this->validateConfig();

        $template = (string) $this->setting(
            'bookingorder_detail_endpoint',
            config('xs2.bookingorder_detail_endpoint', '/v1/bookingorders/{bookingorder_id}'),
        );
        $uri = $this->endpoints->resolve($template, ['bookingorder_id' => $bookingOrderId], 'XS2');

        return $this->send(
            'GET',
            $uri,
            [],
            'sandbox_get_bookingorder',
        );
    }

    /**
     * Download a single e-ticket PDF via the XS2 sandbox API.
     *
     * @return array{status: int, body: string, headers: array<string, list<string>>}
     */
    public function downloadEticketPdf(string $bookingOrderId, string $orderItemId, string $downloadLink): array
    {
        $this->validateConfig();

        $template = (string) config(
            'xs2.eticket_download_endpoint',
            '/v1/etickets/download/{bookingorder_id}/{orderitem_id}/url/{url}',
        );
        $uri = $this->endpoints->resolve($template, [
            'bookingorder_id' => $bookingOrderId,
            'orderitem_id' => $orderItemId,
            'url' => $downloadLink,
        ], 'XS2');

        $url = $this->absoluteUrl($uri);
        $requestHeaders = $this->requestHeaders();

        try {
            $response = Http::baseUrl(rtrim((string) $this->setting('api_url'), '/'))
                ->withHeaders($requestHeaders)
                ->accept('application/pdf, application/octet-stream, */*')
                ->connectTimeout((int) $this->setting('connect_timeout', 10))
                ->timeout((int) $this->setting('timeout', 30))
                ->get($uri);
        } catch (ConnectionException) {
            throw new Xs2RequestException('XS2 sandbox e-ticket request could not connect.');
        }

        $this->debugger->record(
            'sandbox_download_eticket',
            'GET',
            $url,
            $requestHeaders,
            [
                'bookingorder_id' => $bookingOrderId,
                'orderitem_id' => $orderItemId,
                'download_link' => $downloadLink,
            ],
            $response,
        );

        if (! $response->successful()) {
            throw new Xs2RequestException(
                $this->requestFailureMessage($response->status(), $response->json(), $url),
                $response->status(),
            );
        }

        $body = $response->body();
        if ($body === '') {
            throw new Xs2ResponseException('XS2 sandbox e-ticket response was empty.');
        }

        return [
            'status' => $response->status(),
            'body' => $body,
            'headers' => $response->headers(),
        ];
    }

    /** @return array<string, mixed> */
    public function fetchVenue(string $externalVenueId): array
    {
        $this->validateConfig();

        $externalVenueId = trim($externalVenueId);
        if ($externalVenueId === '') {
            throw new \InvalidArgumentException('XS2 sandbox venue id is required.');
        }

        $response = $this->send(
            'GET',
            $this->endpoint('venue_detail_endpoint', ['venue_id' => $externalVenueId]),
            [],
            'sandbox_get_venue',
        );

        $venue = data_get($response, 'venue');
        if (is_array($venue)) {
            return $venue;
        }

        return is_array($response) ? $response : [];
    }

    /** @return list<array<string, mixed>> */
    public function fetchCategoriesForEvent(string $externalEventId): array
    {
        $this->validateConfig();

        return $this->paginate(
            $this->endpoint('categories_endpoint'),
            ['event_id' => trim($externalEventId)],
            ['categories', 'data'],
            'sandbox_list_categories',
        );
    }

    /** @return list<array<string, mixed>> */
    public function fetchAllTicketsForEvent(string $externalEventId): array
    {
        $this->validateConfig();

        return $this->paginate(
            $this->endpoint('tickets_endpoint'),
            array_merge(
                ['event_id' => trim($externalEventId), 'include_youth' => 'true'],
                $this->availableTicketQuery(),
            ),
            ['tickets', 'data'],
            'sandbox_list_all_tickets',
        );
    }

    /**
     * Search the sandbox event catalog and return events with available tickets.
     *
     * @param  array<string, scalar>  $filters  e.g. event_name, sport_type, hometeam_name
     * @return list<array{external_event_id: string, event_name: string|null, venue_name: string|null, hometeam_name: string|null, available_tickets: int}>
     */
    public function searchEventsWithAvailableTickets(array $filters, int $limit = 10): array
    {
        $this->validateConfig();

        $limit = max(1, $limit);
        $results = [];
        $page = 1;

        while (count($results) < $limit) {
            $query = array_merge($filters, ['page_size' => min(20, $limit * 2), 'page' => $page]);
            $response = $this->send('GET', $this->endpoint('events_endpoint'), ['query' => $query], 'sandbox_search_events');
            $events = $this->collectionFrom($response, ['events', 'data']) ?? [];

            if ($events === []) {
                break;
            }

            foreach ($events as $rawEvent) {
                if (! is_array($rawEvent) || count($results) >= $limit) {
                    break;
                }

                $event = $this->normalizeEventPayload($rawEvent, null);
                $eventId = $this->nullableString($event['external_event_id'] ?? null);
                if ($eventId === null) {
                    continue;
                }

                $available = $this->countTicketsForEvent($eventId, $this->availableTicketQuery());
                if ($available <= 0) {
                    continue;
                }

                $results[] = [
                    'external_event_id' => $eventId,
                    'event_name' => $this->nullableString($event['event_name'] ?? null),
                    'venue_name' => $this->nullableString($event['venue_name'] ?? null),
                    'hometeam_name' => $this->nullableString(data_get($rawEvent, 'hometeam_name')),
                    'available_tickets' => $available,
                ];
            }

            if (! $this->hasMoreEventPages($response, $page)) {
                break;
            }

            $page++;
        }

        return $results;
    }

    /** @return array<string, mixed> */
    public function fetchTicketGuestRequirements(string $ticketId): array
    {
        $this->validateConfig();

        return $this->send(
            'GET',
            $this->endpoint('ticket_guestdata_endpoint', ['ticket_id' => $ticketId]),
            [],
            'sandbox_get_ticket_guestdata',
        );
    }

    /** @return array<string, mixed> */
    public function fetchBookingOrderGuestData(string $bookingOrderId, bool $includeConditions = true): array
    {
        $this->validateConfig();

        $options = [];
        if ($includeConditions) {
            $options['query'] = ['include_conditions' => 'true'];
        }

        return $this->send(
            'GET',
            $this->endpoint('bookingorder_guestdata_endpoint', ['bookingorder_id' => $bookingOrderId]),
            $options,
            'sandbox_get_bookingorder_guestdata',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $guests
     * @return array<string, mixed>
     */
    public function updateBookingGuestData(string $bookingOrderId, string $ticketId, array $guests): array
    {
        $this->validateConfig();

        $uri = $this->endpoint('bookingorder_guestdata_endpoint', ['bookingorder_id' => $bookingOrderId]);

        return $this->send(
            'PUT',
            $uri,
            ['json' => [
                'items' => [[
                    'ticket_id' => $ticketId,
                    'guests' => $guests,
                ]],
            ]],
            'sandbox_update_booking_guestdata',
        );
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->setting('api_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withHeaders($this->requestHeaders())
            ->connectTimeout((int) $this->setting('connect_timeout', 10))
            ->timeout((int) $this->setting('timeout', 30));
    }

    /** @return array<string, string> */
    private function requestHeaders(): array
    {
        return [
            (string) $this->setting('api_key_header', 'X-Api-Key') => (string) $this->setting('api_key'),
        ];
    }

    private function absoluteUrl(string $uri): string
    {
        $base = rtrim((string) $this->setting('api_url'), '/');
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }

        return $base.'/'.ltrim($uri, '/');
    }

    private function validateConfig(): void
    {
        foreach (['api_url', 'api_key'] as $key) {
            if (blank($this->setting($key))) {
                throw new Xs2ConfigurationException(
                    'XS2 sandbox test flow is not configured. Set XS2_SANDBOX_API_URL and XS2_SANDBOX_API_KEY in .env.',
                );
            }
        }
    }

    /** @param array<string, scalar> $parameters */
    private function endpoint(string $configKey, array $parameters = []): string
    {
        $template = config("xs2.{$configKey}");
        if (! is_string($template) || $template === '') {
            throw new Xs2ConfigurationException('XS2 endpoint '.$configKey.' is not configured.');
        }

        return $this->endpoints->resolve($template, $parameters, 'XS2');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|list<mixed>
     */
    private function send(string $method, string $uri, array $options = [], string $operation = 'xs2_sandbox_api'): array
    {
        $result = $this->sendDetailed($method, $uri, $options, $operation);
        if (! $result['success']) {
            throw new Xs2RequestException(
                (string) ($result['message'] ?? 'XS2 sandbox request failed.'),
                $result['status'],
            );
        }

        return $result['data'];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     success: bool,
     *     status: int|null,
     *     data: array<string, mixed>,
     *     headers: array<string, list<string>>,
     *     message: string|null
     * }
     */
    private function sendDetailed(string $method, string $uri, array $options = [], string $operation = 'xs2_sandbox_api'): array
    {
        $attempts = max(1, (int) $this->setting('retry_times', 2));
        $retryableStatuses = [429, 500, 502, 503, 504];
        $url = $this->absoluteUrl($uri);
        $requestHeaders = $this->requestHeaders();
        $payload = $options['query'] ?? $options['json'] ?? $options['body'] ?? [];
        if (! is_array($payload)) {
            $payload = ['body' => $payload];
        }
        if (($options['query'] ?? null) && is_array($options['query']) && $options['query'] !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($options['query']);
        }

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->http()->send($method, $uri, $options);
            } catch (ConnectionException $exception) {
                if ($attempt === $attempts) {
                    $this->debugger->recordTransportFailure(
                        $operation,
                        $method,
                        $url,
                        $requestHeaders,
                        $payload,
                        null,
                        $exception->getMessage(),
                    );

                    return [
                        'success' => false,
                        'status' => null,
                        'data' => [],
                        'headers' => [],
                        'message' => 'XS2 sandbox request could not connect.',
                    ];
                }

                $this->backoff($attempt);

                continue;
            }

            $this->debugger->record($operation, $method, $url, $requestHeaders, $payload, $response);
            $headers = $response->headers();
            $json = $response->json();
            $body = is_array($json) ? $json : [];

            if ($response->successful()) {
                if (! is_array($json)) {
                    return [
                        'success' => false,
                        'status' => $response->status(),
                        'data' => [],
                        'headers' => $headers,
                        'message' => 'XS2 sandbox response is not a JSON object or array.',
                    ];
                }

                return [
                    'success' => true,
                    'status' => $response->status(),
                    'data' => $json,
                    'headers' => $headers,
                    'message' => null,
                ];
            }

            if (! in_array($response->status(), $retryableStatuses, true)) {
                return [
                    'success' => false,
                    'status' => $response->status(),
                    'data' => $body,
                    'headers' => $headers,
                    'message' => $this->requestFailureMessage($response->status(), $json, $url),
                ];
            }

            if ($attempt === $attempts) {
                return [
                    'success' => false,
                    'status' => $response->status(),
                    'data' => $body,
                    'headers' => $headers,
                    'message' => $this->requestFailureMessage($response->status(), $json, $url),
                ];
            }

            $this->backoff($attempt);
        }

        return [
            'success' => false,
            'status' => null,
            'data' => [],
            'headers' => [],
            'message' => 'XS2 sandbox request failed.',
        ];
    }

    /** @param array<string,mixed>|list<mixed> $response @param list<string> $keys @return list<mixed>|null */
    private function collectionFrom(array $response, array $keys): ?array
    {
        if (array_is_list($response)) {
            return $response;
        }

        foreach ($keys as $key) {
            $value = data_get($response, $key);
            if (is_array($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     source: string,
     *     request_url: string,
     *     event: array<string, mixed>,
     *     listing: array<string, mixed>,
     *     listing_request_url: string
     * }|null
     */
    private function tryEventForListing(string $eventId, string $source): ?array
    {
        $listingResult = $this->tryFetchListingForEvent($eventId);
        if ($listingResult === null) {
            return null;
        }

        $eventEndpoint = $this->endpoint('event_detail_endpoint', ['event_id' => $eventId]);

        return [
            'source' => $source,
            'request_url' => $this->absoluteUrl($eventEndpoint),
            'event' => $this->fetchEventById($eventId),
            'listing' => $listingResult['listing'],
            'listing_request_url' => $listingResult['request_url'],
        ];
    }

    /** @return array<string, mixed> */
    private function fetchEventById(string $eventId): array
    {
        $eventEndpoint = $this->endpoint('event_detail_endpoint', ['event_id' => $eventId]);
        $eventResponse = $this->send('GET', $eventEndpoint, [], 'sandbox_get_event');

        return $this->normalizeEventPayload($eventResponse, $eventId);
    }

    /**
     * @param  array{
     *     source: string,
     *     request_url: string,
     *     event: array<string, mixed>,
     *     listing: array<string, mixed>,
     *     listing_request_url: string
     * }  $result
     * @param  list<array{external_event_id: string|null, event_name: string|null, reason: string}>  $skippedEvents
     * @return array{
     *     environment: string,
     *     is_sandbox: true,
     *     source: string,
     *     request_url: string,
     *     events_tried: int,
     *     max_event_attempts: int,
     *     skipped_events: list<array{external_event_id: string|null, event_name: string|null, reason: string}>,
     *     event: array<string, mixed>,
     *     listing: array<string, mixed>,
     *     listing_request_url: string
     * }
     */
    private function buildEventWithListingResult(
        array $result,
        array $skippedEvents,
        int $eventsTried,
        int $maxAttempts,
    ): array {
        return [
            'environment' => 'sandbox',
            'is_sandbox' => true,
            'source' => $result['source'],
            'request_url' => $result['request_url'],
            'events_tried' => $eventsTried,
            'max_event_attempts' => $maxAttempts,
            'skipped_events' => $skippedEvents,
            'event' => $result['event'],
            'listing' => $result['listing'],
            'listing_request_url' => $result['listing_request_url'],
        ];
    }

    /**
     * @param  array<string, scalar>  $filters
     * @param  list<array{external_event_id: string|null, event_name: string|null, reason: string}>  $skippedEvents
     * @param  array<string, true>  $triedEventIds
     * @return array{
     *     environment: string,
     *     is_sandbox: true,
     *     source: string,
     *     request_url: string,
     *     events_tried: int,
     *     max_event_attempts: int,
     *     skipped_events: list<array{external_event_id: string|null, event_name: string|null, reason: string}>,
     *     event: array<string, mixed>,
     *     listing: array<string, mixed>,
     *     listing_request_url: string
     * }|null
     */
    private function searchCatalogEventsForListing(
        array $filters,
        string $source,
        int $maxAttempts,
        array &$skippedEvents,
        array &$triedEventIds,
        int &$eventsTried,
    ): ?array {
        $page = 1;
        $pageSize = min(10, $maxAttempts);
        $eventsEndpoint = $this->endpoint('events_endpoint');

        while ($eventsTried < $maxAttempts) {
            $remaining = $maxAttempts - $eventsTried;
            $query = array_merge(
                $filters,
                ['page_size' => min($pageSize, $remaining), 'page' => $page],
            );
            $response = $this->send('GET', $eventsEndpoint, ['query' => $query], 'sandbox_list_events');
            $events = $this->collectionFrom($response, ['events', 'data']) ?? [];

            if ($events === []) {
                break;
            }

            $catalogRequestUrl = $this->absoluteUrl($eventsEndpoint).'?'.http_build_query($query);

            foreach ($events as $rawEvent) {
                if ($eventsTried >= $maxAttempts) {
                    break 2;
                }

                if (! is_array($rawEvent)) {
                    continue;
                }

                $event = $this->normalizeEventPayload($rawEvent, null);
                $eventId = $this->nullableString($event['external_event_id'] ?? null);
                if ($eventId === null || isset($triedEventIds[$eventId])) {
                    continue;
                }

                $triedEventIds[$eventId] = true;
                $eventsTried++;

                $listingResult = $this->tryFetchListingForEvent($eventId);
                if ($listingResult !== null) {
                    return $this->buildEventWithListingResult(
                        [
                            'source' => $source,
                            'request_url' => $catalogRequestUrl,
                            'event' => $event,
                            'listing' => $listingResult['listing'],
                            'listing_request_url' => $listingResult['request_url'],
                        ],
                        $skippedEvents,
                        $eventsTried,
                        $maxAttempts,
                    );
                }

                $skippedEvents[] = $this->skippedEventEntry($event, 'no_available_tickets');
            }

            if (! $this->hasMoreEventPages($response, $page)) {
                break;
            }

            $page++;
        }

        return null;
    }

    /** @param array<string, mixed> $event @return array{external_event_id: string|null, event_name: string|null, reason: string} */
    private function skippedEventEntry(array $event, string $reason): array
    {
        return [
            'external_event_id' => $this->nullableString($event['external_event_id'] ?? null),
            'event_name' => $this->nullableString($event['event_name'] ?? null),
            'reason' => $reason,
        ];
    }

    /**
     * @return array{request_url: string, listing: array<string, mixed>}|null
     */
    private function tryFetchListingForEvent(string $externalEventId): ?array
    {
        $endpoint = $this->endpoint('tickets_endpoint');
        $query = array_merge(
            ['event_id' => $externalEventId, 'page_size' => 25, 'page' => 1],
            $this->availableTicketQuery(),
        );
        $response = $this->send('GET', $endpoint, ['query' => $query], 'sandbox_list_tickets');
        $tickets = $this->collectionFrom($response, ['tickets', 'data']) ?? [];
        $listing = $this->firstAvailableTicket($tickets);

        if ($listing === null) {
            return null;
        }

        return [
            'request_url' => $this->absoluteUrl($endpoint).'?'.http_build_query($query),
            'listing' => $this->normalizeListingPayload($listing),
        ];
    }

    /** @param array<string, mixed>|list<mixed> $response */
    private function hasMoreEventPages(array $response, int $currentPage): bool
    {
        $totalPages = data_get($response, 'pagination.total_pages', data_get($response, 'total_pages'));
        if (is_numeric($totalPages)) {
            return $currentPage < (int) $totalPages;
        }

        $nextPage = data_get($response, 'pagination.next_page', data_get($response, 'next_page'));

        return is_numeric($nextPage) && (int) $nextPage > $currentPage;
    }

    /**
     * @return array{request_url: string, event: array<string, mixed>}|null
     */
    private function discoverEventFromAvailableTickets(): ?array
    {
        $ticketsEndpoint = $this->endpoint('tickets_endpoint');
        $query = array_merge(['page_size' => 1, 'page' => 1], $this->availableTicketQuery());
        $response = $this->send('GET', $ticketsEndpoint, ['query' => $query], 'sandbox_discover_ticket_event');
        $tickets = $this->collectionFrom($response, ['tickets', 'data']) ?? [];
        $ticket = $this->firstAvailableTicket($tickets);
        if ($ticket === null) {
            return null;
        }

        $eventId = $this->nullableString($ticket['event_id'] ?? null);
        if ($eventId === null) {
            return null;
        }

        $eventEndpoint = $this->endpoint('event_detail_endpoint', ['event_id' => $eventId]);
        $eventResponse = $this->send('GET', $eventEndpoint, [], 'sandbox_get_event');
        $event = $this->normalizeEventPayload($eventResponse, $eventId);

        return [
            'request_url' => $this->absoluteUrl($ticketsEndpoint).'?'.http_build_query($query),
            'event' => $event,
        ];
    }

    /** @param array<string, scalar> $extraQuery */
    private function countTicketsForEvent(string $eventId, array $extraQuery): int
    {
        $endpoint = $this->endpoint('tickets_endpoint');
        $query = array_merge(
            ['event_id' => $eventId, 'page_size' => 1, 'page' => 1],
            $extraQuery,
        );
        $response = $this->send('GET', $endpoint, ['query' => $query], 'sandbox_count_tickets');

        return $this->paginationTotal($response);
    }

    /** @return array<string, string> */
    private function availableTicketQuery(): array
    {
        return [
            'include_youth' => 'true',
            'ticket_status' => 'available',
            'stock' => 'gt:0',
        ];
    }

    /** @param array<string, mixed>|list<mixed> $response */
    private function paginationTotal(array $response): int
    {
        $total = data_get($response, 'pagination.total_size');

        return is_numeric($total) ? (int) $total : 0;
    }

    /** @param list<mixed> $tickets @return array<string, mixed>|null */
    private function firstAvailableTicket(array $tickets): ?array
    {
        foreach ($tickets as $ticket) {
            if (! is_array($ticket)) {
                continue;
            }

            $status = strtolower((string) ($ticket['ticket_status'] ?? ''));
            $stock = (int) ($ticket['stock'] ?? $ticket['quantity'] ?? 0);
            if ($status === 'available' && $stock > 0) {
                return $ticket;
            }
        }

        return null;
    }

    /** @param array<string, mixed>|list<mixed> $payload */
    private function normalizeEventPayload(array $payload, ?string $fallbackEventId): array
    {
        $event = data_get($payload, 'event');
        if (is_array($event)) {
            $payload = $event;
        }

        $eventId = $this->nullableString($payload['event_id'] ?? $payload['id'] ?? $fallbackEventId);

        return [
            'environment' => 'sandbox',
            'is_sandbox' => true,
            'external_event_id' => $eventId,
            'event_name' => $this->nullableString($payload['event_name'] ?? $payload['name'] ?? null),
            'starts_at' => $this->nullableString($payload['date_start'] ?? $payload['starts_at'] ?? null),
            'ends_at' => $this->nullableString($payload['date_stop'] ?? $payload['ends_at'] ?? null),
            'tournament_name' => $this->nullableString($payload['tournament_name'] ?? null),
            'venue_name' => $this->nullableString($payload['venue_name'] ?? null),
            'city' => $this->nullableString($payload['city'] ?? null),
            'sport_type' => $this->nullableString($payload['sport_type'] ?? null),
            'event_status' => $this->nullableString($payload['event_status'] ?? $payload['status'] ?? null),
            'catalog_payload' => $payload,
        ];
    }

    /** @param array<string, mixed> $ticket */
    private function normalizeListingPayload(array $ticket): array
    {
        return [
            'environment' => 'sandbox',
            'is_sandbox' => true,
            'ticket_id' => $this->nullableString($ticket['ticket_id'] ?? $ticket['id'] ?? null),
            'event_id' => $this->nullableString($ticket['event_id'] ?? null),
            'ticket_name' => $this->nullableString($ticket['ticket_name'] ?? $ticket['name'] ?? null),
            'category_name' => $this->nullableString($ticket['category_name'] ?? null),
            'ticket_status' => $this->nullableString($ticket['ticket_status'] ?? null),
            'stock' => isset($ticket['stock']) ? (int) $ticket['stock'] : null,
            'net_rate' => isset($ticket['net_rate']) ? (int) $ticket['net_rate'] : null,
            'sales_price' => isset($ticket['sales_price']) ? (int) $ticket['sales_price'] : null,
            'currency_code' => $this->nullableString($ticket['currency_code'] ?? $ticket['currency'] ?? 'EUR'),
            'catalog_payload' => $ticket,
        ];
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        if ($key === 'api_url') {
            $resolved = app(ApiEnvironmentService::class)->effectiveSandboxXs2BaseUrl();
            if (filled($resolved)) {
                return $resolved;
            }
        }

        if ($key === 'api_key') {
            $resolved = app(ApiEnvironmentService::class)->effectiveSandboxXs2ApiKey();
            if (filled($resolved)) {
                return $resolved;
            }
        }

        return config("xs2.sandbox.{$key}", $default);
    }

    private function backoff(int $attempt): void
    {
        usleep((int) $this->setting('retry_delay_ms', 1000) * 1000 * $attempt);
    }

    /** @param array<string,mixed>|null $body */
    private function requestFailureMessage(int $status, ?array $body, ?string $url = null): string
    {
        $detail = data_get($body, 'message', data_get($body, 'error'));
        $prefix = 'XS2 sandbox request failed with HTTP '.$status;
        if ($url !== null) {
            $prefix .= ' ('.$this->sanitizeUrlForError($url).')';
        }

        return is_string($detail) && $detail !== ''
            ? $prefix.': '.mb_substr($detail, 0, 500)
            : $prefix.'.';
    }

    private function sanitizeUrlForError(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';

        return $scheme.'://'.$host.$path;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @param  list<string>  $collectionKeys
     * @return list<array<string, mixed>>
     */
    private function paginate(string $uri, array $filters, array $collectionKeys, string $operation): array
    {
        $items = [];
        $maxPages = max(1, (int) config('xs2.max_pages', 500));
        $pageSize = max(1, (int) config('xs2.page_size', 100));

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->send('GET', $uri, [
                'query' => array_merge($filters, ['page_size' => $pageSize, 'page' => $page]),
            ], $operation);
            $pageItems = $this->collectionFrom($response, $collectionKeys) ?? [];

            foreach ($pageItems as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            if (! $this->hasMoreEventPages($response, $page)) {
                break;
            }
        }

        return $items;
    }
}
