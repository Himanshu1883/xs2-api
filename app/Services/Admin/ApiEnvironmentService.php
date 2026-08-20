<?php

namespace App\Services\Admin;

class ApiEnvironmentService
{
    public const XS2_ACTIVE_ENVIRONMENT = 'XS2_ACTIVE_ENVIRONMENT';

    public const XS2_ORDERS_ACTIVE_ENVIRONMENT = 'XS2_ORDERS_ACTIVE_ENVIRONMENT';

    public const SELLER_CATALOG_ACTIVE_ENVIRONMENT = 'SELLER_CATALOG_ACTIVE_ENVIRONMENT';

    public const SELLER_LISTING_ACTIVE_ENVIRONMENT = 'SELLER_LISTING_ACTIVE_ENVIRONMENT';

    public const ENV_SANDBOX = 'sandbox';

    public const ENV_PRODUCTION = 'production';

    public const POINT_XS2_EVENTS = 'xs2_events';

    public const POINT_XS2_INVENTORY = 'xs2_inventory';

    public const POINT_XS2_VENUES = 'xs2_venues';

    public const POINT_XS2_CREATE_ORDER = 'xs2_create_order';

    public const POINT_SB_CATALOG = 'sb_catalog';

    public const POINT_SB_LISTING = 'sb_listing';

    /** @var list<string> */
    public const VALID_ENVIRONMENTS = [
        self::ENV_SANDBOX,
        self::ENV_PRODUCTION,
    ];

    public function __construct(
        private readonly IntegrationSettingService $integrationSettings,
    ) {}

    /** @return list<array<string, mixed>> */
    public function integrationPoints(): array
    {
        return [
            $this->buildPoint(
                self::POINT_XS2_EVENTS,
                'XS2 Events',
                'Event catalog sync (xs2:sync-events, event mapping).',
                self::XS2_ACTIVE_ENVIRONMENT,
                fn (): array => $this->xs2Connection(),
                [$this->endpoint('GET', config('xs2.events_endpoint', '/v1/events'), 'XS2_EVENTS_ENDPOINT')],
            ),
            $this->buildPoint(
                self::POINT_XS2_INVENTORY,
                'XS2 Tickets / Inventory',
                'Ticket and inventory sync (xs2:sync-inventory, listing push prerequisites).',
                self::XS2_ACTIVE_ENVIRONMENT,
                fn (): array => $this->xs2Connection(),
                [
                    $this->endpoint('GET', config('xs2.tickets_endpoint', '/v1/tickets'), 'XS2_TICKETS_ENDPOINT'),
                    $this->endpoint('GET', config('xs2.categories_endpoint', '/v1/categories'), 'XS2_CATEGORIES_ENDPOINT'),
                ],
            ),
            $this->buildPoint(
                self::POINT_XS2_VENUES,
                'XS2 Venues',
                'Venue and stadium sync (xs2:sync-venues, stadium mapping).',
                self::XS2_ACTIVE_ENVIRONMENT,
                fn (): array => $this->xs2Connection(),
                [$this->endpoint('GET', config('xs2.venues_endpoint', '/v1/venues'), 'XS2_VENUES_ENDPOINT')],
            ),
            $this->buildPoint(
                self::POINT_XS2_CREATE_ORDER,
                'XS2 Create Order API',
                'SB→XS2 order creation (reservation + booking) when SB bookings sync. Production uses api.xs2event.com when implemented.',
                self::XS2_ORDERS_ACTIVE_ENVIRONMENT,
                fn (): array => $this->xs2OrdersConnection(),
                [
                    $this->endpoint('POST', config('xs2.reservations_endpoint', '/v1/reservations'), 'XS2_RESERVATIONS_ENDPOINT'),
                    $this->endpoint('POST', config('xs2.sandbox.bookings_endpoint', '/v1/bookings'), 'XS2_SANDBOX_BOOKINGS_ENDPOINT'),
                    $this->endpoint('GET', config('xs2.sandbox.bookingorders_endpoint', '/v1/bookingorders'), 'XS2_SANDBOX_BOOKINGORDERS_ENDPOINT'),
                ],
            ),
            $this->buildPoint(
                self::POINT_SB_CATALOG,
                'Seats Broker API (catalog)',
                'External catalog for event search, import, and venue sync (Bearer token).',
                self::SELLER_CATALOG_ACTIVE_ENVIRONMENT,
                fn (): array => $this->sellerCatalogConnection(),
                [
                    $this->endpoint('GET', config('seller-api.events_endpoint', '/api/events'), 'SELLER_API_EVENTS_ENDPOINT'),
                    $this->endpoint('GET', config('seller-api.venues_endpoint', '/api/venues'), 'SELLER_API_VENUES_ENDPOINT'),
                ],
            ),
            $this->buildPoint(
                self::POINT_SB_LISTING,
                'Listing publish on SB',
                'Multipart seller API for publish, unpublish, delete, and ticket_dropdown.',
                self::SELLER_LISTING_ACTIVE_ENVIRONMENT,
                fn (): array => $this->sellerListingConnection(),
                [
                    $this->endpoint('POST', config('seller-api.create_listing_endpoint', '/api/ticket/create'), 'SELLER_API_CREATE_LISTING_ENDPOINT'),
                    $this->endpoint('POST', config('seller-api.ticket_dropdown_endpoint', '/api/ticket_dropdown'), 'SELLER_API_TICKET_DROPDOWN_ENDPOINT'),
                ],
            ),
        ];
    }

