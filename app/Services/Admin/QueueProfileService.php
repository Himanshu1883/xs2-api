<?php

namespace App\Services\Admin;

/**
 * Queue worker profiles tuned for server load. "minimal" targets ~40% CPU on small AWS instances.
 */
class QueueProfileService
{
    public const PROFILE_MINIMAL = 'minimal';

    public const PROFILE_BALANCED = 'balanced';

    public const PROFILE_THROUGHPUT = 'throughput';

    public const SETTING_PROFILE = 'QUEUE_MANAGEMENT_PROFILE';

    public function __construct(
        private readonly IntegrationSettingService $integrationSettings,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function profiles(): array
    {
        return [
            self::PROFILE_MINIMAL => [
                'id' => self::PROFILE_MINIMAL,
                'label' => 'Minimal load',
                'description' => 'One xs2-sync worker, long idle sleep, strict job backpressure. Best for keeping AWS CPU under ~40%.',
                'recommended_use' => 'Default for fresh deploys with low_load_mode, small AWS instances, or emergency CPU relief.',
                'workers' => [
                    'xs2_sync' => 1,
                    'xs2_listing_gen' => 0,
                    'xs2_reconcile' => 0,
                    'xs2_guest' => 0,
                    'xs2_mapping' => 0,
                    'seller_api' => 0,
                    'default' => 0,
                ],
                'sleep' => 5,
                'rate_limit_per_minute' => 20,
                'max_pending_jobs' => 40,
                'max_dispatch_per_run' => 8,
                'low_load_mode' => true,
            ],
            self::PROFILE_BALANCED => [
                'id' => self::PROFILE_BALANCED,
                'label' => 'Balanced',
                'description' => 'One worker per queue, moderate sleep and backpressure. Target CPU ~50–60%.',
                'recommended_use' => 'Steady-state production when queues stay drained and CPU headroom is available.',
                'workers' => [
                    'xs2_sync' => 1,
                    'xs2_listing_gen' => 1,
                    'xs2_reconcile' => 1,
                    'xs2_guest' => 1,
                    'xs2_mapping' => 1,
                    'seller_api' => 1,
                    'default' => 1,
                ],
                'sleep' => 3,
                'rate_limit_per_minute' => 30,
                'max_pending_jobs' => 150,
                'max_dispatch_per_run' => 30,
                'low_load_mode' => true,
            ],
            self::PROFILE_THROUGHPUT => [
                'id' => self::PROFILE_THROUGHPUT,
                'label' => 'Throughput',
                'description' => 'More workers and faster polling for catch-up backlogs. Use temporarily when queues are drained and CPU allows.',
                'recommended_use' => 'Short catch-up windows only — disable when backpressure is active or CPU exceeds ~70%.',
                'workers' => [
                    'xs2_sync' => 2,
                    'xs2_listing_gen' => 1,
                    'xs2_reconcile' => 1,
                    'xs2_guest' => 1,
                    'xs2_mapping' => 1,
                    'seller_api' => 1,
                    'default' => 1,
                ],
                'sleep' => 1,
                'rate_limit_per_minute' => 60,
                'max_pending_jobs' => 800,
                'max_dispatch_per_run' => 150,
                'low_load_mode' => false,
            ],
        ];
    }

    public function activeProfileId(): string
    {
        $stored = $this->integrationSettings->value(self::SETTING_PROFILE);
        if (is_string($stored) && isset($this->profiles()[$stored])) {
            return $stored;
        }

        if ((bool) config('app.low_load_mode', false)) {
            return self::PROFILE_MINIMAL;
        }

        return self::PROFILE_BALANCED;
    }

    /** @return array<string, mixed> */
    public function activeProfile(): array
    {
        return $this->profiles()[$this->activeProfileId()];
    }

    /** @return array<string, mixed> */
    public function applyProfile(string $profileId): array
    {
        $profile = $this->profiles()[$profileId] ?? null;
        if ($profile === null) {
            throw new \InvalidArgumentException("Unknown queue profile “{$profileId}”.");
        }

        $this->integrationSettings->set(self::SETTING_PROFILE, $profileId);
        $this->integrationSettings->set(
            IntegrationSettingService::APP_LOW_LOAD_MODE,
            ($profile['low_load_mode'] ?? false) ? 'true' : 'false',
        );

        $this->applyProfileToRuntime($profile);

        return [
            'profile' => $profileId,
            'applied' => $profile,
            'supervisor_config' => $this->supervisorConfig($profileId),
            'worker_script_hint' => 'bash scripts/run-queue-workers.sh',
        ];
    }

    /** @param  array<string, mixed>  $profile */
    public function applyProfileToRuntime(array $profile): void
    {
        $workers = is_array($profile['workers'] ?? null) ? $profile['workers'] : [];

        config([
            'xs2.queue_workers.xs2_sync' => max(0, (int) ($workers['xs2_sync'] ?? 1)),
            'xs2.queue_workers.xs2_listing_gen' => max(0, (int) ($workers['xs2_listing_gen'] ?? 1)),
            'xs2.queue_workers.xs2_reconcile' => max(0, (int) ($workers['xs2_reconcile'] ?? 1)),
            'xs2.queue_workers.xs2_guest' => max(0, (int) ($workers['xs2_guest'] ?? 1)),
            'xs2.queue_workers.xs2_mapping' => max(0, (int) ($workers['xs2_mapping'] ?? 1)),
            'xs2.queue_workers.seller_api' => max(0, (int) ($workers['seller_api'] ?? 1)),
            'xs2.queue_workers.default' => max(0, (int) ($workers['default'] ?? 1)),
            'xs2.queue_worker_options.sleep' => max(1, (int) ($profile['sleep'] ?? 3)),
            'xs2.rate_limit_per_minute' => max(1, (int) ($profile['rate_limit_per_minute'] ?? 30)),
            'services.xs2.rate_limit_per_minute' => max(1, (int) ($profile['rate_limit_per_minute'] ?? 30)),
            'xs2.queue_backpressure.max_pending_jobs' => max(1, (int) ($profile['max_pending_jobs'] ?? 150)),
            'xs2.queue_backpressure.max_dispatch_per_run' => max(1, (int) ($profile['max_dispatch_per_run'] ?? 30)),
            'app.low_load_mode' => (bool) ($profile['low_load_mode'] ?? true),
        ]);
    }

    public function applyActiveProfileToRuntime(): void
    {
        $this->applyProfileToRuntime($this->activeProfile());
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $profile = $this->activeProfile();
        $profiles = $this->profiles();

        return [
            'active_profile' => $this->activeProfileId(),
            'profiles' => array_values(array_map(
                static fn (array $item): array => [
                    'id' => $item['id'],
                    'label' => $item['label'],
                    'description' => $item['description'],
                    'recommended_use' => $item['recommended_use'] ?? '',
                    'workers' => $item['workers'] ?? [],
                    'sleep' => $item['sleep'] ?? 3,
                    'rate_limit_per_minute' => $item['rate_limit_per_minute'] ?? 30,
                    'max_pending_jobs' => $item['max_pending_jobs'] ?? 150,
                ],
                $profiles,
            )),
            'active' => [
                'label' => $profile['label'],
                'workers' => $profile['workers'],
                'sleep' => $profile['sleep'],
                'rate_limit_per_minute' => $profile['rate_limit_per_minute'],
                'max_pending_jobs' => $profile['max_pending_jobs'],
                'max_dispatch_per_run' => $profile['max_dispatch_per_run'],
                'low_load_mode' => $profile['low_load_mode'],
            ],
        ];
    }

    public function supervisorConfig(?string $profileId = null): string
    {
        $profileId ??= $this->activeProfileId();
        $profile = $this->profiles()[$profileId] ?? $this->profiles()[self::PROFILE_BALANCED];
        $workers = is_array($profile['workers'] ?? null) ? $profile['workers'] : [];
        $sleep = max(1, (int) ($profile['sleep'] ?? 3));
        $tries = max(1, (int) config('xs2.queue_worker_options.tries', 5));
        $timeout = max(60, (int) config('xs2.queue_worker_options.timeout', 300));
        $appPath = base_path();
        $user = env('SUPERVISOR_RUN_AS', 'www-data');

        $programs = [
            ['key' => 'xs2_mapping', 'queue' => (string) config('xs2.mapping_queue', 'xs2-mapping'), 'name' => 'xs2-mapping'],
            ['key' => 'xs2_reconcile', 'queue' => (string) config('pipeline.reconcile_queue', 'xs2-reconcile'), 'name' => 'xs2-reconcile'],
            ['key' => 'xs2_listing_gen', 'queue' => (string) config('pipeline.listing_gen_queue', 'xs2-listing-gen'), 'name' => 'xs2-listing-gen'],
            ['key' => 'xs2_sync', 'queue' => (string) config('xs2.queue', 'xs2-sync'), 'name' => 'xs2-sync'],
            ['key' => 'xs2_guest', 'queue' => (string) config('xs2.guest_queue', 'xs2-guest'), 'name' => 'xs2-guest'],
            ['key' => 'xs2_mapping', 'queue' => (string) config('xs2.mapping_queue', 'xs2-mapping'), 'name' => 'xs2-mapping'],
            ['key' => 'seller_api', 'queue' => (string) config('services.seller_api.queue', 'seller-api'), 'name' => 'seller-api'],
            ['key' => 'default', 'queue' => 'default', 'name' => 'default'],
        ];

        $blocks = [];
        $blocks[] = '; Generated for profile: '.$profileId.' ('.$profile['label'].')';
        $blocks[] = '; Install: sudo cp deploy/supervisor/seatsbroker-provider.conf /etc/supervisor/conf.d/seatsbroker-provider.conf';
        $blocks[] = ';         sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status';
        $blocks[] = '';

        $activePrograms = [];
        foreach ($programs as $program) {
            $count = max(0, (int) ($workers[$program['key']] ?? 0));
            if ($count === 0) {
                continue;
            }

            $programName = 'seatsbroker-'.$program['name'];
            $activePrograms[] = $programName;

            $blocks[] = '[program:'.$programName.']';
            $blocks[] = 'process_name=%(program_name)s_%(process_num)02d';
            $blocks[] = 'command=php '.$appPath.'/artisan queue:work --queue='.$program['queue'].' --sleep='.$sleep.' --tries='.$tries.' --timeout='.$timeout;
            $blocks[] = 'directory='.$appPath;
            $blocks[] = 'autostart=true';
            $blocks[] = 'autorestart=true';
            $blocks[] = 'startsecs=10';
            $blocks[] = 'startretries=3';
            $blocks[] = 'stopwaitsecs='.$timeout;
            $blocks[] = 'stopasgroup=true';
            $blocks[] = 'killasgroup=true';
            $blocks[] = 'numprocs='.$count;
            $blocks[] = 'user='.$user;
            $blocks[] = 'redirect_stderr=true';
            $blocks[] = 'stdout_logfile='.$appPath.'/storage/logs/supervisor-'.$program['name'].'.log';
            $blocks[] = 'stdout_logfile_maxbytes=10MB';
            $blocks[] = 'stdout_logfile_backups=3';
            $blocks[] = '';
        }

        if ($activePrograms !== []) {
            $blocks[] = '[group:seatsbroker-workers]';
            $blocks[] = 'programs='.implode(',', $activePrograms);
            $blocks[] = 'priority=999';
            $blocks[] = '';
        }

        $blocks[] = '[program:seatsbroker-scheduler]';
        $blocks[] = 'command=php '.$appPath.'/artisan schedule:work';
        $blocks[] = 'directory='.$appPath;
        $blocks[] = 'autostart='.(config('app.scheduler_enabled', true) ? 'true' : 'false');
        $blocks[] = 'autorestart=true';
        $blocks[] = 'startsecs=5';
        $blocks[] = 'stopwaitsecs=30';
        $blocks[] = 'stopasgroup=true';
        $blocks[] = 'killasgroup=true';
        $blocks[] = 'user='.$user;
        $blocks[] = 'redirect_stderr=true';
        $blocks[] = 'stdout_logfile='.$appPath.'/storage/logs/supervisor-scheduler.log';
        $blocks[] = 'stdout_logfile_maxbytes=10MB';
        $blocks[] = 'stdout_logfile_backups=3';

        return implode(PHP_EOL, $blocks).PHP_EOL;
    }
}
