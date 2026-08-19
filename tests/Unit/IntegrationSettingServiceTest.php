<?php

namespace Tests\Unit;

use App\Models\IntegrationSetting;
use App\Services\Admin\IntegrationSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationSettingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_and_read_listing_base_url(): void
    {
        $service = app(IntegrationSettingService::class);
        $service->set(IntegrationSettingService::SELLER_LISTING_BASE_URL, 'https://sandbox-sellerapi.seatsbrokers.com');

        $this->assertSame(
            'https://sandbox-sellerapi.seatsbrokers.com',
            $service->value(IntegrationSettingService::SELLER_LISTING_BASE_URL),
        );
    }

    public function test_listing_api_key_is_stored_encrypted(): void
    {
        $service = app(IntegrationSettingService::class);
        $service->set(IntegrationSettingService::SELLER_LISTING_API_KEY, 'secret-sandbox-key', secret: true);

        $row = IntegrationSetting::query()->where('key', IntegrationSettingService::SELLER_LISTING_API_KEY)->first();
        $this->assertNotNull($row);
        $this->assertNotSame('secret-sandbox-key', $row->value);
        $this->assertSame('secret-sandbox-key', $service->value(IntegrationSettingService::SELLER_LISTING_API_KEY));
        $this->assertStringContainsString('*', (string) $service->masked(IntegrationSettingService::SELLER_LISTING_API_KEY));
    }
}
