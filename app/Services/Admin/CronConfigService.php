<?php

namespace App\Services\Admin;

use App\Jobs\SyncXs2EventInventory;
use App\Models\EventMapping;
use App\Models\SbOrder;
use App\Models\Xs2Event;
use App\Models\Xs2Order;
use App\Models\Xs2EventInventorySyncState;
use App\Models\Xs2SyncState;
use App\Services\Admin\ApiEnvironmentService;
use App\Services\SellerApi\SbListingInventorySyncService;
use App\Services\SellerApi\SbNewListingPublishService;
use App\Services\SellerApi\SellerBookingSyncService;
use App\Services\Xs2\SbOrderXs2GuestDataSyncService;
use App\Services\Xs2\Xs2ApiDebugRecorder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use App\Support\AwsEmergencyStopGuide;
use Illuminate\Validation\ValidationException;
use Throwable;

class CronConfigService
{
    public function __construct(
        private readonly QueueManagementService $queues,
        private readonly CronIntervalService $intervals,
    ) {}

    /** @return array{scheduler: array<string, mixed>, tasks: list<array<string, mixed>>} */
    public function snapshot(): array
    {
        $xs2Enabled = (bool) config('xs2.enabled', true);
        $xs2Configured = $this->xs2CredentialsConfigured();
        // Heal cron UI / telemetry when credentials were fixed after prior failed runs.
        if ($xs2Configured) {
            $this->clearResolvedXs2ConfigurationErrors();
        }

        $states = $this->syncStatesByResource();
        $inventoryTelemetry = $this->inventorySyncTelemetry();
        $scheduleByTaskId = $this->scheduleMetadataByTaskId();

        $tasks = array_merge(
            $this->inventoryTasks($inventoryTelemetry, $scheduleByTaskId, $xs2Enabled),
            $this->sbNewListingPublishTasks($scheduleByTaskId),
            $this->sbListingInventoryTasks($scheduleByTaskId),
            $this->sbEventsSyncTasks(),
            [
                $this->finalizeTask(
                    $this->task(
                        id: 'sanctum-prune-expired',
                        name: 'Prune expired Sanctum tokens',
                        type: 'command',
                        command: 'sanctum:prune-expired --hours=24',
                        schedule: 'Daily',
                        scheduleDetail: 'Removes API tokens expired for more than 24 hours.',
                        queue: null,
                        syncResource: null,
                        state: null,
                        extra: [],
                        enabled: true,
                        category: 'system',
                    ),
                    $scheduleByTaskId['sanctum-prune-expired'] ?? null,
                ),
            ],
            $this->xs2EventTasks($states, $scheduleByTaskId, $xs2Enabled),
            $this->xs2SbOrderSyncTasks($scheduleByTaskId, $states, $xs2Enabled),
            $this->xs2SbOrderGuestDataSyncTasks($scheduleByTaskId, $states, $xs2Enabled),
        );

        $tasks = array_map(
            fn (array $task): array => $this->intervals->decorateTask($task),
            $tasks,
        );

        $health = $this->scheduleHealth($tasks);
        $queueSnapshot = $this->queues->snapshot();

        return [
            'scheduler' => [
                'timezone' => (string) config('app.timezone'),
                'definition_file' => 'routes/console.php',
                'runner_command' => 'php artisan schedule:run',
                'runner_cron' => '* * * * * php artisan schedule:run >> /dev/null 2>&1',
                'queue_workers' => $queueSnapshot['queues'],
                'queue_stats' => [
                    'available' => $queueSnapshot['available'],
                    'jobs_table' => $queueSnapshot['jobs_table'] ?? 'jobs',
                    'connection' => $queueSnapshot['connection'],
                    'totals' => $queueSnapshot['totals'],
                    'other_queues' => $queueSnapshot['other_queues'],
                    'rate_limit_per_minute' => $queueSnapshot['rate_limit_per_minute'] ?? null,
                    'worker_sleep_seconds' => $queueSnapshot['worker_sleep_seconds'] ?? null,
                    'profile' => $queueSnapshot['profile'] ?? null,
                    'backpressure' => $queueSnapshot['backpressure'] ?? null,
                    'supervisor_config' => $queueSnapshot['supervisor_config'] ?? null,
                    'worker_script' => $queueSnapshot['worker_script'] ?? null,
                    'promote_delayed_command' => $queueSnapshot['promote_delayed_command'] ?? null,
                    'health' => $queueSnapshot['health'] ?? null,
                    'failed_jobs_summary' => $queueSnapshot['failed_jobs_summary'] ?? null,
                    'queues' => $queueSnapshot['queues'] ?? [],
                ],
                'configured_sports' => $this->configuredSports(),
                'xs2_enabled' => $xs2Enabled,
                'xs2_configured' => $xs2Configured,
                'xs2_base_url' => $this->effectiveXs2BaseUrl(),
                'scheduler_enabled' => app(CronControlService::class)->schedulerEnabled(),
                'low_load_mode' => app(CronControlService::class)->lowLoadModeEnabled(),
                'cron_control' => app(CronControlService::class)->status(),
                'aws_emergency_steps' => AwsEmergencyStopGuide::steps(),
                'schedule_health' => $health,
                'generated_at' => now()->toIso8601String(),
            ],
            'tasks' => $tasks,
        ];
    }

    /**
     * Queue inventory sync jobs for all future (sellable) mapped events in a league.
     *
     * @return array<string, mixed>
     */
    public function queueInventorySyncByLeague(string $tournament, string $mode = 'full', bool $futureOnly = true): array
    {
        $tournament = trim($tournament);
        if ($tournament === '') {
            throw ValidationException::withMessages([
                'tournament' => ['A league / tournament name is required.'],
            ]);
        }

        if (! in_array($mode, ['incremental', 'full'], true)) {
            throw ValidationException::withMessages([
                'mode' => ['Mode must be incremental or full.'],
            ]);
        }

        if (! $this->xs2CredentialsConfigured()) {
            throw ValidationException::withMessages([
                'xs2' => ['XS2 base URL and API key are not configured. Set them in API Config or .env.'],
            ]);
        }

        $requestsPerMinute = max(1, (int) config('services.xs2.rate_limit_per_minute', config('xs2.rate_limit_per_minute', 30)));
        $dispatchSpacingSeconds = max(
            1,
            (int) config('xs2.inventory_dispatch_interval_seconds', ceil(120 / $requestsPerMinute)),
        );

        $mappingQuery = EventMapping::query()
            ->with('xs2Event')
            ->whereHas('xs2Event', function ($event) use ($tournament, $futureOnly): void {
                $event->where('tournament_name', $tournament);
                if ($futureOnly) {
                    $event->where(fn ($q) => $q
                        ->whereNull('date_start_local')
                        ->orWhere('date_start_local', '>=', now()));
                }
            });

        $mappings = $mappingQuery->get();
        $queued = 0;
        $skippedUnsellable = 0;
        $queuedMappingIds = [];
        $chunkSize = (int) config('pipeline.staggered_dispatch.chunk_size', 10);
        $delayPerWave = (int) config('pipeline.staggered_dispatch.delay_per_wave_seconds', 90);

        foreach ($mappings as $mapping) {
            $event = $mapping->xs2Event;
            if (! $event instanceof Xs2Event) {
                continue;
            }

            if ($futureOnly && ! $event->isSellable()) {
                $skippedUnsellable++;

                continue;
            }

            $wave = intdiv($queued, $chunkSize);
            $delaySeconds = $wave * $delayPerWave;

            SyncXs2EventInventory::dispatch($mapping->id, $mode, true)
                ->delay(now()->addSeconds($delaySeconds));
            $queuedMappingIds[] = $mapping->id;
            $queued++;
        }

        $progress = $this->leagueInventoryProgress($tournament, $queuedMappingIds);
        $totalWaves = $queued > 0 ? intdiv($queued - 1, $chunkSize) + 1 : 0;
        $estimatedSeconds = $totalWaves > 1 ? ($totalWaves - 1) * $delayPerWave : 0;

        return [
            'tournament' => $tournament,
            'mode' => $mode,
            'future_only' => $futureOnly,
            'matched_mappings' => $mappings->count(),
            'queued' => $queued,
            'skipped_unsellable' => $skippedUnsellable,
            'queue' => (string) config('xs2.queue', 'xs2-sync'),
            'dispatch_interval_seconds' => $dispatchSpacingSeconds,
            'worker_hint' => 'php artisan queue:work --queue='.(string) config('xs2.queue', 'xs2-sync'),
            'mapping_ids' => $queuedMappingIds,
            'progress' => $progress,
            'waves' => $totalWaves,
            'chunk_size' => $chunkSize,
            'delay_per_wave_seconds' => $delayPerWave,
            'estimated_completion_seconds' => $estimatedSeconds,
        ];
    }

