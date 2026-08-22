<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CronIntervalService
{
    /** @var list<int> */
    public const DEFAULT_PRESETS = [1, 5, 15, 30, 60];

    public function __construct(private readonly IntegrationSettingService $integrationSettings) {}

    /**
     * Per-job cron duration (how often the scheduler runs the command).
     *
     * @return array<string, array{config_key: string, setting_key: string, min: int, max: int, presets: list<int>}>
     */
    public function definitions(): array
    {
        return [
            'xs2-inventory-incremental' => [
                'config_key' => 'xs2.sync.incremental_interval_minutes',
                'setting_key' => IntegrationSettingService::XS2_INCREMENTAL_SYNC_INTERVAL_MINUTES,
                'min' => 5,
                'max' => 60,
                'presets' => [5, 10, 15, 30, 60],
            ],
            'xs2-inventory-full' => [
                'config_key' => 'xs2.sync.full_interval_minutes',
                'setting_key' => IntegrationSettingService::XS2_FULL_SYNC_INTERVAL_MINUTES,
                'min' => 60,
                'max' => 1440,
                'presets' => [60, 120, 180, 360],
            ],
            'xs2-sb-new-listing-publish' => [
                'config_key' => 'xs2.sb_new_listing_publish.sync_interval_minutes',
                'setting_key' => IntegrationSettingService::XS2_SB_NEW_LISTING_PUBLISH_INTERVAL_MINUTES,
                'min' => 1,
                'max' => 60,
                'presets' => self::DEFAULT_PRESETS,
            ],
            'xs2-sb-listing-inventory' => [
                'config_key' => 'xs2.sb_listing_inventory.sync_interval_minutes',
                'setting_key' => IntegrationSettingService::XS2_SB_LISTING_INVENTORY_SYNC_INTERVAL_MINUTES,
                'min' => 1,
                'max' => 60,
                'presets' => self::DEFAULT_PRESETS,
            ],
            'xs2-sb-order-sync' => [
                'config_key' => 'xs2.sb_bookings_sync.sync_interval_minutes',
                'setting_key' => IntegrationSettingService::SB_BOOKINGS_SYNC_INTERVAL_MINUTES,
                'min' => 1,
                'max' => 60,
                'presets' => self::DEFAULT_PRESETS,
            ],
            'xs2-sb-order-guest-data-sync' => [
                'config_key' => 'xs2.sb_order_guest_data_sync.sync_interval_minutes',
                'setting_key' => IntegrationSettingService::XS2_SB_ORDER_GUEST_DATA_SYNC_INTERVAL_MINUTES,
                'min' => 1,
                'max' => 60,
                'presets' => self::DEFAULT_PRESETS,
            ],
        ];
    }

    public function isConfigurable(string $cronJobId): bool
    {
        return array_key_exists($cronJobId, $this->definitions());
    }

    public function applyConfigOverrides(): void
    {
        if (! Schema::hasTable('integration_settings')) {
            return;
        }

        foreach ($this->definitions() as $definition) {
            $raw = $this->integrationSettings->value($definition['setting_key']);
            if ($raw === null || $raw === '') {
                continue;
            }

            $minutes = (int) $raw;
            if ($minutes < 1) {
                continue;
            }

            config([$definition['config_key'] => $this->clamp($minutes, $definition)]);
        }
    }

    public function minutesFor(string $cronJobId): int
    {
        $definition = $this->definitions()[$cronJobId] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown cron interval job [{$cronJobId}].");
        }

        return $this->clamp((int) config($definition['config_key'], $definition['min']), $definition);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateMinutes(string $cronJobId, int $minutes): array
    {
        $definition = $this->definitions()[$cronJobId] ?? null;
        if ($definition === null) {
            throw ValidationException::withMessages([
                'cron_job_id' => ['Cron duration cannot be configured for this job.'],
            ]);
        }

        if ($minutes < $definition['min'] || $minutes > $definition['max']) {
            throw ValidationException::withMessages([
                'interval_minutes' => [
                    "Cron duration must be between {$definition['min']} and {$definition['max']} minutes.",
                ],
            ]);
        }

        $this->integrationSettings->set($definition['setting_key'], (string) $minutes);
        config([$definition['config_key'] => $minutes]);

        return $this->metadataFor($cronJobId);
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    public function decorateTask(array $task): array
    {
        $cronJobId = (string) ($task['id'] ?? '');
        if (! $this->isConfigurable($cronJobId)) {
            $task['interval_configurable'] = false;

            return $task;
        }

        $metadata = $this->metadataFor($cronJobId);
        $extra = is_array($task['extra'] ?? null) ? $task['extra'] : [];
        $extra['sync_interval_minutes'] = $metadata['interval_minutes'];

        return [
            ...$task,
            ...$metadata,
            'extra' => $extra,
        ];
    }

    /**
     * Spread recurring tasks across the hour so they do not all fire at once.
     */
    public function staggeredExpression(int $intervalMinutes, int $offsetMinute): string
    {
        $interval = max(1, min(60, $intervalMinutes));
        if ($interval >= 60) {
            return '0 * * * *';
        }

        if ($interval === 1) {
            return '* * * * *';
        }

        $start = $offsetMinute % $interval;
        $minutes = [];
        for ($minute = $start; $minute < 60; $minute += $interval) {
            $minutes[] = (string) $minute;
        }

        return implode(',', $minutes).' * * * *';
    }

    public function hourlyExpression(int $intervalMinutes): string
    {
        $hours = max(1, (int) ceil(max(60, $intervalMinutes) / 60));

        return '0 */'.$hours.' * * *';
    }

    /**
     * @return array{
     *     interval_configurable: true,
     *     interval_minutes: int,
     *     interval_min_minutes: int,
     *     interval_max_minutes: int,
     *     interval_presets: list<int>,
     *     interval_is_overridden: bool
     * }
     */
    public function metadataFor(string $cronJobId): array
    {
        $definition = $this->definitions()[$cronJobId];
        $minutes = $this->minutesFor($cronJobId);

        return [
            'interval_configurable' => true,
            'interval_minutes' => $minutes,
            'interval_min_minutes' => $definition['min'],
            'interval_max_minutes' => $definition['max'],
            'interval_presets' => array_values(array_filter(
                $definition['presets'],
                fn (int $preset): bool => $preset >= $definition['min'] && $preset <= $definition['max'],
            )),
            'interval_is_overridden' => $this->integrationSettings->hasOverride($definition['setting_key']),
        ];
    }

    /**
     * @param  array{min: int, max: int}  $definition
     */
    private function clamp(int $minutes, array $definition): int
    {
        return max($definition['min'], min($definition['max'], $minutes));
    }
}
