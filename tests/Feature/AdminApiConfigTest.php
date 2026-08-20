<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\User;
use App\Services\Admin\ApiEnvironmentService;
use App\Services\Admin\IntegrationSettingService;
use App\Services\SellerApi\SellerApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminApiConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedUsersTable();
    }

    public function test_admin_can_view_api_environment_dashboard(): void
    {
        config()->set('xs2.base_url', 'https://testapi.xs2event.com');
        config()->set('services.xs2.base_url', 'https://testapi.xs2event.com');
        config()->set('xs2.api_key', 'xs2-test-key');
        config()->set('xs2.sandbox.api_url', 'https://testapi.xs2event.com');
        config()->set('xs2.sandbox.api_key', 'xs2-sandbox-key');
        config()->set('seller-api.listing_base_url', 'https://sandbox-sellerapi.seatsbrokers.com');

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('api-config-environment')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/api-config/environment')
            ->assertOk()
            ->assertJsonPath('data.integrations.0.id', 'xs2_events')
            ->assertJsonPath('data.integrations.0.environment', 'sandbox')
            ->assertJsonPath('data.integrations.0.base_url', 'https://testapi.xs2event.com')
            ->assertJsonPath('data.integrations.3.id', 'xs2_create_order')
            ->assertJsonPath('data.integrations.3.environment', 'sandbox')
            ->assertJsonPath('data.integrations.4.id', 'sb_catalog')
            ->assertJsonPath('data.integrations.5.id', 'sb_listing')
            ->assertJsonPath('data.integrations.5.base_url', 'https://sandbox-sellerapi.seatsbrokers.com');
    }

    public function test_api_environment_dashboard_includes_both_environment_previews(): void
    {
        config()->set('xs2.base_url', 'https://api.xs2event.com');
        config()->set('services.xs2.base_url', 'https://api.xs2event.com');
        config()->set('xs2.api_key', 'prod-xs2-key');
        config()->set('xs2.sandbox.api_url', 'https://testapi.xs2event.com');
        config()->set('xs2.sandbox.api_key', 'sandbox-xs2-key');
        config()->set('seller-api.catalog_sandbox_base_url', 'https://sandbox-externalapi.seatsbrokers.com');
        config()->set('seller-api.catalog_production_base_url', 'https://externalapi.seatsbrokers.com');
        config()->set('seller-api.api_key', 'catalog-token');

        app(IntegrationSettingService::class)->set(ApiEnvironmentService::XS2_ACTIVE_ENVIRONMENT, 'production');

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('api-config-environment-preview')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/admin/api-config/environment')
            ->assertOk();

        $response
            ->assertJsonPath('data.integrations.0.environment', 'production')
            ->assertJsonPath('data.integrations.0.base_url', 'https://api.xs2event.com')
            ->assertJsonPath('data.integrations.0.environments.sandbox.base_url', 'https://testapi.xs2event.com')
            ->assertJsonPath('data.integrations.0.environments.production.base_url', 'https://api.xs2event.com')
            ->assertJsonPath('data.integrations.0.environments.sandbox.api_key_configured', true)
            ->assertJsonPath('data.integrations.0.environments.production.api_key_configured', true)
            ->assertJsonPath('data.integrations.1.environments.sandbox.base_url', 'https://testapi.xs2event.com')
            ->assertJsonPath('data.integrations.2.environments.sandbox.base_url', 'https://testapi.xs2event.com')
            ->assertJsonPath('data.integrations.3.id', 'xs2_create_order')
            ->assertJsonPath('data.integrations.3.environment', 'sandbox')
            ->assertJsonPath('data.integrations.3.base_url', 'https://testapi.xs2event.com')
            ->assertJsonPath('data.integrations.3.environments.sandbox.base_url', 'https://testapi.xs2event.com')
            ->assertJsonPath('data.integrations.3.environments.production.base_url', 'https://api.xs2event.com')
            ->assertJsonPath('data.integrations.4.environments.sandbox.base_url', 'https://sandbox-externalapi.seatsbrokers.com')
            ->assertJsonPath('data.integrations.4.environments.production.base_url', 'https://externalapi.seatsbrokers.com')
            ->assertJsonPath('data.integrations.5.environments.sandbox.base_url', 'https://sandbox-sellerapi.seatsbrokers.com')
            ->assertJsonPath('data.integrations.5.environments.production.base_url', 'https://sellerapi.seatsbrokers.com');

        $this->assertNotSame(
            $response->json('data.integrations.0.environments.sandbox.api_key'),
            $response->json('data.integrations.0.environments.production.api_key'),
        );
    }

    public function test_admin_can_persist_api_environment_switches(): void
    {
        config()->set('xs2.base_url', 'https://api.xs2event.com');
        config()->set('xs2.api_key', 'prod-xs2-key');
        config()->set('xs2.sandbox.api_url', 'https://testapi.xs2event.com');
        config()->set('xs2.sandbox.api_key', 'sandbox-xs2-key');
        config()->set('seller-api.catalog_sandbox_base_url', 'https://sandbox-externalapi.seatsbrokers.com');
        config()->set('seller-api.catalog_production_base_url', 'https://externalapi.seatsbrokers.com');
        config()->set('seller-api.api_key', 'catalog-token');

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('api-config-environment-save')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/admin/api-config/environment', [
                'integrations' => [
                    ['id' => 'xs2_events', 'environment' => 'sandbox'],
                    ['id' => 'sb_catalog', 'environment' => 'production'],
                    ['id' => 'sb_listing', 'environment' => 'production'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.integrations.0.environment', 'sandbox')
            ->assertJsonPath('data.integrations.0.base_url', 'https://testapi.xs2event.com')
            ->assertJsonPath('data.integrations.4.environment', 'production')
            ->assertJsonPath('data.integrations.4.base_url', 'https://externalapi.seatsbrokers.com')
            ->assertJsonPath('data.integrations.5.environment', 'production')
            ->assertJsonPath('data.integrations.5.base_url', 'https://sellerapi.seatsbrokers.com');

        $settings = app(IntegrationSettingService::class);
        $this->assertSame('sandbox', $settings->value(ApiEnvironmentService::XS2_ACTIVE_ENVIRONMENT));
        $this->assertSame('production', $settings->value(ApiEnvironmentService::SELLER_CATALOG_ACTIVE_ENVIRONMENT));
        $this->assertSame('production', $settings->value(ApiEnvironmentService::SELLER_LISTING_ACTIVE_ENVIRONMENT));
        $this->assertSame('https://sellerapi.seatsbrokers.com', $settings->value(IntegrationSettingService::SELLER_LISTING_BASE_URL));
    }

    public function test_admin_can_persist_xs2_create_order_environment(): void
    {
        config()->set('xs2.base_url', 'https://api.xs2event.com');
        config()->set('xs2.api_key', 'prod-xs2-key');
        config()->set('xs2.sandbox.api_url', 'https://testapi.xs2event.com');
        config()->set('xs2.sandbox.api_key', 'sandbox-xs2-key');

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('api-config-xs2-order-env')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/admin/api-config/environment', [
                'integrations' => [
                    ['id' => 'xs2_create_order', 'environment' => 'production'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.integrations.3.id', 'xs2_create_order')
            ->assertJsonPath('data.integrations.3.environment', 'production')
            ->assertJsonPath('data.integrations.3.base_url', 'https://api.xs2event.com');

        $this->assertSame(
            'production',
            app(IntegrationSettingService::class)->value(ApiEnvironmentService::XS2_ORDERS_ACTIVE_ENVIRONMENT),
        );
    }

    public function test_xs2_create_order_defaults_to_sandbox_when_unset(): void
    {
        config()->set('xs2.sandbox.api_url', 'https://testapi.xs2event.com');
        config()->set('xs2.sandbox.api_key', 'sandbox-xs2-key');

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('api-config-xs2-order-default')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/api-config/environment')
            ->assertOk()
            ->assertJsonPath('data.integrations.3.id', 'xs2_create_order')
            ->assertJsonPath('data.integrations.3.environment', 'sandbox')
            ->assertJsonPath('data.integrations.3.base_url', 'https://testapi.xs2event.com');
    }

    public function test_xs2_client_uses_sandbox_credentials_when_environment_is_sandbox(): void
    {
        app(IntegrationSettingService::class)->set(ApiEnvironmentService::XS2_ACTIVE_ENVIRONMENT, 'sandbox');

        config()->set('xs2.base_url', 'https://api.xs2event.com');
        config()->set('xs2.api_key', 'prod-key');
        config()->set('xs2.sandbox.api_url', 'https://testapi.xs2event.com');
        config()->set('xs2.sandbox.api_key', 'sandbox-key');
        config()->set('xs2.enabled', true);
        config()->set('services.xs2.enabled', true);
        config()->set('xs2.api_key_header', 'X-Api-Key');
        config()->set('xs2.events_endpoint', '/v1/events');
        config()->set('xs2.rate_limit_pacing', false);
        config()->set('xs2.rate_limit_per_minute', 60);

        Http::fake([
            'https://testapi.xs2event.com/*' => Http::response(['results' => [], 'pagination' => ['page' => 1, 'pages' => 1]]),
        ]);

        app(\App\Services\Xs2\Xs2Client::class)->getEvents(['page' => 1]);

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'https://testapi.xs2event.com/')
                && $request->hasHeader('X-Api-Key', 'sandbox-key');
        });
    }

    public function test_seller_api_client_uses_persisted_catalog_environment(): void
    {
        app(IntegrationSettingService::class)->set(ApiEnvironmentService::SELLER_CATALOG_ACTIVE_ENVIRONMENT, 'production');
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::SELLER_CATALOG_PRODUCTION_BASE_URL,
            'https://externalapi.custom.test',
        );
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::SELLER_CATALOG_PRODUCTION_API_KEY,
            'production-bearer-token',
            secret: true,
        );

        config()->set('services.seller_api.enabled', true);
        config()->set('services.seller_api.api_key', 'fallback-token');
        config()->set('services.seller_api.events_endpoint', '/api/events');
        config()->set('seller-api.events_endpoint', '/api/events');

        Http::fake([
            'https://externalapi.custom.test/*' => Http::response([
                'data' => [['event_id' => 'evt-2', 'match_name' => 'Production Match']],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ]),
        ]);

        $client = app(SellerApiClient::class);
        $this->assertSame('production', $client->defaultCatalogEnvironment());
        $client->fetchEventsPage(1, 100, [], null);

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'https://externalapi.custom.test/api/events')
                && $request->hasHeader('Authorization', 'Bearer production-bearer-token');
        });
    }

    public function test_admin_can_view_seller_catalog_config_in_api_config(): void
    {
        config()->set('seller-api.catalog_sandbox_base_url', 'https://sandbox-externalapi.seatsbrokers.com');
        config()->set('seller-api.catalog_production_base_url', 'https://externalapi.seatsbrokers.com');
        config()->set('seller-api.api_key', 'shared-catalog-token');

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('api-config-catalog')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/api-config')
            ->assertOk()
            ->assertJsonPath('data.integrations.2.catalog.sandbox.base_url', 'https://sandbox-externalapi.seatsbrokers.com')
            ->assertJsonPath('data.integrations.2.catalog.production.base_url', 'https://externalapi.seatsbrokers.com')
            ->assertJsonPath('data.integrations.2.catalog.sandbox.api_key_configured', true)
            ->assertJsonPath('data.integrations.2.catalog.production.api_key_configured', true);
    }

    public function test_admin_can_persist_catalog_environment_overrides(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('api-config-catalog-save')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/admin/api-config/seller-api/catalog', [
                'sandbox_base_url' => 'https://sandbox-externalapi.custom.test',
                'sandbox_api_key' => 'sandbox-bearer-token',
                'production_base_url' => 'https://externalapi.custom.test',
                'production_api_key' => 'production-bearer-token',
            ])
            ->assertOk()
            ->assertJsonPath('data.integration.catalog.sandbox.base_url', 'https://sandbox-externalapi.custom.test')
            ->assertJsonPath('data.integration.catalog.production.base_url', 'https://externalapi.custom.test')
            ->assertJsonPath('data.integration.catalog.sandbox.api_key_configured', true)
            ->assertJsonPath('data.integration.catalog.production.api_key_configured', true)
            ->assertJsonMissing(['data' => ['integration' => ['catalog' => ['sandbox' => ['api_key' => 'sandbox-bearer-token']]]]]);

        $settings = app(IntegrationSettingService::class);
        $this->assertSame('https://sandbox-externalapi.custom.test', $settings->value(IntegrationSettingService::SELLER_CATALOG_SANDBOX_BASE_URL));
        $this->assertSame('https://externalapi.custom.test', $settings->value(IntegrationSettingService::SELLER_CATALOG_PRODUCTION_BASE_URL));
        $this->assertSame('sandbox-bearer-token', $settings->value(IntegrationSettingService::SELLER_CATALOG_SANDBOX_API_KEY));
        $this->assertSame('production-bearer-token', $settings->value(IntegrationSettingService::SELLER_CATALOG_PRODUCTION_API_KEY));
    }

    public function test_seller_api_client_uses_per_environment_catalog_credentials(): void
    {
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::SELLER_CATALOG_SANDBOX_BASE_URL,
            'https://sandbox-externalapi.custom.test',
        );
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::SELLER_CATALOG_SANDBOX_API_KEY,
            'sandbox-bearer-token',
            secret: true,
        );
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::SELLER_CATALOG_PRODUCTION_BASE_URL,
            'https://externalapi.custom.test',
        );
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::SELLER_CATALOG_PRODUCTION_API_KEY,
            'production-bearer-token',
            secret: true,
        );

        config()->set('services.seller_api.enabled', true);
        config()->set('services.seller_api.api_key', 'fallback-token');
        config()->set('services.seller_api.events_endpoint', '/api/events');
        config()->set('seller-api.events_endpoint', '/api/events');

        Http::fake([
            'https://sandbox-externalapi.custom.test/*' => Http::response([
                'data' => [['event_id' => 'evt-1', 'match_name' => 'Sandbox Match']],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ]),
            'https://externalapi.custom.test/*' => Http::response([
                'data' => [['event_id' => 'evt-2', 'match_name' => 'Production Match']],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ]),
        ]);

        $client = app(SellerApiClient::class);

        $this->assertSame(
            'https://sandbox-externalapi.custom.test/api/events?page=1&per_page=100',
            $client->catalogEventsPreviewUrl([], 'sandbox'),
        );
        $this->assertSame(
            'https://externalapi.custom.test/api/events?page=1&per_page=100',
            $client->catalogEventsPreviewUrl([], 'production'),
        );

        $client->fetchEventsPage(1, 100, [], 'sandbox');
        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'https://sandbox-externalapi.custom.test/api/events')
                && $request->hasHeader('Authorization', 'Bearer sandbox-bearer-token');
        });

        $client->fetchEventsPage(1, 100, [], 'production');
        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'https://externalapi.custom.test/api/events')
                && $request->hasHeader('Authorization', 'Bearer production-bearer-token');
        });
    }

    public function test_admin_can_view_seller_listing_base_url_in_api_config(): void
    {
        config()->set('seller-api.listing_base_url', 'https://sandbox-sellerapi.seatsbrokers.com');
        config()->set('seller-api.price_uses_minor_units', false);

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('api-config-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/api-config')
            ->assertOk()
            ->assertJsonPath('data.integrations.2.id', 'seller_api')
            ->assertJsonPath('data.integrations.2.listing_base_url', 'https://sandbox-sellerapi.seatsbrokers.com')
            ->assertJsonPath('data.integrations.2.listing_base_url_editable', true)
            ->assertJsonPath('data.integrations.2.price_uses_minor_units', false)
            ->assertJsonPath('data.integrations.2.price_unit_mode', 'major_decimal');
    }

    public function test_admin_can_persist_listing_base_url_override(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('api-config-update')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/admin/api-config/seller-api', [
                'listing_base_url' => 'https://sandbox-sellerapi.seatsbrokers.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.integration.listing_base_url', 'https://sandbox-sellerapi.seatsbrokers.com');

        $this->assertSame(
            'https://sandbox-sellerapi.seatsbrokers.com',
            app(IntegrationSettingService::class)->value(IntegrationSettingService::SELLER_LISTING_BASE_URL),
        );
    }

    public function test_seller_api_client_uses_database_listing_base_url_override(): void
    {
        IntegrationSetting::query()->create([
            'key' => IntegrationSettingService::SELLER_LISTING_BASE_URL,
            'value' => 'https://sandbox-sellerapi.seatsbrokers.com',
            'is_secret' => false,
        ]);

        config()->set('services.seller_api.base_url', 'https://externalapi.seatsbrokers.com');
        config()->set('services.seller_api.listing_base_url', 'https://sellerapi.seatsbrokers.com');
        config()->set('services.seller_api.api_key', 'listing-test-key');
        config()->set('services.seller_api.api_key_header', 'apiKey');
        config()->set('services.seller_api.ticket_dropdown_endpoint', '/api/ticket_dropdown');
        config()->set('services.seller_api.seller_id', 1);

        Http::fake([
            'https://sandbox-sellerapi.seatsbrokers.com/*' => Http::response([
                'result' => [
                    'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                    'category' => [],
                    'split_type' => [],
                ],
            ]),
        ]);

        app(SellerApiClient::class)->ticketDropdown(1);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sandbox-sellerapi.seatsbrokers.com'));
    }

    public function test_admin_can_persist_xs2_base_url_override(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('api-config-xs2')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/admin/api-config/xs2', [
                'base_url' => 'https://api.xs2event.com',
                'api_key' => 'xs2-secret-key',
            ])
            ->assertOk()
            ->assertJsonPath('data.integration.base_url', 'https://api.xs2event.com')
            ->assertJsonPath('data.integration.api_key_configured', true)
            ->assertJsonMissing(['data' => ['integration' => ['api_key' => 'xs2-secret-key']]]);

        $this->assertSame(
            'https://api.xs2event.com',
            app(IntegrationSettingService::class)->value(IntegrationSettingService::XS2_BASE_URL),
        );
        $this->assertSame(
            'xs2-secret-key',
            app(IntegrationSettingService::class)->value(IntegrationSettingService::XS2_API_KEY),
        );
    }

    public function test_xs2_client_uses_integration_settings_before_env(): void
    {
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::XS2_BASE_URL,
            'https://override.xs2.test',
        );
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::XS2_API_KEY,
            'override-key',
            secret: true,
        );

        config()->set('xs2.base_url', null);
        config()->set('xs2.api_key', null);
        config()->set('services.xs2.base_url', null);
        config()->set('services.xs2.api_key', null);
        config()->set('xs2.enabled', true);
        config()->set('services.xs2.enabled', true);
        config()->set('xs2.api_key_header', 'X-Api-Key');
        config()->set('services.xs2.api_key_header', 'X-Api-Key');
        config()->set('xs2.events_endpoint', '/v1/events');
        config()->set('services.xs2.events_endpoint', '/v1/events');
        config()->set('xs2.rate_limit_pacing', false);
        config()->set('services.xs2.rate_limit_pacing', false);
        config()->set('xs2.rate_limit_per_minute', 60);
        config()->set('services.xs2.rate_limit_per_minute', 60);

        Http::fake([
            'https://override.xs2.test/*' => Http::response(['results' => [], 'pagination' => ['page' => 1, 'pages' => 1]]),
        ]);

        app(\App\Services\Xs2\Xs2Client::class)->getEvents(['page' => 1]);

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'https://override.xs2.test/')
                && $request->hasHeader('X-Api-Key', 'override-key');
        });
    }

    public function test_admin_can_view_xs2_sandbox_config_in_api_config(): void
    {
        config()->set('xs2.sandbox.api_url', 'https://testapi.xs2event.com');
        config()->set('xs2.sandbox.api_key', 'sandbox-xs2-key');
        config()->set('xs2.base_url', 'https://api.xs2event.com');
        config()->set('xs2.api_key', 'prod-xs2-key');

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('api-config-xs2-sandbox')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/api-config')
            ->assertOk()
            ->assertJsonPath('data.integrations.1.id', 'xs2')
            ->assertJsonPath('data.integrations.1.environments.sandbox.base_url', 'https://testapi.xs2event.com')
            ->assertJsonPath('data.integrations.1.environments.production.base_url', 'https://api.xs2event.com')
            ->assertJsonPath('data.integrations.1.environments.sandbox.api_key_configured', true)
            ->assertJsonPath('data.integrations.1.environments.production.api_key_configured', true);
    }

    public function test_admin_can_persist_xs2_sandbox_overrides(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('api-config-xs2-sandbox-save')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/admin/api-config/xs2/sandbox', [
                'base_url' => 'https://testapi.xs2event.com',
                'api_key' => 'sandbox-secret-key',
            ])
            ->assertOk()
            ->assertJsonPath('data.integration.environments.sandbox.base_url', 'https://testapi.xs2event.com')
            ->assertJsonPath('data.integration.environments.sandbox.api_key_configured', true)
            ->assertJsonMissing(['data' => ['integration' => ['environments' => ['sandbox' => ['api_key' => 'sandbox-secret-key']]]]]);

        $settings = app(IntegrationSettingService::class);
        $this->assertSame('https://testapi.xs2event.com', $settings->value(IntegrationSettingService::XS2_SANDBOX_API_URL));
        $this->assertSame('sandbox-secret-key', $settings->value(IntegrationSettingService::XS2_SANDBOX_API_KEY));
    }

    public function test_xs2_client_uses_persisted_sandbox_credentials_when_environment_is_sandbox(): void
    {
        app(IntegrationSettingService::class)->set(ApiEnvironmentService::XS2_ACTIVE_ENVIRONMENT, 'sandbox');
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::XS2_SANDBOX_API_URL,
            'https://testapi.custom.test',
        );
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::XS2_SANDBOX_API_KEY,
            'db-sandbox-key',
            secret: true,
        );

        config()->set('xs2.base_url', 'https://api.xs2event.com');
        config()->set('xs2.api_key', 'prod-key');
        config()->set('xs2.sandbox.api_url', 'https://testapi.xs2event.com');
        config()->set('xs2.sandbox.api_key', 'env-sandbox-key');
        config()->set('xs2.enabled', true);
        config()->set('services.xs2.enabled', true);
        config()->set('xs2.api_key_header', 'X-Api-Key');
        config()->set('xs2.events_endpoint', '/v1/events');
        config()->set('xs2.rate_limit_pacing', false);
        config()->set('xs2.rate_limit_per_minute', 60);

        Http::fake([
            'https://testapi.custom.test/*' => Http::response(['results' => [], 'pagination' => ['page' => 1, 'pages' => 1]]),
        ]);

        app(\App\Services\Xs2\Xs2Client::class)->getEvents(['page' => 1]);

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'https://testapi.custom.test/')
                && $request->hasHeader('X-Api-Key', 'db-sandbox-key');
        });
    }

    public function test_cron_config_reports_xs2_configured_from_integration_settings(): void
    {
        config()->set('xs2.base_url', null);
        config()->set('xs2.api_key', null);
        config()->set('services.xs2.base_url', null);
        config()->set('services.xs2.api_key', null);

        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::XS2_BASE_URL,
            'https://api.xs2event.com',
        );
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::XS2_API_KEY,
            'from-api-config',
            secret: true,
        );

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('cron-config-xs2')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/cron-config')
            ->assertOk()
            ->assertJsonPath('data.scheduler.xs2_configured', true)
            ->assertJsonPath('data.scheduler.xs2_base_url', 'https://api.xs2event.com');
    }
}
