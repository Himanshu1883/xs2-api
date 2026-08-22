<?php

namespace App\Services\Admin;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class IntegrationSettingService
{
    public const SELLER_LISTING_BASE_URL = 'SELLER_API_LISTING_BASE_URL';

    public const SELLER_LISTING_API_KEY = 'SELLER_API_LISTING_API_KEY';

    public const SELLER_CATALOG_SANDBOX_BASE_URL = 'SELLER_API_CATALOG_SANDBOX_BASE_URL';

    public const SELLER_CATALOG_SANDBOX_API_KEY = 'SELLER_API_CATALOG_SANDBOX_API_KEY';

    public const SELLER_CATALOG_PRODUCTION_BASE_URL = 'SELLER_API_CATALOG_PRODUCTION_BASE_URL';

    public const SELLER_CATALOG_PRODUCTION_API_KEY = 'SELLER_API_CATALOG_PRODUCTION_API_KEY';

    public const XS2_BASE_URL = 'XS2_BASE_URL';

    public const XS2_API_KEY = 'XS2_API_KEY';

    public const XS2_SANDBOX_API_URL = 'XS2_SANDBOX_API_URL';

    public const XS2_SANDBOX_API_KEY = 'XS2_SANDBOX_API_KEY';

    public const SB_WEBHOOK_BEARER_TOKEN = 'SB_WEBHOOK_BEARER_TOKEN';

    public const LISTING_PUBLISH_RULES = 'LISTING_PUBLISH_RULES';

    public const APP_SCHEDULER_ENABLED = 'APP_SCHEDULER_ENABLED';

    public const XS2_ENABLED = 'XS2_ENABLED';

    public const XS2_SB_LISTING_INVENTORY_SYNC_ENABLED = 'XS2_SB_LISTING_INVENTORY_SYNC_ENABLED';

    public const XS2_SB_NEW_LISTING_PUBLISH_ENABLED = 'XS2_SB_NEW_LISTING_PUBLISH_ENABLED';

    public const SB_BOOKINGS_SYNC_ENABLED = 'SB_BOOKINGS_SYNC_ENABLED';

    public const XS2_SB_ORDER_GUEST_DATA_SYNC_ENABLED = 'XS2_SB_ORDER_GUEST_DATA_SYNC_ENABLED';

    public const SELLER_API_ENABLED = 'SELLER_API_ENABLED';

    public const APP_LOW_LOAD_MODE = 'APP_LOW_LOAD_MODE';

    public const CRON_CONTROL_SNAPSHOT = 'CRON_CONTROL_SNAPSHOT';

    public const XS2_INCREMENTAL_SYNC_INTERVAL_MINUTES = 'XS2_INCREMENTAL_SYNC_INTERVAL_MINUTES';

    public const XS2_FULL_SYNC_INTERVAL_MINUTES = 'XS2_FULL_SYNC_INTERVAL_MINUTES';

    public const XS2_SB_LISTING_INVENTORY_SYNC_INTERVAL_MINUTES = 'XS2_SB_LISTING_INVENTORY_SYNC_INTERVAL_MINUTES';

    public const XS2_SB_NEW_LISTING_PUBLISH_INTERVAL_MINUTES = 'XS2_SB_NEW_LISTING_PUBLISH_INTERVAL_MINUTES';

    public const SB_BOOKINGS_SYNC_INTERVAL_MINUTES = 'SB_BOOKINGS_SYNC_INTERVAL_MINUTES';

    public const XS2_SB_ORDER_GUEST_DATA_SYNC_INTERVAL_MINUTES = 'XS2_SB_ORDER_GUEST_DATA_SYNC_INTERVAL_MINUTES';

    /** @var list<string> */
    public const SELLER_API_KEYS = [
        self::SELLER_LISTING_BASE_URL,
        self::SELLER_LISTING_API_KEY,
        self::SELLER_CATALOG_SANDBOX_BASE_URL,
        self::SELLER_CATALOG_SANDBOX_API_KEY,
        self::SELLER_CATALOG_PRODUCTION_BASE_URL,
        self::SELLER_CATALOG_PRODUCTION_API_KEY,
    ];

    /** @var list<string> */
    public const XS2_KEYS = [
        self::XS2_BASE_URL,
        self::XS2_API_KEY,
        self::XS2_SANDBOX_API_URL,
        self::XS2_SANDBOX_API_KEY,
    ];

    public function value(string $key): ?string
    {
        if (! Schema::hasTable('integration_settings')) {
            return null;
        }

        $row = IntegrationSetting::query()->where('key', $key)->first();
        if (! $row || $row->value === null || $row->value === '') {
            return null;
        }

        if ($row->is_secret) {
            try {
                return Crypt::decryptString($row->value);
            } catch (\Throwable) {
                return null;
            }
        }

        return $row->value;
    }

    public function set(string $key, ?string $value, bool $secret = false): void
    {
        if (! Schema::hasTable('integration_settings')) {
            throw new \RuntimeException('integration_settings table is not available.');
        }

        if ($value === null || trim($value) === '') {
            IntegrationSetting::query()->where('key', $key)->delete();

            return;
        }

        IntegrationSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $secret ? Crypt::encryptString(trim($value)) : trim($value),
                'is_secret' => $secret,
            ],
        );
    }

    public function masked(string $key): ?string
    {
        $plain = $this->value($key);
        if ($plain === null || $plain === '') {
            return null;
        }

        return $this->maskPlain($plain);
    }

    public function maskPlain(string $plain): string
    {
        $length = strlen($plain);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($plain, 0, 4).str_repeat('*', max(4, $length - 8)).substr($plain, -4);
    }

    public function hasOverride(string $key): bool
    {
        if (! Schema::hasTable('integration_settings')) {
            return false;
        }

        return IntegrationSetting::query()->where('key', $key)->exists();
    }
}
