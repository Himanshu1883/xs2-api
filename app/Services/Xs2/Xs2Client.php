<?php

namespace App\Services\Xs2;

use App\Exceptions\Integrations\Xs2ConfigurationException;
use App\Exceptions\Integrations\Xs2RateLimitException;
use App\Exceptions\Integrations\Xs2RequestException;
use App\Exceptions\Integrations\Xs2ResponseException;
use App\Services\Admin\ApiEnvironmentService;
use App\Services\Admin\IntegrationSettingService;
use App\Support\EndpointTemplateResolver;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class Xs2Client
{
    public function __construct(
        private readonly EndpointTemplateResolver $endpoints,
        private readonly Xs2ApiRequestDebugger $debugger,
    ) {}

    private function http(): PendingRequest
    {
        $this->validateConfig();

        return Http::baseUrl(rtrim((string) $this->setting('base_url'), '/'))
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
            (string) $this->setting('api_key_header') => (string) $this->setting('api_key'),
        ];
    }

    private function absoluteUrl(string $uri): string
    {
        $base = rtrim((string) $this->setting('base_url'), '/');
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }

        return $base.'/'.ltrim($uri, '/');
    }

    private function validateConfig(): void
    {
        if (! $this->setting('enabled', true)) {
            throw new Xs2ConfigurationException('XS2 integration is disabled.');
        }

        foreach (['base_url', 'api_key'] as $key) {
            if (blank($this->setting($key))) {
                throw new Xs2ConfigurationException('XS2 integration is enabled but XS2_'.strtoupper($key).' is not configured.');
            }
        }
    }

    /** @param array<string, scalar> $parameters */
    private function endpoint(string $configKey, array $parameters = []): string
    {
        $template = $this->setting($configKey);
        if (! is_string($template) || $template === '') {
            throw new Xs2ConfigurationException('XS2 integration is enabled but XS2_'.strtoupper($configKey).' is not configured.');
        }

        return $this->endpoints->resolve($template, $parameters, 'XS2');
    }

    private function consumeRateLimit(): void
    {
        $key = 'xs2-api:'.app()->environment();
        $maximumAttempts = max(1, (int) $this->setting('rate_limit_per_minute', 30));

        if (! $this->setting('rate_limit_pacing', true)) {
            $this->consumeBurstLimit($key, $maximumAttempts);

            return;
        }

        $secondsPerRequest = 60 / $maximumAttempts;
        $lock = Cache::lock($key.':pacing-lock', 120);

        try {
            $lock->block(60, function () use ($key, $maximumAttempts, $secondsPerRequest): void {
                $nextRequestAtKey = $key.':next-request-at';
                $waitSeconds = max(0, (float) Cache::get($nextRequestAtKey, 0) - microtime(true));

                if (RateLimiter::tooManyAttempts($key, $maximumAttempts)) {
                    $waitSeconds = max($waitSeconds, (float) max(1, RateLimiter::availableIn($key)));
                }

                if ($waitSeconds > 0) {
                    usleep((int) ceil($waitSeconds * 1_000_000));
                }

                while (RateLimiter::tooManyAttempts($key, $maximumAttempts)) {
                    usleep(max(1, RateLimiter::availableIn($key)) * 1_000_000);
                }

                RateLimiter::hit($key, 60);
                Cache::put($nextRequestAtKey, microtime(true) + $secondsPerRequest, now()->addSeconds(120));
            });
        } catch (LockTimeoutException) {
            // Queue jobs release this exception rather than starting an
            // overlapping request while another worker owns the next slot.
            throw new Xs2RateLimitException(max(1, (int) ceil($secondsPerRequest)));
        }
    }

    private function consumeBurstLimit(string $key, int $maximumAttempts): void
    {
        if (RateLimiter::tooManyAttempts($key, $maximumAttempts)) {
            throw new Xs2RateLimitException(max(1, RateLimiter::availableIn($key)));
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|list<mixed>
     */
    private function send(string $method, string $uri, array $options = [], string $operation = 'xs2_api'): array
    {
        $attempts = max(1, (int) $this->setting('retry_times', 4));
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
            $this->consumeRateLimit();

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

                    throw new Xs2RequestException('XS2 request could not connect.');
                }

                $this->backoff($attempt);

                continue;
            }

            $this->debugger->record($operation, $method, $url, $requestHeaders, $payload, $response);

            if ($response->successful()) {
                $json = $response->json();
                if (! is_array($json)) {
                    throw new Xs2ResponseException('XS2 response is not a JSON object or array.');
                }

                return $json;
            }

            if (! in_array($response->status(), $retryableStatuses, true)) {
                throw new Xs2RequestException(
                    $this->requestFailureMessage($response->status(), $response->json()),
                    $response->status(),
                );
            }

            if ($attempt === $attempts) {
                throw new Xs2RequestException('XS2 request failed with HTTP '.$response->status().'.', $response->status());
            }

            $this->backoff($attempt);
        }

        throw new Xs2RequestException('XS2 request failed.');
    }

    public function getEvents(array $query = []): array
    {
        return $this->send('GET', $this->endpoint('events_endpoint'), ['query' => $query], 'get_events');
    }

    public function getEvent(string $externalEventId): array
    {
        return $this->send('GET', $this->endpoint('event_detail_endpoint', ['event_id' => $externalEventId]), [], 'get_event');
    }

    /** @param array<string, scalar|null> $query */
    public function previewEventsRequestUrl(array $query = []): string
    {
        $endpoint = $this->endpoint('events_endpoint');
        $filtered = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');

        return $this->absoluteUrl($endpoint).($filtered === [] ? '' : '?'.http_build_query($filtered));
    }

    public function getVenue(string $externalVenueId): array
    {
        return $this->send('GET', $this->endpoint('venue_detail_endpoint', ['venue_id' => $externalVenueId]), [], 'get_venue');
    }

    /** @return list<array<string,mixed>> */
    public function getCategoriesForEvent(string $externalEventId): array
    {
        return $this->paginate('categories_endpoint', ['event_id' => $externalEventId], ['categories', 'data'], 'get_categories');
    }

    /** @return array<string, string> */
    private function ticketCatalogFilters(string $externalEventId): array
    {
        return [
            'event_id' => $externalEventId,
            // XS2 excludes youth tickets unless explicitly requested.
            'include_youth' => 'true',
        ];
    }

    /** @return list<array<string,mixed>> */
    public function getTicketsForEvent(string $externalEventId, array $filters = []): array
    {
        return $this->paginate('tickets_endpoint', array_merge(
            $this->ticketCatalogFilters($externalEventId),
            $filters,
        ), ['tickets', 'data'], 'get_tickets');
    }

    /**
     * Live first-page (or chosen page) XS2 tickets response for admin inspection.
     *
     * @return array{
     *     http_method: string,
     *     endpoint_template: string,
     *     base_url: string,
     *     query: array<string, mixed>,
     *     response: array<string, mixed>|list<mixed>,
     *     sellable_query: array<string, mixed>,
     *     sellable_response: array<string, mixed>|list<mixed>
     * }
     */
    public function previewTicketsForEvent(string $externalEventId, int $page = 1): array
    {
        $pageSize = (int) $this->setting('page_size', 100);
        $page = max(1, $page);
        $query = array_merge($this->ticketCatalogFilters($externalEventId), [
            'page_size' => $pageSize,
            'page' => $page,
        ]);
        $sellableQuery = array_merge($query, [
            'ticket_status' => 'available',
            'stock' => 'gt:0',
        ]);

        return [
            'http_method' => 'GET',
            'endpoint_template' => (string) $this->setting('tickets_endpoint', '/v1/tickets'),
            'base_url' => rtrim((string) $this->setting('base_url'), '/'),
            'query' => $query,
            'response' => $this->send('GET', $this->endpoint('tickets_endpoint'), ['query' => $query], 'preview_tickets'),
            'sellable_query' => $sellableQuery,
            'sellable_response' => $this->send('GET', $this->endpoint('tickets_endpoint'), ['query' => $sellableQuery], 'preview_sellable_tickets'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function getIncrementalTicketsForEvent(string $externalEventId, CarbonInterface $updatedSince): array
    {
        return $this->paginate('tickets_endpoint', [
            'event_id' => $externalEventId,
            'updated' => 'ge:'.$updatedSince->utc()->toDateString(),
            'show_deleted' => 'true',
        ], ['tickets', 'data'], 'get_incremental_tickets');
    }

    public function getTicket(string $ticketId): array
    {
        return $this->send('GET', $this->endpoint('ticket_detail_endpoint', ['ticket_id' => $ticketId]), [], 'get_ticket');
    }

    public function isConfigured(): bool
    {
        if (! $this->setting('enabled', true)) {
            return false;
        }

        foreach (['base_url', 'api_key'] as $key) {
            if (blank($this->setting($key))) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function getTicketGuestData(string $ticketId, ?string $countryHint = null): array
    {
        $query = [];
        if ($countryHint !== null && $countryHint !== '') {
            $query['country_hint'] = $countryHint;
        }

        return $this->send(
            'GET',
            $this->endpoint('ticket_guestdata_endpoint', ['ticket_id' => $ticketId]),
            $query === [] ? [] : ['query' => $query],
            'get_ticket_guestdata',
        );
    }

    /** @return array<string, mixed> */
    public function getBookingOrderGuestData(string $bookingOrderId, bool $includeConditions = true): array
    {
        $options = [];
        if ($includeConditions) {
            $options['query'] = ['include_conditions' => 'true'];
        }

        return $this->send(
            'GET',
            $this->endpoint('bookingorder_guestdata_endpoint', ['bookingorder_id' => $bookingOrderId]),
            $options,
            'get_bookingorder_guestdata',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $guests
     * @return array<string, mixed>
     */
    public function updateBookingOrderGuestData(string $bookingOrderId, string $ticketId, array $guests): array
    {
        return $this->send(
            'PUT',
            $this->endpoint('bookingorder_guestdata_endpoint', ['bookingorder_id' => $bookingOrderId]),
            ['json' => [
                'items' => [[
                    'ticket_id' => $ticketId,
                    'guests' => $guests,
                ]],
            ]],
            'update_bookingorder_guestdata',
        );
    }

    /** @return array<string, mixed> */
    public function getTeam(string $teamId): array
    {
        return $this->send('GET', $this->endpoint('team_detail_endpoint', ['team_id' => $teamId]), [], 'get_team');
    }

    /**
     * List supplier orders/bookings from XS2 when the account exposes an orders API.
     *
     * Default path is GET /v1/orders (XS2_ORDERS_ENDPOINT). Current XS2 catalog OpenAPI
     * documents reservations (POST) but not a confirmed orders list; configure the endpoint
     * when XS2 provides one.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function getOrders(array $filters = []): array
    {
        return $this->paginate('orders_endpoint', $filters, ['orders', 'bookings', 'data'], 'get_orders');
    }

    /**
     * @param  array<string,mixed>  $filters
     * @param  list<string>  $collectionKeys
     * @return list<array<string,mixed>>
     */
    private function paginate(string $endpointConfigKey, array $filters, array $collectionKeys, string $operation = 'xs2_paginate'): array
    {
        $items = [];
        $seenCursors = [];
        $maxPages = max(1, (int) $this->setting('max_pages', 500));

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->send('GET', $this->endpoint($endpointConfigKey), [
                'query' => array_merge($filters, [
                    'page_size' => (int) $this->setting('page_size', 100),
                    'page' => $page,
                ]),
            ], $operation);
            $pageItems = $this->collectionFrom($response, $collectionKeys);
            if ($pageItems === null) {
                throw new Xs2ResponseException('XS2 paginated response has an unexpected collection structure.');
            }

            foreach ($pageItems as $item) {
                if (! is_array($item)) {
                    throw new Xs2ResponseException('XS2 paginated response contains a non-object item.');
                }
                $items[] = $item;
            }

            $next = data_get($response, 'pagination.next_page', data_get($response, 'next_page'));
            $total = data_get($response, 'pagination.total_pages', data_get($response, 'total_pages'));
            $cursor = is_scalar($next) ? (string) $next : null;
            if ($cursor !== null && $cursor !== '') {
                if (isset($seenCursors[$cursor])) {
                    throw new Xs2ResponseException('XS2 pagination response contains a cursor loop.');
                }
                $seenCursors[$cursor] = true;
            }

            $hasMorePages = ($cursor !== null && $cursor !== '')
                || (is_numeric($total) && $page < (int) $total);
            if ($pageItems === [] && $hasMorePages) {
                throw new Xs2ResponseException('XS2 pagination returned an empty page before the collection was complete.');
            }
            if ($pageItems === [] || ! $hasMorePages) {
                return $items;
            }
        }

        throw new Xs2ResponseException('XS2 pagination exceeded XS2_MAX_PAGES.');
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

    private function backoff(int $attempt): void
    {
        usleep((int) $this->setting('retry_delay_ms', 1500) * 1000 * $attempt);
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        if ($key === 'base_url') {
            $resolved = app(ApiEnvironmentService::class)->xs2BaseUrl();
            if (filled($resolved)) {
                return $resolved;
            }
        }

        if ($key === 'api_key') {
            $resolved = app(ApiEnvironmentService::class)->xs2ApiKey();
            if (filled($resolved)) {
                return $resolved;
            }
        }

        $overrideKey = match ($key) {
            'base_url' => IntegrationSettingService::XS2_BASE_URL,
            'api_key' => IntegrationSettingService::XS2_API_KEY,
            default => null,
        };
        if ($overrideKey !== null) {
            $override = app(IntegrationSettingService::class)->value($overrideKey);
            if (filled($override)) {
                return $override;
            }
        }

        $legacy = config("services.xs2.{$key}");

        return filled($legacy) ? $legacy : config("xs2.{$key}", $default);
    }

    /** @param array<string,mixed>|null $body */
    private function requestFailureMessage(int $status, ?array $body): string
    {
        $detail = data_get($body, 'message', data_get($body, 'error'));

        return is_string($detail) && $detail !== ''
            ? 'XS2 request failed with HTTP '.$status.': '.mb_substr($detail, 0, 500)
            : 'XS2 request failed with HTTP '.$status.'.';
    }

    /** @deprecated Use getEvents(). */
    public function events(array $query = []): array
    {
        return $this->getEvents($query);
    }

    /** @deprecated Use getEvent(). */
    public function event(string $eventId): array
    {
        return $this->getEvent($eventId);
    }
}