    public function xs2Environment(): string
    {
        return $this->environment(self::XS2_ACTIVE_ENVIRONMENT) ?? $this->inferXs2Environment();
    }

    public function xs2OrdersEnvironment(): string
    {
        return $this->environment(self::XS2_ORDERS_ACTIVE_ENVIRONMENT) ?? self::ENV_SANDBOX;
    }

    public function sellerCatalogEnvironment(): string
    {
        return $this->environment(self::SELLER_CATALOG_ACTIVE_ENVIRONMENT) ?? $this->inferSellerCatalogEnvironment();
    }

    public function sellerListingEnvironment(): string
    {
        return $this->environment(self::SELLER_LISTING_ACTIVE_ENVIRONMENT) ?? $this->inferSellerListingEnvironment();
    }

    public function xs2BaseUrl(): ?string
    {
        if ($this->xs2Environment() === self::ENV_SANDBOX) {
            return $this->effectiveSandboxXs2BaseUrl();
        }

        return $this->effectiveProductionXs2BaseUrl();
    }

    public function xs2ApiKey(): ?string
    {
        if ($this->xs2Environment() === self::ENV_SANDBOX) {
            return $this->effectiveSandboxXs2ApiKey();
        }

        return $this->effectiveProductionXs2ApiKey();
    }

    public function effectiveSandboxXs2BaseUrl(): ?string
    {
        $override = $this->integrationSettings->value(IntegrationSettingService::XS2_SANDBOX_API_URL);
        if (filled($override)) {
            return rtrim(trim($override), '/');
        }

        $fromConfig = config('xs2.sandbox.api_url');

        return is_string($fromConfig) && $fromConfig !== ''
            ? rtrim($fromConfig, '/')
            : 'https://testapi.xs2event.com';
    }

    public function effectiveSandboxXs2ApiKey(): ?string
    {
        $override = $this->integrationSettings->value(IntegrationSettingService::XS2_SANDBOX_API_KEY);
        if (filled($override)) {
            return trim($override);
        }

        $fromConfig = config('xs2.sandbox.api_key');

        return is_string($fromConfig) && trim($fromConfig) !== '' ? trim($fromConfig) : null;
    }

    public function sellerCatalogBaseUrl(string $environment): ?string
    {
        $overrideKey = $environment === self::ENV_SANDBOX
            ? IntegrationSettingService::SELLER_CATALOG_SANDBOX_BASE_URL
            : IntegrationSettingService::SELLER_CATALOG_PRODUCTION_BASE_URL;

        $override = $this->integrationSettings->value($overrideKey);
        if (filled($override)) {
            return rtrim(trim($override), '/');
        }

        $configKey = $environment === self::ENV_SANDBOX
            ? 'catalog_sandbox_base_url'
            : 'catalog_production_base_url';

        $fromConfig = config("seller-api.{$configKey}");

        return is_string($fromConfig) && $fromConfig !== '' ? rtrim($fromConfig, '/') : null;
    }

