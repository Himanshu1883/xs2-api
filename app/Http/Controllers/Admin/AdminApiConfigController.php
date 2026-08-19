<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventMapping;
use App\Services\Admin\ApiEnvironmentService;
use App\Services\Admin\CronConfigService;
use App\Services\Admin\IntegrationSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminApiConfigController extends Controller
{
    public function __construct(
        private readonly IntegrationSettingService $integrationSettings,
        private readonly CronConfigService $cronConfig,
        private readonly ApiEnvironmentService $apiEnvironment,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        return response()->json([
            'message' => 'Integration API configuration.',
            'data' => [
                'integrations' => [
                    $this->providerBackendIntegration(),
                    $this->xs2Integration(),
                    $this->sellerApiIntegration(),
                ],
            ],
        ]);
    }

    public function updateSellerApi(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'listing_base_url' => ['required', 'string', 'url', 'max:500'],
            'listing_api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $this->integrationSettings->set(
            IntegrationSettingService::SELLER_LISTING_BASE_URL,
            $validated['listing_base_url'],
            secret: false,
        );

        if (filled($validated['listing_api_key'] ?? null)) {
            $this->integrationSettings->set(
                IntegrationSettingService::SELLER_LISTING_API_KEY,
                $validated['listing_api_key'],
                secret: true,
            );
        }

        return response()->json([
            'message' => 'Seatsbrokers Seller API listing settings saved.',
            'data' => [
                'integration' => $this->sellerApiIntegration(),
            ],
        ]);
    }

    public function updateSellerCatalogApi(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'sandbox_base_url' => ['required', 'string', 'url', 'max:500'],
            'sandbox_api_key' => ['nullable', 'string', 'max:500'],
            'production_base_url' => ['required', 'string', 'url', 'max:500'],
            'production_api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $this->integrationSettings->set(
            IntegrationSettingService::SELLER_CATALOG_SANDBOX_BASE_URL,
            $validated['sandbox_base_url'],
            secret: false,
        );

        $this->integrationSettings->set(
            IntegrationSettingService::SELLER_CATALOG_PRODUCTION_BASE_URL,
            $validated['production_base_url'],
            secret: false,
        );

        if (filled($validated['sandbox_api_key'] ?? null)) {
            $this->integrationSettings->set(
                IntegrationSettingService::SELLER_CATALOG_SANDBOX_API_KEY,
                $validated['sandbox_api_key'],
                secret: true,
            );
        }

        if (filled($validated['production_api_key'] ?? null)) {
            $this->integrationSettings->set(
                IntegrationSettingService::SELLER_CATALOG_PRODUCTION_API_KEY,
                $validated['production_api_key'],
                secret: true,
            );
        }

        return response()->json([
            'message' => 'Seatsbrokers Seller API catalog settings saved.',
            'data' => [
                'integration' => $this->sellerApiIntegration(),
            ],
        ]);
    }

    public function showEnvironment(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        return response()->json([
            'message' => 'Active API environments for integration points.',
            'data' => [
                'integrations' => $this->apiEnvironment->integrationPoints(),
            ],
        ]);
    }

    public function updateEnvironment(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'integrations' => ['required', 'array', 'min:1'],
            'integrations.*.id' => ['required', 'string'],
            'integrations.*.environment' => ['required', 'string', 'in:sandbox,production'],
        ]);

        foreach ($validated['integrations'] as $row) {
            $this->apiEnvironment->setEnvironment($row['id'], $row['environment']);
        }

        $this->cronConfig->clearResolvedXs2ConfigurationErrors();

        return response()->json([
            'message' => 'API environment settings saved.',
            'data' => [
                'integrations' => $this->apiEnvironment->integrationPoints(),
            ],
        ]);
    }

    public function updateXs2(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'base_url' => ['required', 'string', 'url', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $this->integrationSettings->set(
            IntegrationSettingService::XS2_BASE_URL,
            $validated['base_url'],
            secret: false,
        );

        // Keep in-process config in sync for the current request lifecycle.
        config([
            'xs2.base_url' => $validated['base_url'],
            'services.xs2.base_url' => $validated['base_url'],
        ]);

        if (filled($validated['api_key'] ?? null)) {
            $this->integrationSettings->set(
                IntegrationSettingService::XS2_API_KEY,
                $validated['api_key'],
                secret: true,
            );
            config([
                'xs2.api_key' => $validated['api_key'],
                'services.xs2.api_key' => $validated['api_key'],
            ]);
        }

        $this->cronConfig->clearResolvedXs2ConfigurationErrors();

        return response()->json([
            'message' => 'XS2 Event API settings saved.',
            'data' => [
                'integration' => $this->xs2Integration(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function providerBackendIntegration(): array
    {
        return [
            'id' => 'provider_backend',
            'name' => 'Seatsbroker Provider API',
            'description' => 'Laravel backend consumed by the provider console through the Next.js /api proxy. Admin auth uses Sanctum session tokens from login, not a static API key.',
            'enabled' => true,
            'base_url' => rtrim((string) config('app.url'), '/'),
            'base_url_env' => 'APP_URL',
            'api_key' => null,
            'api_key_env' => null,
            'api_key_header' => null,
            'api_key_header_env' => null,
            'endpoints' => [
                ['method' => 'POST', 'path' => '/api/auth/login', 'env' => null],
                ['method' => 'POST', 'path' => '/api/auth/logout', 'env' => null],
                ['method' => 'GET', 'path' => '/api/events', 'env' => null],
                ['method' => 'GET', 'path' => '/api/admin/**', 'env' => null],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function xs2Integration(): array
    {
        $config = config('xs2');
        $baseUrl = $this->effectiveXs2BaseUrl();
        $apiKeyDisplay = $this->effectiveXs2ApiKeyMasked();

        return [
            'id' => 'xs2',
            'name' => 'XS2 Event API',
            'description' => 'Upstream inventory, venues, categories, tickets, and reservations for XS2 synchronization. Values saved here override .env and are used by Xs2Client, cron jobs, and inventory sync.',
            'enabled' => (bool) ($config['enabled'] ?? false),
            'base_url' => $baseUrl,
            'base_url_env' => 'XS2_BASE_URL',
            'base_url_editable' => true,
            'api_key' => $apiKeyDisplay,
            'api_key_env' => 'XS2_API_KEY',
            'api_key_editable' => true,
            'api_key_configured' => $this->xs2ApiKeyConfigured(),
            'api_key_header' => $config['api_key_header'] ?? 'X-Api-Key',
            'api_key_header_env' => 'XS2_API_KEY_HEADER',
            'endpoints' => [
                ['method' => 'GET', 'path' => $config['events_endpoint'] ?? '/v1/events', 'env' => 'XS2_EVENTS_ENDPOINT'],
                ['method' => 'GET', 'path' => $config['event_detail_endpoint'] ?? '/v1/events/{event_id}', 'env' => 'XS2_EVENT_DETAIL_ENDPOINT'],
                ['method' => 'GET', 'path' => $config['venues_endpoint'] ?? '/v1/venues', 'env' => 'XS2_VENUES_ENDPOINT'],
                ['method' => 'GET', 'path' => $config['venue_detail_endpoint'] ?? '/v1/venues/{venue_id}', 'env' => 'XS2_VENUE_DETAIL_ENDPOINT'],
                ['method' => 'GET', 'path' => $config['categories_endpoint'] ?? '/v1/categories', 'env' => 'XS2_CATEGORIES_ENDPOINT'],
                ['method' => 'GET', 'path' => $config['category_detail_endpoint'] ?? '/v1/categories/{category_id}', 'env' => 'XS2_CATEGORY_DETAIL_ENDPOINT'],
                ['method' => 'GET', 'path' => $config['tickets_endpoint'] ?? '/v1/tickets', 'env' => 'XS2_TICKETS_ENDPOINT'],
                ['method' => 'GET', 'path' => $config['ticket_detail_endpoint'] ?? '/v1/tickets/{ticket_id}', 'env' => 'XS2_TICKET_DETAIL_ENDPOINT'],
                ['method' => 'POST', 'path' => $config['reservations_endpoint'] ?? '/v1/reservations', 'env' => 'XS2_RESERVATIONS_ENDPOINT'],
                ['method' => 'GET', 'path' => $config['orders_endpoint'] ?? '/v1/orders', 'env' => 'XS2_ORDERS_ENDPOINT'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sellerApiIntegration(): array
    {
        $config = config('seller-api');
        $listingBaseUrl = $this->effectiveListingBaseUrl();
        $listingApiKeyDisplay = $this->effectiveListingApiKeyMasked();

        return [
            'id' => 'seller_api',
            'name' => 'Seatsbrokers Seller API',
            'description' => 'External catalog uses per-environment base URLs (Bearer). Listing publish, unpublish, delete, and ticket_dropdown use the listing base URL below with the apiKey header.',
            'enabled' => (bool) ($config['enabled'] ?? false),
            'base_url' => $config['base_url'] ?? null,
            'base_url_env' => 'SELLER_API_BASE_URL',
            'listing_base_url' => $listingBaseUrl,
            'listing_base_url_env' => 'SELLER_API_LISTING_BASE_URL',
            'listing_base_url_editable' => true,
            'api_key' => $config['api_key'] ?? null,
            'api_key_env' => 'SELLER_API_KEY',
            'listing_api_key' => $listingApiKeyDisplay,
            'listing_api_key_env' => 'SELLER_API_LISTING_API_KEY',
            'listing_api_key_editable' => true,
            'listing_api_key_configured' => $this->listingApiKeyConfigured(),
            'api_key_header' => $config['api_key_header'] ?? 'apiKey',
            'api_key_header_env' => 'SELLER_API_KEY_HEADER',
            'seller_id' => $config['seller_id'] ?? null,
            'seller_id_env' => 'SELLER_API_SELLER_ID',
            'price_uses_minor_units' => (bool) ($config['price_uses_minor_units'] ?? false),
            'price_uses_minor_units_env' => 'SELLER_API_PRICE_USES_MINOR_UNITS',
            'price_unit_mode' => ($config['price_uses_minor_units'] ?? false) ? 'minor_integer' : 'major_decimal',
            'catalog' => [
                'sandbox' => $this->catalogEnvironmentConfig('sandbox'),
                'production' => $this->catalogEnvironmentConfig('production'),
            ],
            'endpoints' => [
                ['method' => 'GET', 'path' => $config['events_endpoint'] ?? '/api/events', 'env' => 'SELLER_API_EVENTS_ENDPOINT'],
                ['method' => 'GET', 'path' => $config['venues_endpoint'] ?? '/api/venues', 'env' => 'SELLER_API_VENUES_ENDPOINT'],
                ['method' => 'POST', 'path' => $config['create_listing_endpoint'] ?? null, 'env' => 'SELLER_API_CREATE_LISTING_ENDPOINT'],
                ['method' => 'POST', 'path' => $config['update_listing_endpoint'] ?? null, 'env' => 'SELLER_API_UPDATE_LISTING_ENDPOINT'],
                ['method' => 'POST', 'path' => $config['disable_listing_endpoint'] ?? null, 'env' => 'SELLER_API_DISABLE_LISTING_ENDPOINT'],
                ['method' => 'GET', 'path' => $config['get_listing_endpoint'] ?? null, 'env' => 'SELLER_API_GET_LISTING_ENDPOINT'],
                ['method' => 'GET', 'path' => $config['find_listing_endpoint'] ?? null, 'env' => 'SELLER_API_FIND_LISTING_ENDPOINT'],
                ['method' => 'POST', 'path' => $config['ticket_dropdown_endpoint'] ?? null, 'env' => 'SELLER_API_TICKET_DROPDOWN_ENDPOINT'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogEnvironmentConfig(string $environment): array
    {
        $envBaseUrlKey = $environment === 'sandbox'
            ? 'SELLER_API_CATALOG_SANDBOX_BASE_URL'
            : 'SELLER_API_CATALOG_PRODUCTION_BASE_URL';
        $envApiKeyKey = $environment === 'sandbox'
            ? 'SELLER_API_CATALOG_SANDBOX_API_KEY'
            : 'SELLER_API_CATALOG_PRODUCTION_API_KEY';

        return [
            'base_url' => $this->effectiveCatalogBaseUrl($environment),
            'base_url_env' => $envBaseUrlKey,
            'base_url_editable' => true,
            'api_key' => $this->effectiveCatalogApiKeyMasked($environment),
            'api_key_env' => $envApiKeyKey,
            'api_key_editable' => true,
            'api_key_configured' => $this->catalogApiKeyConfigured($environment),
        ];
    }

    private function effectiveCatalogBaseUrl(string $environment): ?string
    {
        $overrideKey = $environment === 'sandbox'
            ? IntegrationSettingService::SELLER_CATALOG_SANDBOX_BASE_URL
            : IntegrationSettingService::SELLER_CATALOG_PRODUCTION_BASE_URL;
        $override = $this->integrationSettings->value($overrideKey);
        if (filled($override)) {
            return $override;
        }

        $configKey = $environment === 'sandbox' ? 'catalog_sandbox_base_url' : 'catalog_production_base_url';
        $fromConfig = config("seller-api.{$configKey}");

        return is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;
    }

    private function effectiveCatalogApiKeyMasked(string $environment): ?string
    {
        $overrideKey = $environment === 'sandbox'
            ? IntegrationSettingService::SELLER_CATALOG_SANDBOX_API_KEY
            : IntegrationSettingService::SELLER_CATALOG_PRODUCTION_API_KEY;

        if ($this->integrationSettings->hasOverride($overrideKey)) {
            return $this->integrationSettings->masked($overrideKey);
        }

        $fallback = config('seller-api.api_key');
        if (! is_string($fallback) || trim($fallback) === '') {
            return null;
        }

        return $this->integrationSettings->maskPlain(trim($fallback));
    }

    private function catalogApiKeyConfigured(string $environment): bool
    {
        $overrideKey = $environment === 'sandbox'
            ? IntegrationSettingService::SELLER_CATALOG_SANDBOX_API_KEY
            : IntegrationSettingService::SELLER_CATALOG_PRODUCTION_API_KEY;

        if ($this->integrationSettings->hasOverride($overrideKey)) {
            return true;
        }

        return filled(config('seller-api.api_key'));
    }

    private function effectiveListingBaseUrl(): ?string
    {
        $override = $this->integrationSettings->value(IntegrationSettingService::SELLER_LISTING_BASE_URL);
        if (filled($override)) {
            return $override;
        }

        $fromConfig = config('seller-api.listing_base_url');

        return is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;
    }

    private function effectiveListingApiKeyMasked(): ?string
    {
        if ($this->integrationSettings->hasOverride(IntegrationSettingService::SELLER_LISTING_API_KEY)) {
            return $this->integrationSettings->masked(IntegrationSettingService::SELLER_LISTING_API_KEY);
        }

        $fallback = config('seller-api.listing_api_key') ?: config('seller-api.api_key');
        if (! is_string($fallback) || trim($fallback) === '') {
            return null;
        }

        return $this->integrationSettings->maskPlain(trim($fallback));
    }

    private function listingApiKeyConfigured(): bool
    {
        if ($this->integrationSettings->hasOverride(IntegrationSettingService::SELLER_LISTING_API_KEY)) {
            return true;
        }

        return filled(config('seller-api.listing_api_key')) || filled(config('seller-api.api_key'));
    }

    private function effectiveXs2BaseUrl(): ?string
    {
        $override = $this->integrationSettings->value(IntegrationSettingService::XS2_BASE_URL);
        if (filled($override)) {
            return $override;
        }

        $fromConfig = config('services.xs2.base_url') ?: config('xs2.base_url');

        return is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;
    }

    private function effectiveXs2ApiKeyMasked(): ?string
    {
        if ($this->integrationSettings->hasOverride(IntegrationSettingService::XS2_API_KEY)) {
            return $this->integrationSettings->masked(IntegrationSettingService::XS2_API_KEY);
        }

        $fallback = config('services.xs2.api_key') ?: config('xs2.api_key');
        if (! is_string($fallback) || trim($fallback) === '') {
            return null;
        }

        return $this->integrationSettings->maskPlain(trim($fallback));
    }

    private function xs2ApiKeyConfigured(): bool
    {
        if ($this->integrationSettings->hasOverride(IntegrationSettingService::XS2_API_KEY)) {
            return true;
        }

        return filled(config('services.xs2.api_key')) || filled(config('xs2.api_key'));
    }
}