    /**
     * Live/recent sync telemetry for the cron config UI log panel.
     *
     * @return array<string, mixed>
     */
    public function syncLogs(): array
    {
        $snapshot = $this->snapshot();
        $runningTasks = collect($snapshot['tasks'])
            ->filter(fn (array $task): bool => ($task['status'] ?? '') === 'running' || ($task['is_running'] ?? false))
            ->values()
            ->map(fn (array $task): array => [
                'id' => $task['id'],
                'name' => $task['name'],
                'status' => $task['status'],
                'sync_resource' => $task['sync_resource'] ?? null,
                'last_run_at' => $task['last_run_at'] ?? null,
                'last_error' => $task['last_error'] ?? null,
                'metadata' => $task['metadata'] ?? [],
                'extra' => $task['extra'] ?? [],
            ])
            ->all();

        $syncStates = $this->syncLogStates();
        $inventory = $this->syncLogInventory();
        $recorder = app(Xs2ApiDebugRecorder::class);
        $apiDebugBatches = $recorder->cronLog();
        $globalApiDebug = $recorder->lastGlobal();
        $entries = $this->buildSyncLogEntries($runningTasks, $syncStates, $inventory, $apiDebugBatches);

        return [
            'generated_at' => now()->toIso8601String(),
            'is_active' => count($runningTasks) > 0
                || ($inventory['running'] ?? 0) > 0
                || collect($syncStates)->contains(fn (array $state): bool => ($state['status'] ?? '') === 'running'),
            'running_task_count' => count($runningTasks),
            'running_tasks' => $runningTasks,
            'sync_states' => $syncStates,
            'inventory' => $inventory,
            'api_debug_batches' => $apiDebugBatches,
            'global_api_debug' => $globalApiDebug,
            'entries' => $entries,
        ];
    }

    /**
     * @param  list<int>  $mappingIds
     * @return array<string, mixed>
     */
    public function leagueInventoryProgress(string $tournament, array $mappingIds = []): array
    {
        if (! Schema::hasTable('xs2_event_inventory_sync_states')) {
            return [
                'status' => 'unknown',
                'running' => 0,
                'failed' => 0,
                'completed' => 0,
                'never_run' => 0,
                'total' => 0,
            ];
        }

        $eventIdsQuery = Xs2Event::query()->where('tournament_name', $tournament);
        if ($mappingIds !== []) {
            $eventIdsQuery->whereHas('mapping', fn ($mapping) => $mapping->whereIn('id', $mappingIds));
        }

        $eventIds = $eventIdsQuery->pluck('id');
        if ($eventIds->isEmpty()) {
            return [
                'status' => 'idle',
                'running' => 0,
                'failed' => 0,
                'completed' => 0,
                'never_run' => 0,
                'total' => 0,
            ];
        }

        $states = Xs2EventInventorySyncState::query()
            ->whereIn('xs2_event_id', $eventIds)
            ->get();

        $running = $states->where('tickets_sync_status', 'running')->count();
        $failed = $states->where('tickets_sync_status', 'failed')->count();
        $completed = $states->filter(fn ($state) => in_array($state->tickets_sync_status, ['completed', 'success'], true))->count();
        $tracked = $states->count();
        $neverRun = max(0, $eventIds->count() - $tracked);

        $status = match (true) {
            $running > 0 => 'running',
            $failed > 0 && $completed === 0 && $running === 0 => 'failed',
            $completed > 0 && $running === 0 && $neverRun === 0 => 'completed',
            $completed > 0 || $failed > 0 => 'partial',
            default => 'queued',
        };

        return [
            'status' => $status,
            'running' => $running,
            'failed' => $failed,
            'completed' => $completed,
            'never_run' => $neverRun,
            'total' => $eventIds->count(),
        ];
    }

    public function xs2CredentialsConfigured(): bool
    {
        return filled($this->effectiveXs2BaseUrl()) && filled($this->effectiveXs2ApiKey());
    }

    public function effectiveXs2BaseUrl(): ?string
    {
        $override = app(IntegrationSettingService::class)->value(IntegrationSettingService::XS2_BASE_URL);
        if (filled($override)) {
            return $override;
        }

        $fromConfig = config('services.xs2.base_url') ?: config('xs2.base_url');

        return is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;
    }

    public function effectiveXs2ApiKey(): ?string
    {
        $override = app(IntegrationSettingService::class)->value(IntegrationSettingService::XS2_API_KEY);
        if (filled($override)) {
            return $override;
        }

        $fromConfig = config('services.xs2.api_key') ?: config('xs2.api_key');

        return is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;
    }

    /**
     * Drop stale inventory failures that only reflected missing XS2 credentials
     * once base URL + API key resolve from integration settings or env.
     */
    public function clearResolvedXs2ConfigurationErrors(): int
    {
        if (! Schema::hasTable('xs2_event_inventory_sync_states') || ! $this->xs2CredentialsConfigured()) {
            return 0;
        }

        $states = Xs2EventInventorySyncState::query()
            ->where('tickets_sync_status', 'failed')
            ->whereNotNull('tickets_sync_error')
            ->get();

        $cleared = 0;
        foreach ($states as $state) {
            if (! $this->isResolvedXs2ConfigurationError((string) $state->tickets_sync_error)) {
                continue;
            }

            $hadSuccess = filled($state->tickets_last_incremental_sync_at)
                || filled($state->tickets_last_full_sync_at);

            $state->update([
                'tickets_sync_status' => $hadSuccess ? 'completed' : 'never_run',
                'tickets_sync_error' => null,
            ]);
            $cleared++;
        }

        return $cleared;
    }