    public function sellerListingBaseUrl(): ?string
    {
        $override = $this->integrationSettings->value(IntegrationSettingService::SELLER_LISTING_BASE_URL);
        if (filled($override)) {
            return rtrim(trim($override), '/');
        }

        $fromConfig = config('seller-api.listing_base_url');
        if (is_string($fromConfig) && $fromConfig !== '') {
            return rtrim($fromConfig, '/');
        }

        return $this->sellerListingEnvironment() === self::ENV_SANDBOX
            ? 'https://sandbox-sellerapi.seatsbrokers.com'
            : 'https://sellerapi.seatsbrokers.com';
    }

    public function setEnvironment(string $pointId, string $environment): void
    {
        $environment = strtolower(trim($environment));
        if (! in_array($environment, self::VALID_ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException("Unknown environment: {$environment}");
        }

        match ($pointId) {
            self::POINT_XS2_EVENTS, self::POINT_XS2_INVENTORY, self::POINT_XS2_VENUES => $this->integrationSettings->set(
                self::XS2_ACTIVE_ENVIRONMENT,
                $environment,
            ),
            self::POINT_XS2_CREATE_ORDER => $this->integrationSettings->set(
                self::XS2_ORDERS_ACTIVE_ENVIRONMENT,
                $environment,
            ),
            self::POINT_SB_CATALOG => $this->integrationSettings->set(
                self::SELLER_CATALOG_ACTIVE_ENVIRONMENT,
                $environment,
            ),
            self::POINT_SB_LISTING => $this->applySellerListingEnvironment($environment),
            default => throw new \InvalidArgumentException("Unknown integration point: {$pointId}"),
        };
    }

    private function applySellerListingEnvironment(string $environment): void
    {
        $this->integrationSettings->set(
            self::SELLER_LISTING_ACTIVE_ENVIRONMENT,
            $environment,
        );

        $baseUrl = $environment === self::ENV_SANDBOX
            ? 'https://sandbox-sellerapi.seatsbrokers.com'
            : 'https://sellerapi.seatsbrokers.com';

        $this->integrationSettings->set(
            IntegrationSettingService::SELLER_LISTING_BASE_URL,
            $baseUrl,
        );
    }

    private function environment(string $key): ?string
    {
        $value = $this->integrationSettings->value($key);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, self::VALID_ENVIRONMENTS, true) ? $normalized : null;
    }

    private function inferXs2Environment(): string
    {
        $baseUrl = strtolower((string) ($this->effectiveProductionXs2BaseUrl() ?? ''));

        return str_contains($baseUrl, 'testapi') ? self::ENV_SANDBOX : self::ENV_PRODUCTION;
    }

    private function inferSellerCatalogEnvironment(): string
    {
        $baseUrl = strtolower((string) (config('seller-api.base_url') ?? ''));

        return str_contains($baseUrl, 'sandbox') ? self::ENV_SANDBOX : self::ENV_PRODUCTION;
    }

    private function inferSellerListingEnvironment(): string
    {
        $override = $this->integrationSettings->value(IntegrationSettingService::SELLER_LISTING_BASE_URL);
        if (filled($override)) {
            return str_contains(strtolower($override), 'sandbox') ? self::ENV_SANDBOX : self::ENV_PRODUCTION;
        }

        $fromConfig = config('seller-api.listing_base_url');
        $baseUrl = is_string($fromConfig) ? strtolower($fromConfig) : '';

        return str_contains($baseUrl, 'sandbox') ? self::ENV_SANDBOX : self::ENV_PRODUCTION;
    }

    private function effectiveProductionXs2BaseUrl(): ?string
    {
        $override = $this->integrationSettings->value(IntegrationSettingService::XS2_BASE_URL);
        if (filled($override)) {
            return rtrim(trim($override), '/');
        }

        $fromConfig = config('services.xs2.base_url') ?: config('xs2.base_url');

        return is_string($fromConfig) && $fromConfig !== '' ? rtrim($fromConfig, '/') : null;
    }

