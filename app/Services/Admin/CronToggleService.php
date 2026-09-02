<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Schema;

/**
 * Master Start All + per-cron toggle logic.
 *
 * When Start All is ON: all crons run except those explicitly toggled OFF.
 * When Start All is OFF: only crons explicitly toggled ON run.
 */
class CronToggleService
{
    /** @var list<string> */
    public const TOGGLEABLE_CRON_JOB_IDS = [
        'xs2-inventory-incremental',
        'xs2-inventory-full',
        'xs2-sb-new-listing-publish',
        'xs2-sb-listing-inventory',
        'xs2-sb-order-sync',
        'xs2-sb-order-guest-data-sync',
        'xs2-events-sync',
        'sanctum-prune-expired',
    ];

    public function __construct(
        private readonly IntegrationSettingService $integrationSettings,
    ) {}

    public function startAllEnabled(): bool
    {
        $override = $this->readBoolOverride(IntegrationSettingService::START_ALL_ENABLED);
        if ($override !== null) {
            return $override;
        }

        // Default: follow scheduler master switch when no explicit override exists.
        $schedulerOverride = $this->readBoolOverride(IntegrationSettingService::APP_SCHEDULER_ENABLED);

        return $schedulerOverride ?? (bool) config('app.scheduler_enabled', true);
    }

    public function setStartAllEnabled(bool $enabled): void
    {
        $this->assertIntegrationSettingsAvailable();
        $this->integrationSettings->set(
            IntegrationSettingService::START_ALL_ENABLED,
            $enabled ? 'true' : 'false',
        );
    }

    /**
     * Whether a cron should execute on the schedule (not manual Run now).
     */
    public function shouldRun(string $cronJobId, bool $configEnabled = true): bool
    {
        if (! $configEnabled) {
            return false;
        }

        if (! $this->isToggleable($cronJobId)) {
            return true;
        }

        $explicit = $this->explicitToggle($cronJobId);

        if ($this->startAllEnabled()) {
            return $explicit !== false;
        }

        return $explicit === true;
    }

    /**
     * Effective enabled state for admin UI (toggle switch position).
     */
    public function isCronEnabled(string $cronJobId): bool
    {
        if (! $this->isToggleable($cronJobId)) {
            return true;
        }

        $explicit = $this->explicitToggle($cronJobId);

        if ($this->startAllEnabled()) {
            return $explicit !== false;
        }

        return $explicit === true;
    }

    public function setCronEnabled(string $cronJobId, bool $enabled): void
    {
        $this->assertKnownCron($cronJobId);
        $this->assertIntegrationSettingsAvailable();

        $toggles = $this->allExplicitToggles();
        $toggles[$cronJobId] = $enabled;
        $this->persistToggles($toggles);
    }

    public function hasIndividuallyEnabledCrons(): bool
    {
        foreach (self::TOGGLEABLE_CRON_JOB_IDS as $cronJobId) {
            if ($this->explicitToggle($cronJobId) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scheduler master switch should stay on when Start All is on or any cron is individually enabled.
     */
    public function schedulerShouldBeActive(): bool
    {
        $schedulerOverride = $this->readBoolOverride(IntegrationSettingService::APP_SCHEDULER_ENABLED);
        if ($schedulerOverride === false) {
            return false;
        }

        if ($this->startAllEnabled()) {
            return true;
        }

        return $this->hasIndividuallyEnabledCrons();
    }

    /** @return array<string, bool|null> */
    public function toggleSnapshot(): array
    {
        $snapshot = [];
        foreach (self::TOGGLEABLE_CRON_JOB_IDS as $cronJobId) {
            $snapshot[$cronJobId] = [
                'explicit' => $this->explicitToggle($cronJobId),
                'enabled' => $this->isCronEnabled($cronJobId),
                'toggleable' => true,
            ];
        }

        return [
            'start_all_enabled' => $this->startAllEnabled(),
            'scheduler_should_be_active' => $this->schedulerShouldBeActive(),
            'crons' => $snapshot,
        ];
    }

    public function clearAllExplicitToggles(): void
    {
        if (! Schema::hasTable('integration_settings')) {
            return;
        }

        $this->integrationSettings->set(IntegrationSettingService::CRON_INDIVIDUAL_TOGGLES, null);
    }

    public function isToggleable(string $cronJobId): bool
    {
        return in_array($cronJobId, self::TOGGLEABLE_CRON_JOB_IDS, true);
    }

    private function explicitToggle(string $cronJobId): ?bool
    {
        $toggles = $this->allExplicitToggles();

        if (! array_key_exists($cronJobId, $toggles)) {
            return null;
        }

        return (bool) $toggles[$cronJobId];
    }

    /** @return array<string, bool> */
    private function allExplicitToggles(): array
    {
        if (! Schema::hasTable('integration_settings')) {
            return [];
        }

        $raw = $this->integrationSettings->value(IntegrationSettingService::CRON_INDIVIDUAL_TOGGLES);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $cronJobId => $value) {
            if (! is_string($cronJobId) || ! in_array($cronJobId, self::TOGGLEABLE_CRON_JOB_IDS, true)) {
                continue;
            }
            $normalized[$cronJobId] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $normalized;
    }

    /** @param array<string, bool> $toggles */
    private function persistToggles(array $toggles): void
    {
        if ($toggles === []) {
            $this->integrationSettings->set(IntegrationSettingService::CRON_INDIVIDUAL_TOGGLES, null);

            return;
        }

        $this->integrationSettings->set(
            IntegrationSettingService::CRON_INDIVIDUAL_TOGGLES,
            json_encode($toggles, JSON_THROW_ON_ERROR),
        );
    }

    private function assertKnownCron(string $cronJobId): void
    {
        if (! $this->isToggleable($cronJobId)) {
            throw new \InvalidArgumentException("Cron job “{$cronJobId}” does not support individual toggles.");
        }
    }

    private function readBoolOverride(string $key): ?bool
    {
        $value = $this->integrationSettings->value($key);
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $value === '1';
    }

    private function assertIntegrationSettingsAvailable(): void
    {
        if (! Schema::hasTable('integration_settings')) {
            throw new \RuntimeException('integration_settings table is not available.');
        }
    }
}
