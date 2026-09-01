<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cron_execution_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('cron_job_id', 128)->index();
            $table->string('trigger', 32)->default('scheduled');
            $table->string('status', 32)->index();
            $table->timestamp('started_at')->index();
            // Serves the admin log view without MySQL having to filesort all of
            // a job's historical logs before applying its small LIMIT.
            $table->index(['cron_job_id', 'started_at'], 'cron_execution_logs_job_started_at_idx');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('message')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_execution_logs');
    }
};