    private function effectiveProductionXs2ApiKey(): ?string
    {
        $override = $this->integrationSettings->value(IntegrationSettingService::XS2_API_KEY);
        if (filled($override)) {
            return trim($override);
        }

        $fromConfig = config('services.xs2.api_key') ?: config('xs2.api_key');

        return is_string($fromConfig) && trim($fromConfig) !== '' ? trim($fromConfig) : null;
    }

    /** @return array{environment: string, base_url: string|null, api_key: string|null, api_key_configured: bool, api_key_header: string|null, environments: array<string, array{base_url: string|null, api_key: string|null, api_key_configured: bool}>} */
    private function xs2Connection(): array
    {
        return $this->xs2ConnectionForActiveEnvironment($this->xs2Environment());
    }

    /** @return array{environment: string, base_url: string|null, api_key: string|null, api_key_configured: bool, api_key_header: string|null, environments: array<string, array{base_url: string|null, api_key: string|null, api_key_configured: bool}>} */
    private function xs2OrdersConnection(): array
    {
        return $this->xs2ConnectionForActiveEnvironment($this->xs2OrdersEnvironment());
    }

    /** @return array{environment: string, base_url: string|null, api_key: string|null, api_key_configured: bool, api_key_header: string|null, environments: array<string, array{base_url: string|null, api_key: string|null, api_key_configured: bool}>} */
    private function xs2ConnectionForActiveEnvironment(string $environment): array
    {
        $environments = $this->xs2EnvironmentConnections();

        return [
            'environment' => $environment,
            ...$environments[$environment],
            'api_key_header' => (string) (config('xs2.api_key_header') ?? 'X-Api-Key'),
            'environments' => $environments,
        ];
    }

    /** @return array<string, array{base_url: string|null, api_key: string|null, api_key_configured: bool}> */
    private function xs2EnvironmentConnections(): array
    {
        return [
            self::ENV_SANDBOX => $this->xs2ConnectionForEnvironment(self::ENV_SANDBOX),
            self::ENV_PRODUCTION => $this->xs2ConnectionForEnvironment(self::ENV_PRODUCTION),
        ];
    }

    /** @return array{base_url: string|null, api_key: string|null, api_key_configured: bool} */
    private function xs2ConnectionForEnvironment(string $environment): array
    {
        if ($environment === self::ENV_SANDBOX) {
            $baseUrl = $this->effectiveSandboxXs2BaseUrl();
            $apiKey = $this->effectiveSandboxXs2ApiKey();
        } else {
            $baseUrl = $this->effectiveProductionXs2BaseUrl();
            $apiKey = $this->effectiveProductionXs2ApiKey();
        }

        return [
            'base_url' => $baseUrl,
            'api_key' => $this->maskApiKey($apiKey),
            'api_key_configured' => filled($apiKey),
        ];
    }

    /** @return array{environment: string, base_url: string|null, api_key: string|null, api_key_configured: bool, api_key_header: string|null, environments: array<string, array{base_url: string|null, api_key: string|null, api_key_configured: bool}>} */
    private function sellerCatalogConnection(): array
    {
        $environment = $this->sellerCatalogEnvironment();
        $environments = $this->sellerCatalogEnvironmentConnections();

        return [
            'environment' => $environment,
            ...$environments[$environment],
            'api_key_header' => 'Authorization (Bearer)',
            'environments' => $environments,
        ];
    }

    /** @return array<string, array{base_url: string|null, api_key: string|null, api_key_configured: bool}> */
    private function sellerCatalogEnvironmentConnections(): array
    {
        return [
            self::ENV_SANDBOX => $this->sellerCatalogConnectionForEnvironment(self::ENV_SANDBOX),
            self::ENV_PRODUCTION => $this->sellerCatalogConnectionForEnvironment(self::ENV_PRODUCTION),
        ];
    }

    /** @return array{base_url: string|null, api_key: string|null, api_key_configured: bool} */
    private function sellerCatalogConnectionForEnvironment(string $environment): array
    {
        $apiKey = $this->sellerCatalogApiKey($environment);

        return [
            'base_url' => $this->sellerCatalogBaseUrl($environment),
            'api_key' => $this->maskApiKey($apiKey),
            'api_key_configured' => filled($apiKey),
        ];
    }

