<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pipeline_runs')) {
            Schema::create('pipeline_runs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('correlation_id')->unique();
                $table->string('trigger', 40);
                $table->string('mode', 20);
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->unsignedInteger('events_due')->default(0);
                $table->unsignedInteger('events_dispatched')->default(0);
                $table->unsignedInteger('events_completed')->default(0);
                $table->unsignedInteger('events_failed')->default(0);
                $table->string('status', 40)->default('running')->index();
                $table->timestamps();

                $table->index(['status', 'started_at']);
            });
        }

        if (! Schema::hasTable('pipeline_job_steps')) {
            Schema::create('pipeline_job_steps', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('pipeline_run_id')->constrained('pipeline_runs')->cascadeOnDelete();
                $table->foreignId('xs2_event_id')->constrained('xs2_events')->cascadeOnDelete();
                $table->string('stage', 40);
                $table->string('status', 40)->default('queued');
                $table->string('job_class')->nullable();
                $table->unsignedBigInteger('laravel_job_id')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamps();

                $table->unique(['pipeline_run_id', 'xs2_event_id', 'stage']);
                $table->index(['stage', 'status']);
                $table->index(['xs2_event_id', 'created_at']);
            });
        }

        Schema::table('xs2_event_inventory_sync_states', function (Blueprint $table): void {
            if (! Schema::hasColumn('xs2_event_inventory_sync_states', 'pipeline_run_id')) {
                $table->foreignId('pipeline_run_id')->nullable()->after('xs2_event_id')->constrained('pipeline_runs')->nullOnDelete();
            }

            if (! Schema::hasColumn('xs2_event_inventory_sync_states', 'pipeline_correlation_id')) {
                $table->uuid('pipeline_correlation_id')->nullable()->after('pipeline_run_id')->index('xs2_inv_sync_pipeline_corr_idx');
            }

            if (! Schema::hasColumn('xs2_event_inventory_sync_states', 'listing_gen_status')) {
                $table->string('listing_gen_status', 40)->nullable()->after('tickets_sync_error');
            }

            if (! Schema::hasColumn('xs2_event_inventory_sync_states', 'publish_status')) {
                $table->string('publish_status', 40)->nullable()->after('listing_gen_status');
            }

            if (! Schema::hasColumn('xs2_event_inventory_sync_states', 'reconcile_status')) {
                $table->string('reconcile_status', 40)->nullable()->after('publish_status');
            }

            if (! Schema::hasColumn('xs2_event_inventory_sync_states', 'last_pipeline_stage_at')) {
                $table->timestamp('last_pipeline_stage_at')->nullable()->after('reconcile_status');
            }
        });

        if (! $this->indexExists('xs2_event_inventory_sync_states', 'xs2_inv_sync_status_updated_idx')) {
            Schema::table('xs2_event_inventory_sync_states', function (Blueprint $table): void {
                $table->index(['tickets_sync_status', 'updated_at'], 'xs2_inv_sync_status_updated_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('xs2_event_inventory_sync_states', 'xs2_inv_sync_status_updated_idx')) {
            Schema::table('xs2_event_inventory_sync_states', function (Blueprint $table): void {
                $table->dropIndex('xs2_inv_sync_status_updated_idx');
            });
        }

        Schema::table('xs2_event_inventory_sync_states', function (Blueprint $table): void {
            if (Schema::hasColumn('xs2_event_inventory_sync_states', 'pipeline_run_id')) {
                $table->dropConstrainedForeignId('pipeline_run_id');
            }

            foreach ([
                'pipeline_correlation_id',
                'listing_gen_status',
                'publish_status',
                'reconcile_status',
                'last_pipeline_stage_at',
            ] as $column) {
                if (Schema::hasColumn('xs2_event_inventory_sync_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('pipeline_job_steps');
        Schema::dropIfExists('pipeline_runs');
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() === 'sqlite') {
            $rows = $connection->select("PRAGMA index_list('{$table}')");

            foreach ($rows as $row) {
                $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);
                if ($name === $index) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();
        $result = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $index],
        );

        return $result !== [];
    }
};
