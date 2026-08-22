<?php

namespace App\Services\Xs2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Xs2ResetService
{
    /**
     * Inventory-only tables cleared in FK-safe order (children first).
     * Preserves xs2_events, event_mappings, xs2_venues, xs2_stadium_mappings, and orders.
     *
     * @var list<string>
     */
    public const INVENTORY_TABLES = [
        'listing_split_activities',
        'listing_splits',
        'xs2_category_mapping_details',
        'xs2_ticket_mapping_states',
        'external_listing_mappings',
        'xs2_event_inventory_sync_states',
        'xs2_tickets',
        'xs2_category_contexts',
        'xs2_category_mappings',
        'xs2_categories',
    ];

    /**
     * Tables that must remain untouched by an inventory-only reset.
     *
     * @var list<string>
     */
    public const INVENTORY_PRESERVED_TABLES = [
        'xs2_events',
        'event_mappings',
        'xs2_venues',
        'xs2_stadium_mappings',
        'sb_orders',
        'sb_order_attendees',
        'xs2_orders',
        'xs2_order_attendees',
        'xs2_order_guest_data_logs',
        'users',
    ];

    /**
     * XS2 integration tables cleared in FK-safe order (children first).
     * Legacy catalogue tables (match_info, stadium, etc.) are intentionally excluded.
     *
     * @var list<string>
     */
    public const TABLES = [
        'listing_split_activities',
        'listing_splits',
        'xs2_category_mapping_details',
        'xs2_ticket_mapping_states',
        'external_listing_mappings',
        'xs2_sandbox_test_orders',
        'xs2_order_guest_data_logs',
        'xs2_order_attendees',
        'xs2_orders',
        'sb_order_attendees',
        'sb_orders',
        'xs2_event_inventory_sync_states',
        'xs2_tickets',
        'xs2_category_contexts',
        'xs2_category_mappings',
        'xs2_categories',
        'event_mappings',
        'xs2_stadium_mappings',
        'xs2_events',
        'xs2_venues',
        'xs2_sync_states',
    ];

    /**
     * XS2 catalogue tables (inventory + events + venues + mappings) cleared in FK-safe order.
     * Preserves all order tables, users, and integration settings.
     *
     * @var list<string>
     */
    public const CATALOG_TABLES = [
        'listing_split_activities',
        'listing_splits',
        'xs2_category_mapping_details',
        'xs2_ticket_mapping_states',
        'external_listing_mappings',
        'xs2_event_inventory_sync_states',
        'xs2_tickets',
        'xs2_category_contexts',
        'xs2_category_mappings',
        'xs2_categories',
        'event_mappings',
        'xs2_stadium_mappings',
        'xs2_events',
        'xs2_venues',
        'xs2_sync_states',
    ];

    /**
     * Tables that must remain untouched by a catalogue reset.
     *
     * @var list<string>
     */
    public const CATALOG_PRESERVED_TABLES = [
        'xs2_sandbox_test_orders',
        'xs2_order_guest_data_logs',
        'xs2_order_attendees',
        'xs2_orders',
        'sb_order_attendees',
        'sb_orders',
        'users',
        'integration_settings',
        'match_info',
        'jobs',
        'failed_jobs',
        'stadium',
        'stadium_details',
        'stadium_seats',
        'countries',
        'cities',
        'teams',
        'tournament',
        'game_category',
    ];

    /**
     * Tables that must remain untouched by a full XS2 reset.
     *
     * @var list<string>
     */
    public const PRESERVED_TABLES = [
        'users',
        'match_info',
        'integration_settings',
        'jobs',
        'failed_jobs',
        'stadium',
        'stadium_details',
        'stadium_seats',
        'countries',
        'cities',
        'teams',
        'tournament',
        'game_category',
    ];

    /**
     * @return array{
     *     before: array<string, int>,
     *     after: array<string, int>,
     *     preserved: array<string, int>
     * }
     */
    public function reset(bool $dryRun = false): array
    {
        return $this->resetTables(self::TABLES, self::PRESERVED_TABLES, $dryRun, 'XS2 reset');
    }