    /** @return array{environment: string, base_url: string|null, api_key: string|null, api_key_configured: bool, api_key_header: string|null, environments: array<string, array{base_url: string|null, api_key: string|null, api_key_configured: bool}>} */
    private function sellerListingConnection(): array
    {
        $environment = $this->sellerListingEnvironment();
        $environments = $this->sellerListingEnvironmentConnections();

        return [
            'environment' => $environment,
            ...$environments[$environment],
            'api_key_header' => (string) (config('seller-api.api_key_header') ?? 'apiKey'),
            'environments' => $environments,
        ];
    }

    /** @return array<string, array{base_url: string|null, api_key: string|null, api_key_configured: bool}> */
    private function sellerListingEnvironmentConnections(): array
    {
        $apiKey = $this->sellerListingApiKey();
        $maskedApiKey = $this->maskApiKey($apiKey);
        $apiKeyConfigured = filled($apiKey);

        return [
            self::ENV_SANDBOX => [
                'base_url' => $this->sellerListingBaseUrlForEnvironment(self::ENV_SANDBOX),
                'api_key' => $maskedApiKey,
                'api_key_configured' => $apiKeyConfigured,
            ],
            self::ENV_PRODUCTION => [
                'base_url' => $this->sellerListingBaseUrlForEnvironment(self::ENV_PRODUCTION),
                'api_key' => $maskedApiKey,
                'api_key_configured' => $apiKeyConfigured,
            ],
        ];
    }

    private function sellerListingBaseUrlForEnvironment(string $environment): ?string
    {
        if ($this->sellerListingEnvironment() === $environment) {
            return $this->sellerListingBaseUrl();
        }

        return $environment === self::ENV_SANDBOX
            ? 'https://sandbox-sellerapi.seatsbrokers.com'
            : 'https://sellerapi.seatsbrokers.com';
    }

    private function sellerCatalogApiKey(string $environment): ?string
    {
        $overrideKey = $environment === self::ENV_SANDBOX
            ? IntegrationSettingService::SELLER_CATALOG_SANDBOX_API_KEY
            : IntegrationSettingService::SELLER_CATALOG_PRODUCTION_API_KEY;

        $override = $this->integrationSettings->value($overrideKey);
        if (filled($override)) {
            return trim($override);
        }

        $fallback = config('seller-api.api_key');

        return is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;
    }

    private function sellerListingApiKey(): ?string
    {
        $override = $this->integrationSettings->value(IntegrationSettingService::SELLER_LISTING_API_KEY);
        if (filled($override)) {
            return trim($override);
        }

        $listingKey = config('seller-api.listing_api_key');
        if (is_string($listingKey) && trim($listingKey) !== '') {
            return trim($listingKey);
        }

        $fallback = config('seller-api.api_key');

        return is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;
    }

    private function maskApiKey(?string $plain): ?string
    {
        if ($plain === null || trim($plain) === '') {
            return null;
        }

        return $this->integrationSettings->maskPlain(trim($plain));
    }

    /**
     * @param  callable(): array{environment: string, base_url: string|null, api_key: string|null, api_key_configured: bool, api_key_header: string|null}  $connectionResolver
     * @param  list<array{method: string, path: string, env: string|null}>  $endpoints
     * @return array<string, mixed>
     */
    private function buildPoint(
        string $id,
        string $name,
        string $description,
        string $settingKey,
        callable $connectionResolver,
        array $endpoints,
    ): array {
        $connection = $connectionResolver();

        return [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'setting_key' => $settingKey,
            'shared_with' => $this->sharedWithLabel($id),
            ...$connection,
            'endpoints' => $endpoints,
        ];
    }

    /** @return array{method: string, path: string, env: string|null} */
    private function endpoint(string $method, ?string $path, ?string $env): array
    {
        return [
            'method' => $method,
            'path' => $path ?? '—',
            'env' => $env,
        ];
    }

    private function sharedWithLabel(string $id): ?string
    {
        if (in_array($id, [self::POINT_XS2_EVENTS, self::POINT_XS2_INVENTORY, self::POINT_XS2_VENUES], true)) {
            return 'All XS2 sync flows share one API host (events, tickets, venues).';
        }

        return null;
    }
}
