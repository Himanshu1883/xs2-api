<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'event_mappings_one_public_mapping_per_local_event';

    public function up(): void
    {
        if (! Schema::hasTable('event_mappings')) {
            return;
        }

        // Do not silently pick a winner from existing ambiguous public data.
        // An operator must review any historic duplicate before the database
        // begins enforcing the canonical-event invariant.
        $duplicates = DB::table('event_mappings')
            ->whereNotNull('m_id')
            ->whereIn('status', ['mapped', 'created'])
            ->select('m_id')
            ->groupBy('m_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('m_id')
            ->pluck('m_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot enforce one public XS2 mapping per local event until duplicate active mappings are resolved. Conflicting m_id values: '
                .$duplicates->take(20)->implode(', ')
                .($duplicates->count() > 20 ? ', ...' : '').'.'
            );
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            if (! Schema::hasColumn('event_mappings', 'public_m_id')) {
                Schema::table('event_mappings', function (Blueprint $table): void {
                    // MySQL does not support filtered indexes. A NULL generated
                    // value preserves pending/ignored rows while a unique index
                    // protects mapped and created rows.
                    $table->unsignedBigInteger('public_m_id')
                        ->nullable()
                        ->storedAs("case when `status` in ('mapped', 'created') then `m_id` else null end")
                        ->after('m_id');
                });
            }

            if (! Schema::hasIndex('event_mappings', self::INDEX)) {
                Schema::table('event_mappings', function (Blueprint $table): void {
                    $table->unique('public_m_id', self::INDEX);
                });
            }

            return;
        }

        if ($driver === 'sqlite') {
            if (! Schema::hasIndex('event_mappings', self::INDEX)) {
                DB::statement(sprintf(
                    'CREATE UNIQUE INDEX "%s" ON "event_mappings" ("m_id") WHERE "m_id" IS NOT NULL AND "status" IN (\'mapped\', \'created\')',
                    self::INDEX,
                ));
            }

            return;
        }

        if ($driver === 'pgsql') {
            if (! Schema::hasIndex('event_mappings', self::INDEX)) {
                DB::statement(sprintf(
                    'CREATE UNIQUE INDEX "%s" ON "event_mappings" ("m_id") WHERE "m_id" IS NOT NULL AND "status" IN (\'mapped\', \'created\')',
                    self::INDEX,
                ));
            }

            return;
        }

        throw new RuntimeException("The {$driver} driver cannot safely enforce the public event-mapping uniqueness invariant.");
    }

    public function down(): void
    {
        if (! Schema::hasTable('event_mappings')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            if (Schema::hasIndex('event_mappings', self::INDEX)) {
                Schema::table('event_mappings', function (Blueprint $table): void {
                    $table->dropUnique(self::INDEX);
                });
            }

            if (Schema::hasColumn('event_mappings', 'public_m_id')) {
                Schema::table('event_mappings', function (Blueprint $table): void {
                    $table->dropColumn('public_m_id');
                });
            }

            return;
        }

        if (in_array($driver, ['sqlite', 'pgsql'], true) && Schema::hasIndex('event_mappings', self::INDEX)) {
            DB::statement(sprintf('DROP INDEX "%s"', self::INDEX));
        }
    }
};
