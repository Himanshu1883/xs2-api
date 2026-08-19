<?php

namespace App\Services\Admin;

use App\Models\Xs2EventInventorySyncState;
use App\Models\Xs2SyncState;
use App\Support\AwsEmergencyStopGuide;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;

class CronControlService
{
    /** @var array<string, string> */
    private const CONFIG_TO_SETTING = [
        'app.scheduler_enabled' => IntegrationSettingService::APP_SCHEDULER_ENABLED,
        'app.low_load_mode' => IntegrationSettingService::APP_LOW_LOAD_MODE,
        'xs2.enabled' => IntegrationSettingService::XS2_ENABLED,
        'services.seller_api.enabled' => IntegrationSettingService::SELLER_API_ENABLED,
        'xs2.sb_listing_inventory.enabled' => IntegrationSettingService::XS2_SB_LISTING_INVENTORY_SYNC_ENABLED,
        'xs2.sb_new_listing_publish.enabled' => IntegrationSettingService::XS2_SB_NEW_LISTING_PUBLISH_ENABLED,
        'xs2.sb_bookings_sync.enabled' => IntegrationSettingService::SB_BOOKINGS_SYNC_ENABLED,
        'xs2.sb_order_guest_data_sync.enabled' => IntegrationSettingService::XS2_SB_ORDER_GUEST_DATA_SYNC_ENABLED,
    ];

    public function __construct(
        private readonly IntegrationSettingService $integrationSettings,
        private readonly QueueManagementService $queues,
        private readonly QueueProfileService $queueProfiles,
    ) {}

    public function schedulerEnabled(): bool
    {
        $override = $this->readBoolOverride(IntegrationSettingService::APP_SCHEDULER_ENABLED);
        if ($override !== null) {
            return $override;
        }

        return (bool) config('app.scheduler_enabled', true);
    }

    public function lowLoadModeEnabled(): bool
    {
        $override = $this->readBoolOverride(IntegrationSettingService::APP_LOW_LOAD_MODE);
        if ($override !== null) {
            return $override;
        }

        return (bool) config('app.low_load_mode', false);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $overrides = [];
        foreach (self::CONFIG_TO_SETTING as $configKey => $settingKey) {
            if ($this->integrationSettings->hasOverride($settingKey)) {
                $overrides[$configKey] = $this->readBoolOverride($settingKey);
            }
        }

        return [
            'scheduler_enabled' => $this->schedulerEnabled(),
            'low_load_mode' => $this->lowLoadModeEnabled(),
            'has_restore_snapshot' => filled(
                $this->integrationSettings->value(IntegrationSettingService::CRON_CONTROL_SNAPSHOT),
            ),
            'overrides' => $overrides,
            'aws_emergency_steps' => AwsEmergencyStopGuide::steps(),
        ];
    }

    public function applyConfigOverrides(): void
    {
        if (! Schema::hasTable('integration_settings')) {
            return;
        }

        foreach (self::CONFIG_TO_SETTING as $configKey => $settingKey) {
            $override = $this->readBoolOverride($settingKey);
            if ($override !== null) {
                config([$configKey => $override]);
            }
        }
    }

