<?php

namespace App\Services\Webhooks;

use App\Services\Admin\IntegrationSettingService;
use Illuminate\Support\Str;

class WebhookSettingService
{
    public const WEBHOOK_PATH = '/api/webhooks/sb/orders';

    public function __construct(
        private readonly IntegrationSettingService $integrationSettings,
    ) {}

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').self::WEBHOOK_PATH;
    }

    public function bearerToken(): ?string
    {
        return $this->integrationSettings->value(IntegrationSettingService::SB_WEBHOOK_BEARER_TOKEN);
    }

    public function maskedBearerToken(): ?string
    {
        return $this->integrationSettings->masked(IntegrationSettingService::SB_WEBHOOK_BEARER_TOKEN);
    }

    public function isConfigured(): bool
    {
        return filled($this->bearerToken());
    }

    /**
     * Ensure a bearer token exists; return the plain token (existing or newly generated).
     */
    public function ensureBearerToken(): string
    {
        $existing = $this->bearerToken();
        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        return $this->regenerateBearerToken();
    }

    public function regenerateBearerToken(): string
    {
        $token = Str::random(64);

        $this->integrationSettings->set(
            IntegrationSettingService::SB_WEBHOOK_BEARER_TOKEN,
            $token,
            secret: true,
        );

        return $token;
    }

    public function validateBearerToken(?string $provided): bool
    {
        $expected = $this->bearerToken();
        if ($expected === null || $expected === '' || $provided === null || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /** @return array<string, mixed> */
    public function settingsPayload(?string $plainToken = null): array
    {
        return [
            'webhook_url' => $this->webhookUrl(),
            'webhook_path' => self::WEBHOOK_PATH,
            'bearer_token' => $plainToken ?? $this->maskedBearerToken(),
            'bearer_token_configured' => $this->isConfigured(),
            'bearer_token_plain' => $plainToken,
        ];
    }
}
