<?php

namespace App\Services\SellerApi;

use App\Exceptions\Integrations\SellerApiConfigurationException;
use App\Exceptions\Integrations\SellerApiRequestException;
use App\Services\Admin\ApiEnvironmentService;
use App\Services\Admin\IntegrationSettingService;
use App\Support\EndpointTemplateResolver;
use App\Support\SeatsbrokerCatalogId;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SellerApiClient
{
    public function __construct(
        private readonly EndpointTemplateResolver $endpoints,
        private readonly SellerApiRequestDebugger $debugger,
    ) {}

    private function request(): PendingRequest
    {
        if (! $this->setting('enabled', true)) {
            throw new SellerApiConfigurationException('Seller API integration is disabled.');
        }
        if (blank($this->setting('listing_base_url')) && blank($this->setting('base_url'))) {
            throw new SellerApiConfigurationException('Seller API integration is enabled but SELLER_API_LISTING_BASE_URL or SELLER_API_BASE_URL is not configured.');
        }
        if (blank($this->listingApiKey())) {
            throw new SellerApiConfigurationException('Seller API integration is enabled but SELLER_API_LISTING_API_KEY or SELLER_API_KEY is not configured.');
        }

        return Http::baseUrl($this->listingBaseUrl())
            ->acceptJson()
            ->asMultipart()
            ->withHeaders([
                $this->setting('api_key_header') => $this->listingApiKey(),
            ])
            ->connectTimeout((int) $this->setting('connect_timeout', 10))
            ->timeout((int) $this->setting('timeout', 30))
            ->retry(
                (int) $this->setting('retry_times', 3),
                (int) $this->setting('retry_delay_ms', 1000),
                $this->shouldRetryHttpException(...),
            );
    }

    /** @param array<string, scalar> $params */
    private function endpoint(string $name, array $params = []): string
    {
        $value = $this->setting($name);
        if (! is_string($value) || $value === '') {
            $environmentKey = 'SELLER_API_'.strtoupper(str_replace('_endpoint', '', $name)).'_ENDPOINT';
            throw new SellerApiConfigurationException("Seller API integration is enabled but {$environmentKey} is not configured.");
        }

        return $this->endpoints->resolve($value, $params, 'Seller API');
    }

    /**
     * Coerce multipart field values to scalars/strings for Seller API multipart requests.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, scalar>
     */
    private function scalarMultipartPayload(array $payload): array
    {
        $normalized = [];
        foreach ($payload as $name => $value) {
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }
            if (is_bool($value)) {
                $normalized[(string) $name] = $value ? '1' : '0';
            } else {
                $normalized[(string) $name] = is_string($value) ? $value : (string) $value;
            }
        }

        return $normalized;
    }

    /** @param array<string,mixed> $payload @param array<string,string> $headers */
    private function send(string $method, string $endpoint, array $payload = [], array $headers = [], string $operation = 'seller_api'): array
    {
        $url = $this->absoluteListingUrl($endpoint);
        $requestHeaders = [
            $this->setting('api_key_header') => $this->listingApiKey(),
            ...$headers,
        ];

        try {
            $response = $this->request()->withHeaders($headers)->send($method, $endpoint, [
                $method === 'GET' ? 'query' : 'multipart' => $this->scalarMultipartPayload($payload),
            ]);
            $this->debugger->record($operation, $method, $url, $requestHeaders, $payload, $response);

            if ($response->failed()) {
                throw new SellerApiRequestException(
                    'Seller API request failed with HTTP '.$response->status().'.',
                    $response->status(),
                    $this->listingFailureContext($url, $response),
                );
            }

            $json = $response->json();
            if (! is_array($json)) {
                throw new SellerApiRequestException(
                    'Seller API response is not a JSON object or array.',
                    context: $this->listingFailureContext($url, $response),
                );
            }

            $this->assertListingSuccess($json);

            return $this->normalizeListingResponse($json);
        } catch (SellerApiRequestException|SellerApiConfigurationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $response = $exception instanceof RequestException ? $exception->response : null;
            $this->debugger->recordTransportFailure(
                $operation,
                $method,
                $url,
                $requestHeaders,
                $payload,
                $response,
                $exception->getMessage(),
            );

            throw new SellerApiRequestException(
                $this->transportFailureMessage('Seller API request', $exception),
                $response?->status(),
                $this->listingFailureContext($url, $response, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    public function createListing(array $payload, string $idempotencyKey): array
    {
        $response = $this->send('POST', $this->endpoint('create_listing_endpoint'), $payload, [
            $this->setting('idempotency_key_header', 'Idempotency-Key') => $idempotencyKey,
        ], 'create_listing');
        $this->listingId($response);

        return $response;
    }

    public function listingId(array $response): string
    {
        foreach (['ticket_id', 'id', 'listing_id'] as $key) {
            $id = data_get($response, $key);
            if (is_scalar($id) && trim((string) $id) !== '') {
                return (string) $id;
            }
            $nested = data_get($response, "results.{$key}");
            if (is_scalar($nested) && trim((string) $nested) !== '') {
                return (string) $nested;
            }
        }

        throw new SellerApiRequestException('Seller API create response is missing a listing ID.');
    }

    public function updateListing(string $id, array $payload): array
    {
        return $this->send('POST', $this->endpoint('update_listing_endpoint', ['listing_id' => $id]), [
            'ticket_id' => $id,
            ...$payload,
        ], [], 'update_listing');
    }

    public function disableListing(string $id, array $payload = []): array
    {
        return $this->send('POST', $this->endpoint('disable_listing_endpoint', ['listing_id' => $id]), [
            'ticket_id' => $id,
            ...$payload,
        ], [], 'disable_listing');
    }

    public function canDeleteListing(): bool
    {
        $value = $this->setting('delete_listing_endpoint');

        return is_string($value) && trim($value) !== '';
    }

    public function deleteListing(string $id, array $payload = []): array
    {
        return $this->send('POST', $this->endpoint('delete_listing_endpoint', ['listing_id' => $id]), [
            'ticket_id' => $id,
            ...$payload,
        ], [], 'delete_listing');
    }

    public function getListing(string $id): array
    {
        return $this->send('GET', $this->endpoint('get_listing_endpoint', ['listing_id' => $id]), ['ticket_id' => $id], [], 'get_listing');
    }

    public function findListingByExternalReference(string $reference): ?array
    {
        $response = $this->send('GET', $this->endpoint('find_listing_endpoint', ['external_reference' => $reference]), [
            'external_reference' => $reference,
        ], [], 'find_listing');
        $candidate = data_get($response, 'listing', data_get($response, 'data', $response));

        return is_array($candidate) && $candidate !== [] ? $candidate : null;
    }

    public function canFindListingByExternalReference(): bool
    {
        return filled($this->setting('find_listing_endpoint'));
    }

    public function listTickets(int $matchId): array
    {
        return $this->send('POST', $this->endpoint('get_listing_endpoint'), [
            'match_id' => $matchId,
            'seller_id' => $this->sellerId(),
        ], [], 'list_tickets');
    }

    public function ticketDropdown(int $matchId): array
    {
        return $this->send('POST', $this->endpoint('ticket_dropdown_endpoint'), [
            'match_id' => $matchId,
        ], [], 'ticket_dropdown');
    }

    /**
     * GET /api/booking on the listing host (apiKey auth).
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function fetchBookings(array $query = []): array
    {
        $payload = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $payload[$key] = $value;
        }

        // Include seller_id when configured; listing hosts often scope bookings by it.
        try {
            $payload['seller_id'] ??= $this->sellerId();
        } catch (SellerApiConfigurationException) {
            // apiKey alone may identify the seller.
        }

        return $this->send('GET', $this->endpoint('booking_endpoint'), $payload, [], 'fetch_bookings');
    }

    /**
     * Fetch every booking page from the Seller listing API.
     *
     * SeatsBrokers returns `{ status, total, message, result }` (often without Laravel-style
     * `meta.last_page`). Prefer `total` so we do not stop early when pagination meta is absent.
     *
     * @param  array<string, scalar|null>  $query
     * @return array{result:list<array<string, mixed>>, pages:int, total:?int, listing_base_url:string}
     */
    public function fetchAllBookings(array $query = []): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? $query['limit'] ?? 100);
        if ($perPage < 1) {
            $perPage = 100;
        }

        $allRows = [];
        $pages = 0;
        $reportedTotal = null;
        $maxPages = max(1, (int) config('seller-api.booking_max_pages', 50));
        $listingBaseUrl = $this->listingBaseUrl();

        do {
            $pageQuery = [
                ...$query,
                'page' => $page,
                'per_page' => $perPage,
            ];
            unset($pageQuery['limit']);

            $response = $this->fetchBookings($pageQuery);
            $pages++;

            if ($reportedTotal === null && is_numeric(data_get($response, 'total'))) {
                $reportedTotal = (int) data_get($response, 'total');
            }

            $rows = $this->bookingRowsFromResponse($response);
            foreach ($rows as $row) {
                $allRows[] = $row;
            }

            $lastPage = max(
                1,
                (int) data_get($response, 'meta.last_page', data_get($response, 'results.meta.last_page', $page)),
            );
            $hasMetaMore = $page < $lastPage && $rows !== [];
            $hasTotalMore = $reportedTotal !== null
                && count($allRows) < $reportedTotal
                && $rows !== [];
            $hasMore = $hasMetaMore || $hasTotalMore;
            $page++;
        } while ($hasMore && $pages < $maxPages);

        return [
            'result' => $allRows,
            'pages' => $pages,
            'total' => $reportedTotal,
            'listing_base_url' => $listingBaseUrl,
        ];
    }

    /** Resolved Seller listing host used for ticket/booking calls (sandbox vs production). */
    public function resolvedListingBaseUrl(): string
    {
        return $this->listingBaseUrl();
    }

    /** @return list<array<string, mixed>> */
    private function bookingRowsFromResponse(array $response): array
    {
        $candidates = [
            data_get($response, 'result'),
            data_get($response, 'results'),
            data_get($response, 'results.data'),
            data_get($response, 'results.bookings'),
            data_get($response, 'data'),
            data_get($response, 'data.bookings'),
            data_get($response, 'bookings'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || ! array_is_list($candidate)) {
                continue;
            }

            return array_values(array_filter($candidate, is_array(...)));
        }

        return [];
    }

    public function sellerId(): int
    {
        $sellerId = filter_var($this->setting('seller_id'), FILTER_VALIDATE_INT);
        if ($sellerId === false || $sellerId < 1) {
            throw new SellerApiConfigurationException('SELLER_API_SELLER_ID must be a positive integer.');
        }

        return $sellerId;
    }

    /**
     * @param  array<string, scalar|null>  $filters
     * @return array<string, mixed>
     */
    public function fetchEventsPage(int $page = 1, ?int $perPage = null, array $filters = [], ?string $environment = null): array
    {
        $query = ['page' => max(1, $page)];
        if ($perPage !== null && $perPage > 0) {
            $query['per_page'] = $perPage;
        }

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $query[$key] = $value;
        }

        return $this->sendCatalog('GET', (string) $this->setting('events_endpoint', '/api/events'), $query, $environment);
    }

    /**
     * Search catalog events by name (GET /api/events?event_name=…&limit=…&lang=…).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchEventsByName(
        string $eventName,
        ?int $limit = null,
        ?string $lang = null,
        ?string $environment = null,
    ): array {
        $eventName = trim($eventName);
        if ($eventName === '') {
            return [];
        }

        $limit ??= (int) $this->setting('catalog_search_limit', 10);
        $limit = max(1, min(50, $limit));
        $lang ??= (string) $this->setting('catalog_lang', 'en');

        $response = $this->fetchEventsPage(1, null, [
            'event_name' => $eventName,
            'limit' => $limit,
            'lang' => $lang,
        ], $environment);

        $batch = data_get($response, 'data');

        return is_array($batch)
            ? array_values(array_filter($batch, is_array(...)))
            : [];
    }

    public function catalogEventSearchPreviewUrl(
        string $eventName,
        ?int $limit = null,
        ?string $environment = null,
        ?string $lang = null,
    ): string {
        $eventName = trim($eventName);
        $limit ??= (int) $this->setting('catalog_search_limit', 10);
        $limit = max(1, min(50, $limit));
        $lang ??= (string) $this->setting('catalog_lang', 'en');

        return $this->catalogEventsPreviewUrl([
            'event_name' => $eventName,
            'limit' => $limit,
            'lang' => $lang,
        ], $environment);
    }

    /** @return list<array<string, mixed>> */
    public function fetchAllEvents(?int $perPage = null, ?string $environment = null): array
    {
        return $this->fetchAllCatalogPages(
            fn (int $page, ?int $size): array => $this->fetchEventsPage($page, $size, [], $environment),
            'events',
            $perPage,
        );
    }

    /** @return list<array<string, mixed>> */
    public function fetchEventsByTournament(int $tournamentId, ?int $perPage = null, ?string $environment = null): array
    {
        if ($tournamentId < 1) {
            throw new \InvalidArgumentException('Tournament id must be a positive integer.');
        }

        return $this->fetchAllCatalogPages(
            fn (int $page, ?int $size): array => $this->fetchEventsPage($page, $size, [
                'tournament_id' => SeatsbrokerCatalogId::hash($tournamentId),
            ], $environment),
            'events',
            $perPage,
        );
    }

    /**
     * Absolute GET URL for the external events catalog (Bearer auth; token omitted).
     *
     * @param  array<string, scalar|null>  $filters
     */
    public function catalogEventsPreviewUrl(array $filters = [], ?string $environment = null): string
    {
        $query = ['page' => 1];

        if (isset($filters['event_name']) && trim((string) $filters['event_name']) !== '') {
            $limit = (int) ($filters['limit'] ?? $this->setting('catalog_search_limit', 10));
            $query['limit'] = max(1, min(50, $limit));
            $query['lang'] = (string) ($filters['lang'] ?? $this->setting('catalog_lang', 'en'));
            $query['event_name'] = trim((string) $filters['event_name']);
        } else {
            $perPage = (int) $this->setting('catalog_per_page', 100);
            $query['per_page'] = max(1, $perPage);

            foreach ($filters as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $query[$key] = $value;
            }
        }

        $endpoint = (string) $this->setting('events_endpoint', '/api/events');
        $base = $environment !== null
            ? $this->catalogBaseUrlForEnvironment($environment)
            : $this->catalogBaseUrl();
        $path = $this->resolveCatalogPath($endpoint, $base);

        return rtrim($base, '/').$path.'?'.http_build_query($query);
    }

    public function catalogBaseUrlForEnvironment(string $environment): string
    {
        $overrideKey = match ($environment) {
            'sandbox' => IntegrationSettingService::SELLER_CATALOG_SANDBOX_BASE_URL,
            'production' => IntegrationSettingService::SELLER_CATALOG_PRODUCTION_BASE_URL,
            default => throw new \InvalidArgumentException("Unknown Seller API catalog environment: {$environment}"),
        };

        $override = app(IntegrationSettingService::class)->value($overrideKey);
        if (filled($override)) {
            return $this->normalizeHostBaseUrl($override);
        }

        $key = match ($environment) {
            'sandbox' => 'catalog_sandbox_base_url',
            'production' => 'catalog_production_base_url',
            default => throw new \InvalidArgumentException("Unknown Seller API catalog environment: {$environment}"),
        };

        $baseUrl = $this->normalizeHostBaseUrl((string) $this->setting($key));
        if ($baseUrl !== '') {
            return $baseUrl;
        }

        return $this->catalogBaseUrl();
    }

    public function defaultCatalogEnvironment(): string
    {
        return app(ApiEnvironmentService::class)->sellerCatalogEnvironment();
    }

    /**
     * Fetch one catalog event by its hashed event_id (GET /api/events?event_id=…).
     *
     * @return array<string, mixed>|null
     */
    public function fetchEventById(string $eventId, ?string $environment = null): ?array
    {
        $eventId = trim($eventId);
        if ($eventId === '') {
            return null;
        }

        $response = $this->fetchEventsPage(1, 1, ['event_id' => $eventId], $environment);
        $batch = data_get($response, 'data');
        if (! is_array($batch) || $batch === []) {
            return null;
        }

        $first = $batch[0] ?? null;

        return is_array($first) ? $first : null;
    }

    /** @return array<string, mixed> */
    public function fetchVenuesPage(
        int $page = 1,
        ?int $perPage = null,
        array $filters = [],
        ?string $environment = null,
    ): array {
        $query = ['page' => max(1, $page)];
        if ($perPage !== null && $perPage > 0) {
            $query['per_page'] = $perPage;
        }

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $query[$key] = $value;
        }

        return $this->sendCatalog('GET', (string) $this->setting('venues_endpoint', '/api/venues'), $query, $environment);
    }

    /**
     * Fetch one catalog venue by legacy stadium id (GET /api/venues?s_id=… or ?venue_id=…).
     *
     * @return array<string, mixed>|null
     */
    public function fetchVenueByStadiumId(int $stadiumId, ?string $environment = null): ?array
    {
        if ($stadiumId < 1) {
            return null;
        }

        $filters = [
            ['s_id' => $stadiumId],
            ['venue_id' => SeatsbrokerCatalogId::hash($stadiumId)],
        ];

        foreach ($filters as $filter) {
            $response = $this->fetchVenuesPage(1, 1, $filter, $environment);
            $batch = data_get($response, 'data');
            if (! is_array($batch) || $batch === []) {
                continue;
            }

            $first = $batch[0] ?? null;

            return is_array($first) ? $first : null;
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function fetchAllVenues(?int $perPage = null, ?string $environment = null): array
    {
        return $this->fetchAllCatalogPages(
            fn (int $page, ?int $size): array => $this->fetchVenuesPage($page, $size, [], $environment),
            'venues',
            $perPage,
        );
    }

    /**
     * @param  callable(int, ?int): array<string, mixed>  $fetchPage
     * @return list<array<string, mixed>>
     */
    private function fetchAllCatalogPages(callable $fetchPage, string $resource, ?int $perPage = null): array
    {
        $perPage ??= (int) $this->setting('catalog_per_page', 100);
        $rows = [];
        $page = 1;
        $lastPage = 1;

        do {
            $response = $fetchPage($page, $perPage);
            $batch = data_get($response, 'data');
            if (! is_array($batch)) {
                throw new SellerApiRequestException("Seller API {$resource} response is missing a data array.");
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = [...$rows, ...array_values(array_filter($batch, is_array(...)))];

            $lastPage = max(1, (int) data_get($response, 'meta.last_page', 1));
            $page++;
        } while ($page <= $lastPage);

        return $rows;
    }

    /** @param array<string, scalar|null> $query @return array<string, mixed> */
    private function sendCatalog(string $method, string $endpoint, array $query = [], ?string $environment = null): array
    {
        $operation = 'fetch_catalog';
        $resolvedEnvironment = $this->resolvedCatalogEnvironment($environment);
        $path = $this->catalogEndpoint($endpoint, $resolvedEnvironment);
        $url = $this->absoluteCatalogUrl($path, $query, $resolvedEnvironment);
        $apiKey = $this->catalogApiKeyForEnvironment($resolvedEnvironment);
        $requestHeaders = ['Authorization' => 'Bearer '.$apiKey];

        try {
            $response = $this->catalogRequest($resolvedEnvironment)->send($method, $path, [
                'query' => $query,
            ]);
            $this->debugger->record($operation, $method, $url, $requestHeaders, $query, $response);

            if ($response->failed()) {
                throw new SellerApiRequestException(
                    'Seller API catalog request failed with HTTP '.$response->status().'.',
                    $response->status(),
                    $this->catalogFailureContext($environment, $url, $response),
                );
            }

            $json = $response->json();
            if (! is_array($json)) {
                throw new SellerApiRequestException(
                    'Seller API catalog response is not a JSON object or array.',
                    context: $this->catalogFailureContext($environment, $url, $response),
                );
            }

            return $json;
        } catch (SellerApiRequestException|SellerApiConfigurationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $response = $exception instanceof RequestException ? $exception->response : null;

            if ($response !== null && $response->failed()) {
                $this->debugger->recordTransportFailure(
                    $operation,
                    $method,
                    $url,
                    $requestHeaders,
                    $query,
                    $response,
                    $exception->getMessage(),
                );

                throw new SellerApiRequestException(
                    'Seller API catalog request failed with HTTP '.$response->status().'.',
                    $response->status(),
                    $this->catalogFailureContext($environment, $url, $response, $exception->getMessage()),
                    previous: $exception,
                );
            }

            $this->debugger->recordTransportFailure(
                $operation,
                $method,
                $url,
                $requestHeaders,
                $query,
                $response,
                $exception->getMessage(),
            );

            throw new SellerApiRequestException(
                $this->transportFailureMessage('Seller API catalog request', $exception),
                $response?->status(),
                $this->catalogFailureContext($environment, $url, $response, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    private function catalogRequest(?string $environment = null): PendingRequest
    {
        if (! $this->setting('enabled', true)) {
            throw new SellerApiConfigurationException('Seller API integration is disabled.');
        }

        $environment = $this->resolvedCatalogEnvironment($environment);
        $apiKey = $this->catalogApiKeyForEnvironment($environment);
        if (blank($apiKey)) {
            throw new SellerApiConfigurationException("Seller API integration is enabled but no catalog API token is configured for {$environment}. Set SELLER_API_KEY or the environment-specific catalog token.");
        }

        $baseUrl = $this->catalogBaseUrlForEnvironment($environment);
        if (blank($baseUrl)) {
            throw new SellerApiConfigurationException("Seller API catalog base URL is not configured for {$environment}. Set SELLER_API_BASE_URL or the environment-specific catalog URL.");
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->withToken($apiKey)
            ->connectTimeout((int) $this->setting('connect_timeout', 10))
            ->timeout((int) $this->setting('timeout', 30))
            ->retry(
                (int) $this->setting('retry_times', 3),
                (int) $this->setting('retry_delay_ms', 1000),
                $this->shouldRetryHttpException(...),
            );
    }

    private function resolvedCatalogEnvironment(?string $environment): string
    {
        if ($environment === ApiEnvironmentService::ENV_SANDBOX || $environment === ApiEnvironmentService::ENV_PRODUCTION) {
            return $environment;
        }

        return app(ApiEnvironmentService::class)->sellerCatalogEnvironment();
    }

    private function catalogApiKeyForEnvironment(string $environment): string
    {
        if ($environment === ApiEnvironmentService::ENV_SANDBOX) {
            $override = app(IntegrationSettingService::class)->value(IntegrationSettingService::SELLER_CATALOG_SANDBOX_API_KEY);
            if (filled($override)) {
                return trim($override);
            }
        } elseif ($environment === ApiEnvironmentService::ENV_PRODUCTION) {
            $override = app(IntegrationSettingService::class)->value(IntegrationSettingService::SELLER_CATALOG_PRODUCTION_API_KEY);
            if (filled($override)) {
                return trim($override);
            }
        }

        return trim((string) $this->setting('api_key', ''));
    }

    private function catalogBaseUrl(): string
    {
        return $this->catalogBaseUrlForEnvironment(app(ApiEnvironmentService::class)->sellerCatalogEnvironment());
    }

    private function listingBaseUrl(): string
    {
        $resolved = app(ApiEnvironmentService::class)->sellerListingBaseUrl();
        if (is_string($resolved) && trim($resolved) !== '') {
            return $this->normalizeHostBaseUrl($resolved);
        }

        $explicit = $this->setting('listing_base_url');
        if (is_string($explicit) && trim($explicit) !== '') {
            return $this->normalizeHostBaseUrl($explicit);
        }

        return app(ApiEnvironmentService::class)->sellerListingEnvironment() === ApiEnvironmentService::ENV_SANDBOX
            ? 'https://sandbox-sellerapi.seatsbrokers.com'
            : 'https://sellerapi.seatsbrokers.com';
    }

    private function listingApiKey(): string
    {
        $listingKey = $this->setting('listing_api_key');
        if (is_string($listingKey) && trim($listingKey) !== '') {
            return trim($listingKey);
        }

        return trim((string) $this->setting('api_key', ''));
    }

    private function normalizeHostBaseUrl(string $base): string
    {
        $base = rtrim(trim($base), '/');
        if (preg_match('#/api$#i', $base)) {
            $base = (string) preg_replace('#/api$#i', '', $base);
        }

        return rtrim($base, '/');
    }

    /** @param array<string, mixed> $json */
    private function assertListingSuccess(array $json): void
    {
        foreach ([$json, data_get($json, 'results')] as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            $status = data_get($layer, 'status');
            if ($status === 0 || $status === '0') {
                $message = trim((string) (
                    data_get($layer, 'error')
                    ?: data_get($layer, 'message')
                    ?: data_get($json, 'error')
                    ?: data_get($json, 'message')
                    ?: 'Seller API request failed.'
                ));
                throw new SellerApiRequestException($message !== '' ? $message : 'Seller API request failed.');
            }
        }
    }

    /** @param array<string, mixed> $json @return array<string, mixed> */
    private function normalizeListingResponse(array $json): array
    {
        $results = data_get($json, 'results');
        if (! array_key_exists('result', $json) && is_array($results)) {
            if (isset($results['ticket_type']) || isset($results['category']) || isset($results['split_type'])) {
                $json['result'] = $results;
            }
        }

        foreach (['ticket_id', 'id', 'listing_id'] as $key) {
            if (! isset($json[$key]) && is_scalar(data_get($json, "results.{$key}"))) {
                $json[$key] = data_get($json, "results.{$key}");
            }
        }

        return $json;
    }

    private function catalogEndpoint(string $endpoint, ?string $environment = null): string
    {
        $base = $this->catalogBaseUrlForEnvironment($this->resolvedCatalogEnvironment($environment));

        return $this->resolveCatalogPath($endpoint, $base);
    }

    private function resolveCatalogPath(string $endpoint, string $baseUrl): string
    {
        $path = '/'.ltrim($endpoint, '/');
        $base = rtrim($baseUrl, '/');
        if (preg_match('#/api$#i', $base) && str_starts_with(strtolower($path), '/api/')) {
            return substr($path, 4) ?: '/';
        }

        return $path;
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        $overrideKey = match ($key) {
            'listing_base_url' => IntegrationSettingService::SELLER_LISTING_BASE_URL,
            'listing_api_key' => IntegrationSettingService::SELLER_LISTING_API_KEY,
            default => null,
        };
        if ($overrideKey !== null) {
            $override = app(IntegrationSettingService::class)->value($overrideKey);
            if (filled($override)) {
                return $override;
            }
        }

        $legacy = config("services.seller_api.{$key}");

        return filled($legacy) ? $legacy : config("seller-api.{$key}", $default);
    }

    private function absoluteListingUrl(string $endpoint): string
    {
        return rtrim($this->listingBaseUrl(), '/').'/'.ltrim($endpoint, '/');
    }

    /** @param  array<string, scalar|null>  $query */
    private function absoluteCatalogUrl(string $path, array $query = [], ?string $environment = null): string
    {
        $base = $this->catalogBaseUrlForEnvironment($this->resolvedCatalogEnvironment($environment));
        $url = rtrim($base, '/').'/'.ltrim($path, '/');
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $url;
    }

    /** @return array<string, mixed> */
    private function catalogFailureContext(
        ?string $environment,
        string $url,
        ?Response $response = null,
        ?string $cause = null,
    ): array {
        return $this->requestFailureContext([
            'environment' => $environment,
            'request_url' => $url,
            'http_status' => $response?->status(),
            'response_body' => $this->responseBodyForDebug($response),
            'cause' => filled($cause) ? trim($cause) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function listingFailureContext(string $url, ?Response $response = null, ?string $cause = null): array
    {
        return $this->requestFailureContext([
            'request_url' => $url,
            'http_status' => $response?->status(),
            'response_body' => $this->responseBodyForDebug($response),
            'cause' => filled($cause) ? trim($cause) : null,
        ]);
    }

    /** @param  array<string, mixed>  $context */
    private function requestFailureContext(array $context): array
    {
        return array_filter(
            $context,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    private function responseBodyForDebug(?Response $response): mixed
    {
        if ($response === null) {
            return null;
        }

        $json = $response->json();
        if (is_array($json)) {
            return $json;
        }

        $body = $response->body();
        if (! is_string($body) || $body === '') {
            return null;
        }

        if (strlen($body) <= 2000) {
            return $body;
        }

        return substr($body, 0, 2000).'…';
    }

    private function transportFailureMessage(string $label, \Throwable $exception): string
    {
        $cause = trim($exception->getMessage());
        if ($cause === '') {
            return "{$label} could not be completed.";
        }

        if ($exception instanceof ConnectionException) {
            return "{$label} could not connect: {$cause}";
        }

        return "{$label} could not be completed: {$cause}";
    }

    /**
     * Retry timeouts, rate limits, and server errors. Client validation
     * failures (missing ticket_category, 404, 422, …) must not be retried.
     */
    private function shouldRetryHttpException(\Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response?->status();
        if ($status === null) {
            return true;
        }

        return $status === 408 || $status === 429 || $status >= 500;
    }
}