    /**
     * Disable the scheduler master switch and every cron task flag, then stop queue workers.
     *
     * @return array<string, mixed>
     */
    public function stopAll(bool $stopQueues = true): array
    {
        $this->assertIntegrationSettingsAvailable();

        $previousState = $this->currentEffectiveState();

        $this->integrationSettings->set(
            IntegrationSettingService::CRON_CONTROL_SNAPSHOT,
            json_encode([
                'saved_at' => now()->toIso8601String(),
                'state' => $previousState,
            ], JSON_THROW_ON_ERROR),
        );

        foreach (self::CONFIG_TO_SETTING as $configKey => $settingKey) {
            $value = in_array($configKey, ['app.low_load_mode'], true) ? 'true' : 'false';
            $this->integrationSettings->set($settingKey, $value);
            config([$configKey => $value === 'true']);
        }

        $mutexesCleared = $this->clearScheduleMutexes();
        $stuckStatesReset = $this->resetStuckRunningSyncStates();

        $queueResult = null;
        if ($stopQueues) {
            try {
                $queueResult = $this->queues->stopAll();
            } catch (\RuntimeException $exception) {
                $queueResult = [
                    'jobs_deleted' => 0,
                    'failed_deleted' => 0,
                    'workers_restarted' => false,
                    'queue' => null,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $profileResult = $this->queueProfiles->applyProfile(QueueProfileService::PROFILE_MINIMAL);

        return [
            'action' => 'stop',
            'scheduler_enabled' => false,
            'low_load_mode' => true,
            'previous_state' => $previousState,
            'mutexes_cleared' => $mutexesCleared,
            'stuck_states_reset' => $stuckStatesReset,
            'queue' => $queueResult,
            'queue_profile' => $profileResult,
            'aws_emergency_steps' => AwsEmergencyStopGuide::steps(),
            'stopped_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Restore cron flags from the snapshot saved by stopAll(), or clear overrides to use env defaults.
     *
     * @return array<string, mixed>
     */
    public function startAll(): array
    {
        $this->assertIntegrationSettingsAvailable();

        $snapshotJson = $this->integrationSettings->value(IntegrationSettingService::CRON_CONTROL_SNAPSHOT);
        $snapshot = is_string($snapshotJson) && $snapshotJson !== ''
            ? json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR)
            : null;
        $previousState = is_array($snapshot['state'] ?? null) ? $snapshot['state'] : null;

        if ($previousState !== null) {
            foreach (self::CONFIG_TO_SETTING as $configKey => $settingKey) {
                $enabled = (bool) ($previousState[$configKey] ?? true);
                $this->integrationSettings->set($settingKey, $enabled ? 'true' : 'false');
                config([$configKey => $enabled]);
            }
        } else {
            foreach (self::CONFIG_TO_SETTING as $configKey => $settingKey) {
                $this->integrationSettings->set($settingKey, null);
            }
            $this->applyConfigOverrides();
        }

        $this->integrationSettings->set(IntegrationSettingService::CRON_CONTROL_SNAPSHOT, null);

        $profileResult = $this->queueProfiles->applyProfile(QueueProfileService::PROFILE_BALANCED);

        return [
            'action' => 'start',
            'scheduler_enabled' => $this->schedulerEnabled(),
            'low_load_mode' => $this->lowLoadModeEnabled(),
            'restored_state' => $previousState,
            'queue_profile' => $profileResult,
            'started_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, bool> */
    private function currentEffectiveState(): array
    {
        $state = [];
        foreach (self::CONFIG_TO_SETTING as $configKey => $settingKey) {
            $override = $this->readBoolOverride($settingKey);
            $state[$configKey] = $override ?? (bool) config($configKey);
        }

        return $state;
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

    private function clearScheduleMutexes(): int
    {
        try {
            /** @var Schedule $schedule */
            $schedule = app(Schedule::class);
            $cleared = 0;

            foreach ($schedule->events() as $event) {
                try {
                    if ($event->mutex->exists($event)) {
                        $event->mutex->forget($event);
                        $cleared++;
                    }
                } catch (\Throwable) {
                    // Ignore individual mutex failures.
                }
            }

            return $cleared;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array{sync_states: int, inventory_states: int} */
    private function resetStuckRunningSyncStates(): array
    {
        $message = 'Stopped by emergency stop-all at '.now()->toIso8601String();
        $syncStates = 0;
        $inventoryStates = 0;

        if (Schema::hasTable('xs2_sync_states')) {
            $syncStates = Xs2SyncState::query()
                ->where('status', 'running')
                ->update([
                    'status' => 'failed',
                    'last_error' => $message,
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('xs2_event_inventory_sync_states')) {
            $inventoryStates = Xs2EventInventorySyncState::query()
                ->where('tickets_sync_status', 'running')
                ->update([
                    'tickets_sync_status' => 'failed',
                    'tickets_sync_error' => $message,
                    'updated_at' => now(),
                ]);
        }

        return [
            'sync_states' => (int) $syncStates,
            'inventory_states' => (int) $inventoryStates,
        ];
    }
}
