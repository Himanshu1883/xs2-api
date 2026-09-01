<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'cron_execution_logs_job_started_at_idx';

    public function up(): void
    {
        if (! Schema::hasTable('cron_execution_logs') || Schema::hasIndex('cron_execution_logs', self::INDEX)) {
            return;
        }

        Schema::table('cron_execution_logs', function (Blueprint $table): void {
            // Matches: WHERE cron_job_id = ? ORDER BY started_at DESC LIMIT ?.
            // MySQL can scan this B-tree backwards for DESC ordering.
            $table->index(['cron_job_id', 'started_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cron_execution_logs') || ! Schema::hasIndex('cron_execution_logs', self::INDEX)) {
            return;
        }

        Schema::table('cron_execution_logs', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