    /**
     * @return array{
     *     before: array<string, int>,
     *     after: array<string, int>,
     *     preserved: array<string, int>,
     *     cache_locks_cleared?: int,
     *     cache_entries_cleared?: int
     * }
     */
    public function resetInventory(bool $dryRun = false, bool $clearCacheLocks = false): array
    {
        $summary = $this->resetTables(
            self::INVENTORY_TABLES,
            self::INVENTORY_PRESERVED_TABLES,
            $dryRun,
            'XS2 inventory reset',
        );

        if ($clearCacheLocks) {
            $cacheSummary = $this->clearInventoryCacheLocks($dryRun);
            $summary = [
                ...$summary,
                ...$cacheSummary,
            ];
        }

        return $summary;
    }

    /**
     * @return array{
     *     before: array<string, int>,
     *     after: array<string, int>,
     *     preserved: array<string, int>,
     *     cache_locks_cleared?: int,
     *     cache_entries_cleared?: int
     * }
     */
    public function resetCatalog(bool $dryRun = false, bool $clearCacheLocks = false): array
    {
        $summary = $this->resetTables(
            self::CATALOG_TABLES,
            self::CATALOG_PRESERVED_TABLES,
            $dryRun,
            'XS2 catalogue reset',
        );

        if ($clearCacheLocks) {
            $cacheSummary = $this->clearInventoryCacheLocks($dryRun);
            $summary = [
                ...$summary,
                ...$cacheSummary,
            ];
        }

        return $summary;
    }

    /**
     * @param  list<string>  $tables
     * @param  list<string>  $preservedTables
     * @return array{
     *     before: array<string, int>,
     *     after: array<string, int>,
     *     preserved: array<string, int>
     * }
     */
    private function resetTables(array $tables, array $preservedTables, bool $dryRun, string $label): array
    {
        $before = $this->countTables($tables);
        $preservedBefore = $this->countTables($preservedTables);

        if ($dryRun) {
            return [
                'before' => $before,
                'after' => array_map(static fn (): int => 0, $before),
                'preserved' => $preservedBefore,
            ];
        }

        $this->wipeTables($tables);

        $after = $this->countTables($tables);
        $preservedAfter = $this->countTables($preservedTables);

        foreach ($preservedBefore as $table => $count) {
            if (($preservedAfter[$table] ?? null) !== $count) {
                throw new \RuntimeException("{$label} modified preserved table [{$table}].");
            }
        }

        return [
            'before' => $before,
            'after' => $after,
            'preserved' => $preservedAfter,
        ];
    }

    /**
     * @return array{cache_locks_cleared: int, cache_entries_cleared: int}
     */
    public function clearInventoryCacheLocks(bool $dryRun = false): array
    {
        $patterns = ['%xs2-event-inventory%', '%SyncXs2EventInventory%'];
        $cacheLocksCleared = 0;
        $cacheEntriesCleared = 0;

        if (Schema::hasTable('cache_locks')) {
            $query = DB::table('cache_locks');
            $query->where(function ($builder) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $builder->orWhere('key', 'like', $pattern);
                }
            });
            $cacheLocksCleared = (int) $query->count();

            if (! $dryRun && $cacheLocksCleared > 0) {
                $deleteQuery = DB::table('cache_locks');
                $deleteQuery->where(function ($builder) use ($patterns): void {
                    foreach ($patterns as $pattern) {
                        $builder->orWhere('key', 'like', $pattern);
                    }
                });
                $deleteQuery->delete();
            }
        }

        if (Schema::hasTable('cache')) {
            $query = DB::table('cache');
            $query->where(function ($builder) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $builder->orWhere('key', 'like', $pattern);
                }
            });
            $cacheEntriesCleared = (int) $query->count();

            if (! $dryRun && $cacheEntriesCleared > 0) {
                $deleteQuery = DB::table('cache');
                $deleteQuery->where(function ($builder) use ($patterns): void {
                    foreach ($patterns as $pattern) {
                        $builder->orWhere('key', 'like', $pattern);
                    }
                });
                $deleteQuery->delete();
            }
        }

        return [
            'cache_locks_cleared' => $cacheLocksCleared,
            'cache_entries_cleared' => $cacheEntriesCleared,
        ];
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function countTables(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = Schema::hasTable($table)
                ? (int) DB::table($table)->count()
                : 0;
        }

        return $counts;
    }

    /**
     * @param  list<string>  $tables
     */
    private function wipeTables(array $tables): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            try {
                foreach ($tables as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->truncate();
                    }
                }
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');

            try {
                foreach ($tables as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }
            } finally {
                DB::statement('PRAGMA foreign_keys = ON');
            }

            return;
        }

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }
}