    public function isResolvedXs2ConfigurationError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'xs2_base_url is not configured')
            || str_contains($normalized, 'xs2_api_key is not configured')
            || (str_contains($normalized, 'xs2 integration is enabled but')
                && str_contains($normalized, 'is not configured'));
    }

    /** @return array<string, Xs2SyncState> */
    private function syncStatesByResource(): array
    {
        if (! Schema::hasTable('xs2_sync_states')) {
            return [];
        }

        return Xs2SyncState::query()->get()->keyBy('resource')->all();
    }

    /**
     * @param  array{incremental: array<string, mixed>, full: array<string, mixed>}  $telemetry
     * @param  array<string, array<string, mixed>>  $scheduleByTaskId
     * @return list<array<string, mixed>>
     */
    private function inventoryTasks(array $telemetry, array $scheduleByTaskId, bool $xs2Enabled): array
    {
        $incremental = $this->finalizeTask(
            $this->task(
                id: 'xs2-inventory-incremental',
                name: 'XS2 inventory sync (incremental)',
                type: 'command',
                command: 'xs2:sync-inventory --mode=incremental',
                schedule: 'Every '.max(10, (int) config('xs2.sync.incremental_interval_minutes', 30)).' minutes',
                scheduleDetail: 'Skipped at minute :00 so the scheduled full inventory run can take priority.',
                queue: (string) config('xs2.queue', 'xs2-sync'),
                syncResource: null,
                state: null,
                extra: $telemetry['incremental'],
                enabled: $xs2Enabled,
            ),
            $scheduleByTaskId['xs2-inventory-incremental'] ?? null,
            statusOverride: $telemetry['incremental']['status'] ?? null,
            lastRunAt: $telemetry['incremental']['last_run_at'] ?? null,
            lastSuccessfulAt: $telemetry['incremental']['last_successful_at'] ?? null,
            lastError: $telemetry['incremental']['last_error'] ?? null,
            isRunningOverride: (bool) ($telemetry['incremental']['is_running'] ?? false),
        );

        $full = $this->finalizeTask(
            $this->task(
                id: 'xs2-inventory-full',
                name: 'XS2 inventory sync (full)',
                type: 'command',
                command: 'xs2:sync-inventory --mode=full',
                schedule: 'Every '.max(1, (int) ceil(max(60, (int) config('xs2.sync.full_interval_minutes', 180)) / 60)).' hour(s)',
                scheduleDetail: 'Runs at the start of each configured full-sync interval.',
                queue: (string) config('xs2.queue', 'xs2-sync'),
                syncResource: null,
                state: null,
                extra: $telemetry['full'],
                enabled: $xs2Enabled,
            ),
            $scheduleByTaskId['xs2-inventory-full'] ?? null,
            statusOverride: $telemetry['full']['status'] ?? null,
            lastRunAt: $telemetry['full']['last_run_at'] ?? null,
            lastSuccessfulAt: $telemetry['full']['last_successful_at'] ?? null,
            lastError: $telemetry['full']['last_error'] ?? null,
            isRunningOverride: (bool) ($telemetry['full']['is_running'] ?? false),
        );

        return [$incremental, $full];
    }

    /**
     * @param  array<string, array<string, mixed>>  $scheduleByTaskId
     * @return list<array<string, mixed>>
     */
    private function sbNewListingPublishTasks(array $scheduleByTaskId): array
    {
        $enabled = (bool) config('xs2.sb_new_listing_publish.enabled', true)
            && (bool) config('services.seller_api.enabled', true)
            && $this->sellerApiListingConfigured();
        $telemetry = app(SbNewListingPublishService::class)->telemetry();
        $interval = max(1, min(59, (int) config('xs2.sb_new_listing_publish.sync_interval_minutes', 1)));
        $state = $this->syncStatesByResource()[SbNewListingPublishService::SYNC_RESOURCE] ?? null;

        return [
            $this->finalizeTask(
                $this->task(
                    id: 'xs2-sb-new-listing-publish',
                    name: 'Seats Broker new listing publish',
                    type: 'command',
                    command: 'xs2:publish-new-sb-listings',
                    schedule: $interval <= 1 ? 'Every minute' : 'Every '.$interval.' minutes',
                    scheduleDetail: 'Publishes new XS2 inventory on mapped events to Seats Broker. Runs when an event is mapped or when fresh XS2 tickets appear on an already-mapped event. Skips tickets that already have an active SB master or split listing — quantity updates are handled by the SB listing inventory sync cron.',
                    queue: (string) config('services.seller_api.queue', 'seller-api'),
                    syncResource: SbNewListingPublishService::SYNC_RESOURCE,
                    state: $state,
                    extra: [
                        'cron_role' => 'new_listing_publish',
                        'cron_role_label' => 'New XS2 inventory only',
                        'what_it_does' => 'Creates Seats Broker listings for XS2 tickets that are eligible to sell but not yet published on SB.',
                        'does_not_do' => 'Does not update quantities or remove splits for listings that already exist on SB. Use Seats Broker existing listing qty sync when stock changes on published listings.',
                        'algorithm' => [
                            'Find available XS2 tickets with stock on events mapped to a local match (status mapped/created, m_id set).',
                            'Resolve ticket mapping status; auto-publish only when ready_to_publish or published (full validation via ListingPublishReadinessService).',
                            'Skip tickets that already have an active SB master listing id or active split listing ids.',
                            'Apply listing publish rules (single vs split mode, qty caps, pairs-only) and queue Seller API create jobs.',
                            'Typical triggers: admin maps an event; XS2 inventory sync imports a new category/ticket row; category mapping becomes confirmed.',
                        ],
                        'examples' => [
                            'Event FC Barcelona vs Rayo mapped → Tribuna ticket stock 8, not on SB → cron creates 4 split listings (qty 2 each).',
                            'Mapped event receives new Lateral ticket from XS2 inventory sync → cron publishes only the new Lateral row.',
                            'Tribuna already on SB with listing ids → cron skips it even if XS2 stock changed (inventory sync cron handles qty).',
                        ],
                        'manual_command' => 'php artisan xs2:publish-new-sb-listings --sync --ticket={id}',
                        'worker_hint' => 'php artisan queue:work --queue='.(string) config('services.seller_api.queue', 'seller-api'),
                        'sync_interval_minutes' => $interval,
                        'eligible_tickets' => $telemetry['eligible_tickets'] ?? 0,
                        'pending_publish' => $telemetry['pending_publish'] ?? 0,
                    ],
                    enabled: $enabled,
                    category: 'sb',
                ),
                $scheduleByTaskId['xs2-sb-new-listing-publish'] ?? null,
                statusOverride: $telemetry['status'] ?? null,
                lastRunAt: $telemetry['last_run_at'] ?? null,
                lastSuccessfulAt: $telemetry['last_successful_at'] ?? null,
                lastError: $telemetry['last_error'] ?? null,
                isRunningOverride: (bool) ($telemetry['is_running'] ?? false),
            ),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $scheduleByTaskId
     * @return list<array<string, mixed>>
     */
    private function sbListingInventoryTasks(array $scheduleByTaskId): array
    {
        $enabled = (bool) config('xs2.sb_listing_inventory.enabled', true)
            && (bool) config('services.seller_api.enabled', true)
            && $this->sellerApiListingConfigured();
        $telemetry = app(SbListingInventorySyncService::class)->telemetry();
        $interval = max(1, min(59, (int) config('xs2.sb_listing_inventory.sync_interval_minutes', 30)));
        $unpublishMax = max(0, (int) config('xs2.split_listings.unpublish_stock_max', 0));
        $state = $this->syncStatesByResource()[SbListingInventorySyncService::SYNC_RESOURCE] ?? null;

        return [
            $this->finalizeTask(
                $this->task(
                    id: 'xs2-sb-listing-inventory',
                    name: 'Seats Broker existing listing qty sync',
                    type: 'command',
                    command: 'xs2:sync-sb-listing-inventory',
                    schedule: 'Every '.$interval.' minutes',
                    scheduleDetail: 'Only for XS2 tickets that already have an active Seats Broker listing id. When XS2 stock/qty changes, updates SB to match listing publish rules (master qty edit, split recalculation, trailing split delete). Never picks new/unpublished XS2 inventory — that is handled by Seats Broker new listing publish.',
                    queue: (string) config('services.seller_api.queue', 'seller-api'),
                    syncResource: SbListingInventorySyncService::SYNC_RESOURCE,
                    state: $state,
                    extra: [
                        'cron_role' => 'existing_listing_qty_sync',
                        'cron_role_label' => 'Existing SB listings only',
                        'what_it_does' => 'Updates quantity and split layout on Seats Broker for listings that were already pushed. Re-applies listing publish rules when XS2 stock changes (e.g. stock 8 → 6 removes one split of qty 2).',
                        'does_not_do' => 'Never scans for or publishes new XS2 tickets that are not yet on SB. Ignores unmapped events and tickets without a seller_listing_id or active split listing ids.',
                        'algorithm' => [
                            'Eligibility gate: master tickets must have external_listing_mappings.seller_listing_id (active/failed) and mapping_status published; split tickets must have active listing_splits with seatsbroker_listing_id.',
                            'Skip every ticket that has never been published to SB — those are picked up only by xs2:publish-new-sb-listings.',
                            'Compare XS2 stock (minus SB sold qty) to last pushed SB quantity.',
                            'Master (1:1): PushXs2TicketToSellerApi updates qty on the existing SB listing id, or disable when unavailable.',
                            'Split: recalculate plan from current stock using the ticket split_size from listing publish rules; update surviving splits; delete trailing splits when stock drops; create extra splits when stock increases.',
                            ($unpublishMax > 0
                                ? 'Stock in (0..'.$unpublishMax.'] or ticket/event unavailable → disable all SB split listings (soft, local rows kept).'
                                : 'Stock 0 or ticket/event unavailable → disable all SB split listings (soft, local rows kept).'),
                        ],
                        'examples' => [
                            'Tribuna already on SB as 4× qty 2 splits; XS2 stock 8 → 6 → cron deletes 1 split, keeps 3× qty 2 (per publish rule split_size=2).',
                            'Corner master listing 912502 qty 2 on SB; XS2 stock stays 2 → cron skips (already in sync).',
                            'New Lateral ticket appears on mapped event but not yet on SB → cron ignores it (new listing publish cron handles it).',
                        ],
                        'manual_command' => 'php artisan xs2:sync-sb-listing-inventory --sync --ticket={id}',
                        'worker_hint' => 'php artisan queue:work --queue='.(string) config('services.seller_api.queue', 'seller-api'),
                        'unpublish_stock_max' => $unpublishMax,
                        'sync_interval_minutes' => $interval,
                        'eligible_tickets' => $telemetry['eligible_tickets'] ?? 0,
                        'pending_sync' => $telemetry['pending_sync'] ?? 0,
                        'master_eligible' => $telemetry['masters']['eligible_tickets'] ?? 0,
                        'split_eligible' => $telemetry['splits']['eligible_tickets'] ?? 0,
                        'active_splits' => $telemetry['splits']['active_splits'] ?? 0,
                        'failed_splits' => $telemetry['splits']['failed_splits'] ?? 0,
                    ],
                    enabled: $enabled,
                    category: 'sb',
                ),
                $scheduleByTaskId['xs2-sb-listing-inventory'] ?? null,
                statusOverride: $telemetry['status'] ?? null,
                lastRunAt: $telemetry['last_run_at'] ?? null,
                lastSuccessfulAt: $telemetry['last_successful_at'] ?? null,
                lastError: $telemetry['last_error'] ?? null,
                isRunningOverride: (bool) ($telemetry['is_running'] ?? false),
            ),
        ];
    }

    public function sellerApiListingConfigured(): bool
    {
        $listingBase = app(IntegrationSettingService::class)->value(IntegrationSettingService::SELLER_LISTING_BASE_URL);
        if (filled($listingBase)) {
            return true;
        }

        $fromConfig = config('services.seller_api.listing_base_url') ?: config('seller-api.listing_base_url');

        return is_string($fromConfig) && $fromConfig !== '';
    }

    /**
     * @param  array<string, Xs2SyncState>  $states
     * @param  array<string, array<string, mixed>>  $scheduleByTaskId
     * @return list<array<string, mixed>>
     */
    private function xs2SbOrderSyncTasks(array $scheduleByTaskId, array $states, bool $xs2Enabled): array
    {
        $enabled = (bool) config('xs2.sb_bookings_sync.enabled', true)
            && (bool) config('services.seller_api.enabled', true)
            && $this->sellerApiListingConfigured();
        $telemetry = $this->xs2SbOrderSyncTelemetry($states);
        $interval = max(1, min(59, (int) config('xs2.sb_bookings_sync.sync_interval_minutes', 2)));
        $orderQueue = (string) config('xs2.sandbox.order_queue', config('xs2.queue', 'xs2-sync'));
        $state = $states[SellerBookingSyncService::SYNC_RESOURCE] ?? null;
        $webhookUrl = rtrim((string) config('app.url'), '/').'/api/webhooks/sb/orders';

        return [
            $this->finalizeTask(
                $this->task(
                    id: 'xs2-sb-order-sync',
                    name: 'SB order → XS2 sandbox order sync',
                    type: 'command',
                    command: 'seller-api:sync-bookings',
                    schedule: match (true) {
                        $interval <= 1 => 'Every minute',
                        $interval === 2 => 'Every 2 minutes',
                        default => 'Every '.$interval.' minutes',
                    },
                    scheduleDetail: 'When a Seats Broker order is received, creates a matching reservation and booking on the XS2 sandbox API (when Create Order API is set to sandbox). Runs on SB webhook in real time; this cron polls GET /api/booking as a backup so missed webhooks still sync.',
                    queue: $orderQueue,
                    syncResource: SellerBookingSyncService::SYNC_RESOURCE,
                    state: $state,
                    extra: [
                        'cron_role' => 'sb_order_xs2_order_sync',
                        'cron_role_label' => 'SB order → XS2 booking',
                        'what_it_does' => 'Imports SB marketplace bookings into sb_orders, then immediately queues CreateXs2SandboxOrderFromSbOrder when the sold listing maps to a sandbox XS2 ticket. Creates the XS2 reservation + booking on testapi.xs2event.com and stores the result in xs2_orders.',
                        'does_not_do' => 'Does not run when Create Order API is set to production (not implemented yet). Skips orders whose SB listing is not linked to a sandbox-imported XS2 ticket, cancelled orders, or orders that already have an XS2 booking id.',
                        'algorithm' => [
                            'Real-time path: SB POST '.$webhookUrl.' → upsert sb_orders → queue XS2 sandbox order job if eligible.',
                            'Scheduled path (this cron): GET Seller API /api/booking → upsert each booking → same XS2 queue step.',
                            'CreateXs2SandboxOrderFromSbOrder resolves SB ticket_id / listing_id to external_listing_mappings or listing_splits → sandbox XS2 ticket.',
                            'Calls XS2 sandbox reservation + booking APIs; stores xs2_booking_id / xs2_bookingorder_id on xs2_orders.',
                            'Requires Create Order API = sandbox (Admin → API Config) and XS2_SANDBOX_AUTO_CREATE_ORDERS_FROM_SB=true.',
                        ],
                        'examples' => [
                            'Customer buys Tribuna split listing 912503 on SB → webhook fires → XS2 sandbox booking created for the mapped sandbox ticket.',
                            'Webhook missed → cron fetches booking 1BX67140 from SB → queues same XS2 order job within 2 minutes.',
                            'Create Order API = production → cron still imports SB orders locally but skips XS2 booking creation.',
                        ],
                        'manual_command' => 'php artisan seller-api:sync-bookings',
                        'worker_hint' => 'php artisan queue:work --queue='.$orderQueue,
                        'webhook_url' => $webhookUrl,
                        'create_order_api' => $telemetry['create_order_api'] ?? 'sandbox',
                        'auto_create_enabled' => $telemetry['auto_create_enabled'] ?? false,
                        'sb_orders_total' => $telemetry['sb_orders_total'] ?? 0,
                        'xs2_orders_from_sb' => $telemetry['xs2_orders_from_sb'] ?? 0,
                        'sync_interval_minutes' => $interval,
                    ],
                    enabled: $enabled,
                    category: 'xs2',
                ),
                $scheduleByTaskId['xs2-sb-order-sync'] ?? null,
                statusOverride: $telemetry['status'] ?? null,
                lastRunAt: $telemetry['last_run_at'] ?? null,
                lastSuccessfulAt: $telemetry['last_successful_at'] ?? null,
                lastError: $telemetry['last_error'] ?? null,
                isRunningOverride: (bool) ($telemetry['is_running'] ?? false),
            ),
        ];
    }

    /**
     * @param  array<string, Xs2SyncState>  $states
     * @return array<string, mixed>
     */
    private function xs2SbOrderSyncTelemetry(array $states): array
    {
        $apiEnvironment = app(ApiEnvironmentService::class);
        $state = $states[SellerBookingSyncService::SYNC_RESOURCE] ?? null;
        $rawStatus = $state?->status ?? 'never_run';

        $sbOrdersTotal = Schema::hasTable('sb_orders') ? (int) SbOrder::query()->count() : 0;
        $xs2FromSb = Schema::hasTable('xs2_orders') && Schema::hasColumn('xs2_orders', 'sb_order_id')
            ? (int) Xs2Order::query()->whereNotNull('sb_order_id')->count()
            : 0;

        return [
            'status' => $rawStatus,
            'last_run_at' => $state?->last_attempted_at?->toIso8601String(),
            'last_successful_at' => $state?->last_successful_at?->toIso8601String(),
            'last_error' => filled($state?->last_error) ? (string) $state->last_error : null,
            'is_running' => $rawStatus === 'running',
            'create_order_api' => $apiEnvironment->xs2OrdersEnvironment(),
            'auto_create_enabled' => (bool) config('xs2.sandbox.auto_create_orders_from_sb', true),
            'sb_orders_total' => $sbOrdersTotal,
            'xs2_orders_from_sb' => $xs2FromSb,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $scheduleByTaskId
     * @param  array<string, Xs2SyncState>  $states
     * @return list<array<string, mixed>>
     */
    private function xs2SbOrderGuestDataSyncTasks(array $scheduleByTaskId, array $states, bool $xs2Enabled): array
    {
        $enabled = (bool) config('xs2.sb_order_guest_data_sync.enabled', true);
        $telemetry = $this->xs2SbOrderGuestDataSyncTelemetry($states);
        $interval = max(1, min(59, (int) config('xs2.sb_order_guest_data_sync.sync_interval_minutes', 30)));
        $guestQueue = (string) config('xs2.sb_order_guest_data_sync.queue', config('xs2.guest_queue', 'xs2-guest'));
        $state = $states[SbOrderXs2GuestDataSyncService::SYNC_RESOURCE] ?? null;
        $webhookUrl = rtrim((string) config('app.url'), '/').'/api/webhooks/sb/orders';

        return [
            $this->finalizeTask(
                $this->task(
                    id: 'xs2-sb-order-guest-data-sync',
                    name: 'SB order attendee fetch (once)',
                    type: 'command',
                    command: 'xs2:sync-order-guest-data',
                    schedule: 'Every '.$interval.' minutes',
                    scheduleDetail: 'Fetches attendee/guest details from Seats Broker once per SB order. After a successful fetch, attendee_fetched_at is set and this cron never checks that order again. Manual Fetch attendee on the SB orders page can still re-fetch.',
                    queue: $guestQueue,
                    syncResource: SbOrderXs2GuestDataSyncService::SYNC_RESOURCE,
                    state: $state,
                    extra: [
                        'cron_role' => 'sb_order_xs2_guest_data_sync',
                        'cron_role_label' => 'SB attendee fetch (once)',
                        'what_it_does' => 'Finds sb_orders that do not yet have attendee_fetched_at, GETs that booking from the Seller API, and persists attendee_details. Stops polling an order after the first successful attendee fetch. Does not push guest data to XS2 (use Move to XS order + Push to XS2 API).',
                        'does_not_do' => 'Does not re-fetch attendees for orders already marked fetched. Does not copy attendees onto xs2_orders. Does not PUT XS2 guestdata. Does not create XS2 bookings.',
                        'algorithm' => [
                            'Query active sb_orders where attendee_fetched_at is null.',
                            'GET Seller API /api/booking?booking_no=… for each pending order (batch_limit).',
                            'If attendee_details is non-empty, upsert sb_order_attendees and set attendee_fetched_at.',
                            'If empty, leave unmarked so a later run can retry until guests are filled on SB.',
                            'Skip any order already marked fetched — including bulk seller-api:sync-bookings attendee overwrites.',
                        ],
                        'examples' => [
                            'New SB sale without guests yet → cron keeps checking until attendee_details arrive, then stops.',
                            'Manual Fetch attendee on /admin/xs2/orders re-fetches even after cron marked the order.',
                            'Move to XS order copies stored attendees onto the linked xs2_order; Push to XS2 API is a separate admin action.',
                        ],
                        'manual_command' => 'php artisan xs2:sync-order-guest-data',
                        'manual_command_single' => 'php artisan xs2:sync-order-guest-data --sb-order-id={id}',
                        'worker_hint' => 'php artisan queue:work --queue='.$guestQueue,
                        'webhook_url' => $webhookUrl,
                        'batch_limit' => (int) config('xs2.sb_order_guest_data_sync.batch_limit', 50),
                        'pending_guest_sync' => $telemetry['pending_guest_sync'] ?? 0,
                        'pending_attendee_fetch' => $telemetry['pending_attendee_fetch'] ?? 0,
                        'synced_guest_data' => $telemetry['synced_guest_data'] ?? 0,
                        'fetched_attendees' => $telemetry['fetched_attendees'] ?? 0,
                        'sync_interval_minutes' => $interval,
                    ],
                    enabled: $enabled && $xs2Enabled,
                    category: 'xs2',
                ),
                $scheduleByTaskId['xs2-sb-order-guest-data-sync'] ?? null,
                statusOverride: $telemetry['status'] ?? null,
                lastRunAt: $telemetry['last_run_at'] ?? null,
                lastSuccessfulAt: $telemetry['last_successful_at'] ?? null,
                lastError: $telemetry['last_error'] ?? null,
                isRunningOverride: (bool) ($telemetry['is_running'] ?? false),
            ),
        ];
    }

    /**
     * @param  array<string, Xs2SyncState>  $states
     * @return array<string, mixed>
     */
    private function xs2SbOrderGuestDataSyncTelemetry(array $states): array
    {
        $state = $states[SbOrderXs2GuestDataSyncService::SYNC_RESOURCE] ?? null;
        $rawStatus = $state?->status ?? 'never_run';

        $pendingGuestSync = 0;
        $syncedGuestData = 0;
        $pendingAttendeeFetch = 0;
        $fetchedAttendees = 0;

        if (Schema::hasTable('sb_orders') && Schema::hasColumn('sb_orders', 'attendee_fetched_at')) {
            $fetchedAttendees = (int) SbOrder::query()->whereNotNull('attendee_fetched_at')->count();
            $pendingAttendeeFetch = (int) SbOrder::query()
                ->activeSold()
                ->whereNull('attendee_fetched_at')
                ->count();
            $pendingGuestSync = $pendingAttendeeFetch;
        }

        if (Schema::hasTable('xs2_orders') && Schema::hasColumn('xs2_orders', 'sb_order_id')) {
            $syncedGuestData = (int) Xs2Order::query()
                ->whereNotNull('sb_order_id')
                ->whereNotNull('guest_data_synced_at')
                ->count();
        }

        return [
            'status' => $rawStatus,
            'last_run_at' => $state?->last_attempted_at?->toIso8601String(),
            'last_successful_at' => $state?->last_successful_at?->toIso8601String(),
            'last_error' => filled($state?->last_error) ? (string) $state->last_error : null,
            'is_running' => $rawStatus === 'running',
            'pending_guest_sync' => $pendingGuestSync,
            'pending_attendee_fetch' => $pendingAttendeeFetch,
            'synced_guest_data' => $syncedGuestData,
            'fetched_attendees' => $fetchedAttendees,
        ];
    }

    /**
     * @param  array<string, Xs2SyncState>  $states
     * @param  array<string, array<string, mixed>>  $scheduleByTaskId
     * @return list<array<string, mixed>>
     */
    private function xs2EventTasks(array $states, array $scheduleByTaskId, bool $xs2Enabled): array
    {
        $sports = $this->configuredSports();
        $telemetry = $this->xs2EventsSyncTelemetry($sports, $states);
        $sportsLabel = $sports === [] ? 'none configured' : implode(', ', $sports);
        $eventsScheduled = (bool) config('xs2.events_sync.schedule_enabled', false);

        return [
            $this->finalizeTask(
                $this->task(
                    id: 'xs2-events-sync',
                    name: 'XS2 events sync',
                    type: 'command',
                    command: 'xs2:sync-events',
                    schedule: $eventsScheduled ? 'Hourly + daily full' : 'Manual only',
                    scheduleDetail: $eventsScheduled
                        ? 'Imports and maps XS2 events for every sport in XS2_SPORTS. Hourly incremental sync runs at :00–:23 except midnight; daily full reconciliation runs once at midnight and re-checks missing events across all sports.'
                        : 'Not scheduled automatically. Click Run now on Cron Jobs to import XS2 events when needed.',
                    queue: (string) config('xs2.queue', 'xs2-sync'),
                    syncResource: null,
                    state: null,
                    extra: [
                        'cron_role' => 'xs2_events_sync',
                        'cron_role_label' => 'All configured sports',
                        'manual_only' => ! $eventsScheduled,
                        'configured_sports' => $sports,
                        'what_it_does' => 'Pulls event catalog pages from the XS2 API for every configured sport (XS2_SPORTS), upserts xs2_events rows, and creates or updates local event mappings so admin can match SB catalogue events.',
                        'does_not_do' => 'Does not sync ticket inventory (use XS2 inventory sync) and does not publish listings to Seats Broker (use SB crons). Does not run per-sport as separate cron cards — one run queues all sports.',
                        'algorithm' => [
                            'Read XS2_SPORTS from config (e.g. soccer, rugby, formula1, tennis).',
                            'For each sport, queue SyncXs2EventsJob (incremental hourly, or full at midnight).',
                            'Incremental: fetch changed XS2 events since last sync and upsert local records.',
                            'Full (daily): paginate the complete XS2 event list, reconcile missing events, refresh mappings.',
                            'Admin Run now queues the same all-sports sync without requiring a sport filter.',
                        ],
                        'examples' => [
                            'XS2_SPORTS=soccer,tennis → one cron run queues soccer + tennis jobs back-to-back.',
                            'New tennis event appears on XS2 → next hourly run imports it; admin maps it on XS2 Events page.',
                            'Midnight full run catches events missed by incremental overlap windows.',
                        ],
                        'manual_command' => 'php artisan xs2:sync-events',
                        'manual_command_full' => 'php artisan xs2:sync-events --full --sync',
                        'worker_hint' => 'php artisan queue:work --queue='.(string) config('xs2.queue', 'xs2-sync'),
                        'sports' => $sportsLabel,
                        'sport_count' => count($sports),
                        'events_pending' => $telemetry['events_pending'] ?? 0,
                        'events_mapped' => $telemetry['events_mapped'] ?? 0,
                        'failed_sports' => $telemetry['failed_sports'] ?? 0,
                        'running_sports' => $telemetry['running_sports'] ?? 0,
                    ],
                    enabled: $xs2Enabled && $sports !== [],
                ),
                $scheduleByTaskId['xs2-events-sync'] ?? null,
                statusOverride: $telemetry['status'] ?? null,
                lastRunAt: $telemetry['last_run_at'] ?? null,
                lastSuccessfulAt: $telemetry['last_successful_at'] ?? null,
                lastError: $telemetry['last_error'] ?? null,
                isRunningOverride: (bool) ($telemetry['is_running'] ?? false),
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sbEventsSyncTasks(): array
    {
        $enabled = (bool) config('services.seller_api.enabled', true);

        return [
            $this->finalizeTask(
                $this->task(
                    id: 'sb-events-sync',
                    name: 'Seats Broker catalog events sync',
                    type: 'command',
                    command: 'seller-api:fetch-events',
                    schedule: 'Manual only',
                    scheduleDetail: 'Refreshes the Seats Broker external catalog cache (read-only). Import events into match_info via Admin → Events import or bulk sync by tournament — not scheduled automatically.',
                    queue: null,
                    syncResource: null,
                    state: null,
                    extra: [
                        'cron_role' => 'sb_catalog_events_sync',
                        'cron_role_label' => 'Catalog refresh only',
                        'manual_only' => true,
                        'what_it_does' => 'Fetches the Seats Broker GET /api/events catalog and refreshes the local search cache. Does not write match_info rows — use Events import or bulk sync for that.',
                        'does_not_do' => 'Does not run on a schedule. Does not import tournaments or create local events without an admin import action.',
                        'manual_command' => 'php artisan seller-api:fetch-events',
                        'manual_command_full' => 'php artisan seller-api:fetch-events --environment=production --save',
                        'import_ui_path' => '/events/import',
                    ],
                    enabled: $enabled,
                    category: 'sb',
                ),
                null,
            ),
        ];
    }

    /**
     * @param  list<string>  $sports
     * @param  array<string, Xs2SyncState>  $states
     * @return array<string, mixed>
     */
    private function xs2EventsSyncTelemetry(array $sports, array $states): array
    {
        if ($sports === []) {
            return [
                'status' => 'disabled',
                'last_run_at' => null,
                'last_successful_at' => null,
                'last_error' => null,
                'is_running' => false,
                'events_pending' => 0,
                'events_mapped' => 0,
                'failed_sports' => 0,
                'running_sports' => 0,
            ];
        }

        $runningSports = 0;
        $failedSports = 0;
        $eventsPending = 0;
        $eventsMapped = 0;
        $lastAttempted = null;
        $lastSuccessful = null;
        $lastError = null;

        foreach ($sports as $sport) {
            $state = $states["events:{$sport}"] ?? null;
            if ($state === null) {
                continue;
            }

            if ($state->status === 'running') {
                $runningSports++;
            }
            if ($state->status === 'failed') {
                $failedSports++;
                $lastError ??= filled($state->last_error) ? (string) $state->last_error : null;
            }

            $metadata = is_array($state->metadata) ? $state->metadata : [];
            $eventsPending += (int) ($metadata['events_pending'] ?? 0);
            $eventsMapped += (int) ($metadata['events_mapped'] ?? 0);

            $attempted = $state->last_attempted_at;
            if ($attempted !== null && ($lastAttempted === null || $attempted->gt($lastAttempted))) {
                $lastAttempted = $attempted;
            }

            $successful = $state->last_successful_at;
            if ($successful !== null && ($lastSuccessful === null || $successful->gt($lastSuccessful))) {
                $lastSuccessful = $successful;
            }
        }

        $status = $runningSports > 0
            ? 'running'
            : ($failedSports > 0 ? 'failed' : ($lastSuccessful !== null ? 'completed' : 'never_run'));

        return [
            'status' => $status,
            'last_run_at' => $lastAttempted?->toIso8601String(),
            'last_successful_at' => $lastSuccessful?->toIso8601String(),
            'last_error' => $lastError,
            'is_running' => $runningSports > 0,
            'events_pending' => $eventsPending,
            'events_mapped' => $eventsMapped,
            'failed_sports' => $failedSports,
            'running_sports' => $runningSports,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function task(
        string $id,
        string $name,
        string $type,
        string $command,
        string $schedule,
        string $scheduleDetail,
        ?string $queue,
        ?string $syncResource,
        ?Xs2SyncState $state,
        array $extra,
        bool $enabled = true,
        string $category = 'xs2',
    ): array {
        $rawStatus = $state?->status ?? 'never_run';
        $lastAttempted = $state?->last_attempted_at?->toIso8601String();
        $lastSuccessful = $state?->last_successful_at?->toIso8601String();

        return [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'command' => $command,
            'schedule' => $schedule,
            'schedule_detail' => $scheduleDetail,
            'expression' => null,
            'mutex' => 'withoutOverlapping',
            'server' => 'onOneServer',
            'queue' => $queue,
            'sync_resource' => $syncResource,
            'enabled' => $enabled,
            'status' => $rawStatus,
            'is_running' => $rawStatus === 'running',
            'last_result' => null,
            'last_run_at' => $lastAttempted ?? $lastSuccessful,
            'last_attempted_at' => $lastAttempted,
            'last_successful_at' => $lastSuccessful,
            'next_run_at' => null,
            'last_error' => filled($state?->last_error) ? (string) $state->last_error : null,
            'updated_at' => $state?->updated_at?->toIso8601String(),
            'metadata' => $this->publicMetadata($state?->metadata),
            'extra' => $extra,
            'category' => $category,
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>|null  $scheduleMeta
     * @return array<string, mixed>
     */
    private function finalizeTask(
        array $task,
        ?array $scheduleMeta,
        ?string $statusOverride = null,
        ?string $lastRunAt = null,
        ?string $lastSuccessfulAt = null,
        ?string $lastError = null,
        ?bool $isRunningOverride = null,
    ): array {
        if ($scheduleMeta !== null) {
            $task['expression'] = $scheduleMeta['expression'] ?? $task['expression'];
            $task['next_run_at'] = $scheduleMeta['next_run_at'] ?? null;
            $task['schedule'] = $scheduleMeta['schedule_label'] ?? $task['schedule'];
            if (! empty($scheduleMeta['is_running'])) {
                $task['is_running'] = true;
            }
        }

        if ($lastRunAt !== null) {
            $task['last_run_at'] = $lastRunAt;
            $task['last_attempted_at'] = $lastRunAt;
        }

        if ($lastSuccessfulAt !== null) {
            $task['last_successful_at'] = $lastSuccessfulAt;
            if ($task['last_run_at'] === null) {
                $task['last_run_at'] = $lastSuccessfulAt;
            }
        }

        if ($lastError !== null) {
            $task['last_error'] = $lastError;
        }

        if ($isRunningOverride === true) {
            $task['is_running'] = true;
        }

        $rawStatus = $statusOverride ?? (string) ($task['status'] ?? 'never_run');
        $enabled = (bool) ($task['enabled'] ?? true);
        $isRunning = (bool) ($task['is_running'] ?? false);

        $task['last_result'] = $this->lastResult(
            rawStatus: $rawStatus,
            lastError: $task['last_error'] ?? null,
            lastSuccessfulAt: $task['last_successful_at'] ?? null,
        );
        $task['status'] = $this->normalizeStatus(
            enabled: $enabled,
            isRunning: $isRunning,
            rawStatus: $rawStatus,
            lastError: $task['last_error'] ?? null,
        );
        $task['is_running'] = $task['status'] === 'running';

        return $task;
    }

    private function normalizeStatus(bool $enabled, bool $isRunning, string $rawStatus, mixed $lastError): string
    {
        if (! $enabled) {
            return 'disabled';
        }

        if ($isRunning || $rawStatus === 'running' || $rawStatus === 'in_progress') {
            return 'running';
        }

        if ($rawStatus === 'failed' || filled($lastError)) {
            return 'failed';
        }

        return 'idle';
    }

    private function lastResult(string $rawStatus, mixed $lastError, mixed $lastSuccessfulAt): ?string
    {
        if ($rawStatus === 'failed' || filled($lastError)) {
            return 'failed';
        }

        if (in_array($rawStatus, ['completed', 'success'], true) || filled($lastSuccessfulAt)) {
            return 'success';
        }

        if ($rawStatus === 'running' || $rawStatus === 'in_progress') {
            return 'running';
        }

        return null;
    }

    /** @return array{incremental: array<string, mixed>, full: array<string, mixed>} */
    private function inventorySyncTelemetry(): array
    {
        if (! Schema::hasTable('xs2_event_inventory_sync_states')) {
            return [
                'incremental' => [
                    'tracked_events' => 0,
                    'running_events' => 0,
                    'failed_events' => 0,
                    'is_running' => false,
                    'status' => 'never_run',
                    'last_run_at' => null,
                    'last_successful_at' => null,
                    'last_error' => null,
                ],
                'full' => [
                    'tracked_events' => 0,
                    'running_events' => 0,
                    'failed_events' => 0,
                    'is_running' => false,
                    'status' => 'never_run',
                    'last_run_at' => null,
                    'last_successful_at' => null,
                    'last_error' => null,
                ],
            ];
        }

        $tracked = (int) Xs2EventInventorySyncState::query()->count();
        $running = (int) Xs2EventInventorySyncState::query()->where('tickets_sync_status', 'running')->count();
        $failed = (int) Xs2EventInventorySyncState::query()->where('tickets_sync_status', 'failed')->count();
        $lastError = Xs2EventInventorySyncState::query()
            ->where('tickets_sync_status', 'failed')
            ->whereNotNull('tickets_sync_error')
            ->orderByDesc('updated_at')
            ->value('tickets_sync_error');

        if (is_string($lastError)
            && $this->xs2CredentialsConfigured()
            && $this->isResolvedXs2ConfigurationError($lastError)) {
            $lastError = null;
        }

        $incrementalAt = Xs2EventInventorySyncState::query()->max('tickets_last_incremental_sync_at');
        $fullAt = Xs2EventInventorySyncState::query()->max('tickets_last_full_sync_at');
        $updatedAt = Xs2EventInventorySyncState::query()->max('updated_at');

        $sharedStatus = $running > 0
            ? 'running'
            : ($failed > 0 ? 'failed' : (($incrementalAt || $fullAt) ? 'completed' : 'never_run'));

        $base = [
            'tracked_events' => $tracked,
            'running_events' => $running,
            'failed_events' => $failed,
            'is_running' => $running > 0,
            'status' => $sharedStatus,
            'last_error' => filled($lastError) ? (string) $lastError : null,
        ];

        return [
            'incremental' => [
                ...$base,
                'last_successful_at' => $this->iso($incrementalAt),
                'last_run_at' => $this->iso($incrementalAt) ?? $this->iso($updatedAt),
            ],
            'full' => [
                ...$base,
                'last_successful_at' => $this->iso($fullAt),
                'last_run_at' => $this->iso($fullAt) ?? $this->iso($updatedAt),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function scheduleMetadataByTaskId(): array
    {
        try {
            /** @var list<Event> $events */
            $events = app(Schedule::class)->events();
        } catch (Throwable) {
            return [];
        }

        $indexed = [];

        foreach ($events as $event) {
            $taskId = $this->taskIdForScheduleEvent($event);
            if ($taskId === null) {
                continue;
            }

            $nextRun = null;
            try {
                $nextRun = $event->nextRunDate()?->toIso8601String();
            } catch (Throwable) {
                $nextRun = null;
            }

            $isRunning = false;
            try {
                $isRunning = $event->mutex->exists($event);
            } catch (Throwable) {
                $isRunning = false;
            }

            $existing = $indexed[$taskId] ?? null;
            $entry = [
                'expression' => (string) $event->expression,
                'next_run_at' => $nextRun,
                'is_running' => $isRunning,
                'schedule_label' => $this->humanScheduleLabel((string) $event->expression),
            ];

            if ($existing !== null) {
                $entry['expression'] = trim($existing['expression'].' · '.$entry['expression']);
                $entry['schedule_label'] = trim($existing['schedule_label'].' · '.$entry['schedule_label']);
                $entry['is_running'] = (bool) ($existing['is_running'] ?? false) || $isRunning;
                $existingNext = $existing['next_run_at'] ?? null;
                if ($existingNext !== null && $nextRun !== null) {
                    $entry['next_run_at'] = min($existingNext, $nextRun);
                } else {
                    $entry['next_run_at'] = $nextRun ?? $existingNext;
                }
            }

            $indexed[$taskId] = $entry;
        }

        return $indexed;
    }

    private function taskIdForScheduleEvent(Event $event): ?string
    {
        return CronTaskIdentifier::forEvent($event);
    }

    private function humanScheduleLabel(string $expression): string
    {
        $expression = trim($expression);

        $known = match ($expression) {
            '* * * * *', '*/1 * * * *' => 'Every minute',
            '*/10 * * * *' => 'Every 10 minutes',
            '*/2 * * * *' => 'Every 2 minutes',
            '*/5 * * * *' => 'Every 5 minutes',
            '0 * * * *' => 'Hourly',
            '0 0 * * *' => 'Daily',
            default => null,
        };

        if ($known !== null) {
            return $known;
        }

        if (preg_match('/^0 \*\/(\d+) \* \* \*$/', $expression, $matches) === 1) {
            $hours = max(1, (int) $matches[1]);

            return $hours === 1 ? 'Hourly' : 'Every '.$hours.' hour(s)';
        }

        if (preg_match('/^\*\/(\d+) \* \* \* \*$/', $expression, $matches) === 1) {
            $minutes = max(1, (int) $matches[1]);

            return $minutes === 1 ? 'Every minute' : 'Every '.$minutes.' minutes';
        }

        if (preg_match('/^([\d,]+) \* \* \* \*$/', $expression, $matches) === 1) {
            $minuteList = array_map('intval', explode(',', $matches[1]));
            if (count($minuteList) >= 2) {
                $interval = $minuteList[1] - $minuteList[0];
                if ($interval > 0) {
                    return $interval === 1 ? 'Every minute' : 'Every '.$interval.' minutes';
                }
            }

            if (count($minuteList) === 1) {
                return 'Hourly';
            }
        }

        return $expression;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return array<string, mixed>
     */
    private function scheduleHealth(array $tasks): array
    {
        $counts = [
            'running' => 0,
            'failed' => 0,
            'idle' => 0,
            'disabled' => 0,
        ];

        foreach ($tasks as $task) {
            $status = (string) ($task['status'] ?? 'idle');
            if (! array_key_exists($status, $counts)) {
                $counts['idle']++;

                continue;
            }
            $counts[$status]++;
        }

        $status = match (true) {
            $counts['failed'] > 0 => 'degraded',
            $counts['running'] > 0 => 'active',
            $counts['disabled'] === count($tasks) && count($tasks) > 0 => 'disabled',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'task_counts' => $counts,
            'total_tasks' => count($tasks),
        ];
    }

    /** @param array<string, mixed>|null $metadata */
    private function publicMetadata(?array $metadata): array
    {
        $fields = [
            'events_received',
            'events_created',
            'events_updated',
            'events_mapped',
            'events_pending',
            'local_events_created',
        ];

        return collect($fields)
            ->mapWithKeys(fn (string $field) => [$field => max(0, (int) ($metadata[$field] ?? 0))])
            ->all();
    }

    /** @return list<string> */
    private function configuredSports(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $sport) => is_string($sport) ? trim($sport) : '',
            config('services.xs2.sports', []),
        ))));
    }

    private function iso(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value)->toIso8601String();
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function syncLogStates(): array
    {
        if (! Schema::hasTable('xs2_sync_states')) {
            return [];
        }

        return Xs2SyncState::query()
            ->where(function ($query): void {
                $query->where('status', 'running')
                    ->orWhere('updated_at', '>=', now()->subHours(6));
            })
            ->orderByRaw("CASE status WHEN 'running' THEN 0 WHEN 'failed' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (Xs2SyncState $state): array => [
                'resource' => $state->resource,
                'status' => $state->status,
                'last_attempted_at' => $state->last_attempted_at?->toIso8601String(),
                'last_successful_at' => $state->last_successful_at?->toIso8601String(),
                'last_error' => filled($state->last_error) ? (string) $state->last_error : null,
                'updated_at' => $state->updated_at?->toIso8601String(),
                'metadata' => $this->publicMetadata($state->metadata),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function syncLogInventory(): array
    {
        if (! Schema::hasTable('xs2_event_inventory_sync_states')) {
            return [
                'running' => 0,
                'failed' => 0,
                'completed_recent' => 0,
                'recent_events' => [],
            ];
        }

        $running = (int) Xs2EventInventorySyncState::query()->where('tickets_sync_status', 'running')->count();
        $failed = (int) Xs2EventInventorySyncState::query()->where('tickets_sync_status', 'failed')->count();
        $completedRecent = (int) Xs2EventInventorySyncState::query()
            ->whereIn('tickets_sync_status', ['completed', 'success'])
            ->where('updated_at', '>=', now()->subMinutes(30))
            ->count();

        $recentEvents = Xs2EventInventorySyncState::query()
            ->with(['event:id,external_event_id,name,tournament_name'])
            ->where(function ($query): void {
                $query->where('tickets_sync_status', 'running')
                    ->orWhere('tickets_sync_status', 'failed')
                    ->orWhere('updated_at', '>=', now()->subMinutes(30));
            })
            ->orderByRaw("CASE tickets_sync_status WHEN 'running' THEN 0 WHEN 'failed' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->limit(40)
            ->get()
            ->map(fn (Xs2EventInventorySyncState $state): array => [
                'xs2_event_id' => $state->xs2_event_id,
                'external_event_id' => $state->event?->external_event_id,
                'event_name' => $state->event?->name,
                'tournament_name' => $state->event?->tournament_name,
                'status' => $state->tickets_sync_status,
                'error' => filled($state->tickets_sync_error) ? (string) $state->tickets_sync_error : null,
                'updated_at' => $state->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'running' => $running,
            'failed' => $failed,
            'completed_recent' => $completedRecent,
            'recent_events' => $recentEvents,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $runningTasks
     * @param  list<array<string, mixed>>  $syncStates
     * @param  array<string, mixed>  $inventory
     * @param  list<array<string, mixed>>  $apiDebugBatches
     * @return list<array<string, mixed>>
     */
    private function buildSyncLogEntries(
        array $runningTasks,
        array $syncStates,
        array $inventory,
        array $apiDebugBatches,
    ): array {
        $entries = [];

        foreach ($runningTasks as $task) {
            $entries[] = [
                'at' => $task['last_run_at'] ?? now()->toIso8601String(),
                'level' => 'info',
                'source' => 'cron-task',
                'message' => sprintf('%s is running.', $task['name'] ?? $task['id'] ?? 'Task'),
                'context' => [
                    'task_id' => $task['id'] ?? null,
                    'sync_resource' => $task['sync_resource'] ?? null,
                    'metadata' => $task['metadata'] ?? [],
                ],
            ];
        }

        foreach ($syncStates as $state) {
            if (($state['status'] ?? '') === 'running') {
                $entries[] = [
                    'at' => $state['last_attempted_at'] ?? $state['updated_at'] ?? now()->toIso8601String(),
                    'level' => 'info',
                    'source' => 'xs2-sync-state',
                    'message' => sprintf('Sync state %s is running.', $state['resource'] ?? 'unknown'),
                    'context' => [
                        'resource' => $state['resource'] ?? null,
                        'metadata' => $state['metadata'] ?? [],
                    ],
                ];
            } elseif (($state['status'] ?? '') === 'failed' && filled($state['last_error'] ?? null)) {
                $entries[] = [
                    'at' => $state['updated_at'] ?? now()->toIso8601String(),
                    'level' => 'error',
                    'source' => 'xs2-sync-state',
                    'message' => (string) $state['last_error'],
                    'context' => [
                        'resource' => $state['resource'] ?? null,
                        'metadata' => $state['metadata'] ?? [],
                    ],
                ];
            }
        }

        foreach ($inventory['recent_events'] ?? [] as $event) {
            $status = (string) ($event['status'] ?? 'unknown');
            $entries[] = [
                'at' => $event['updated_at'] ?? now()->toIso8601String(),
                'level' => $status === 'failed' ? 'error' : ($status === 'running' ? 'info' : 'debug'),
                'source' => 'inventory-sync',
                'message' => $status === 'failed' && filled($event['error'] ?? null)
                    ? sprintf('%s failed: %s', $event['event_name'] ?? $event['external_event_id'] ?? 'Event', $event['error'])
                    : sprintf('%s inventory sync %s.', $event['event_name'] ?? $event['external_event_id'] ?? 'Event', $status),
                'context' => $event,
            ];
        }

        foreach ($apiDebugBatches as $batch) {
            $interactionCount = is_array($batch['interactions'] ?? null) ? count($batch['interactions']) : 0;
            $entries[] = [
                'at' => $batch['recorded_at'] ?? now()->toIso8601String(),
                'level' => 'debug',
                'source' => 'xs2-api',
                'message' => sprintf(
                    'Recorded %d XS2 API interaction(s) from %s.',
                    $interactionCount,
                    $batch['source'] ?? 'sync',
                ),
                'context' => [
                    'task_id' => $batch['task_id'] ?? null,
                    'external_event_id' => $batch['external_event_id'] ?? null,
                    'interaction_count' => $interactionCount,
                ],
            ];
        }

        usort($entries, fn (array $left, array $right): int => strcmp((string) ($right['at'] ?? ''), (string) ($left['at'] ?? '')));

        return array_slice($entries, 0, 100);
    }
}
