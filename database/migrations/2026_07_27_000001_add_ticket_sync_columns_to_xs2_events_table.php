<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xs2_events', function (Blueprint $table): void {
            $table->timestamp('tickets_last_incremental_sync_at')->nullable()->after('missing_since');
            $table->timestamp('tickets_last_full_sync_at')->nullable()->after('tickets_last_incremental_sync_at');
            $table->timestamp('tickets_next_sync_at')->nullable()->index()->after('tickets_last_full_sync_at');
            $table->string('tickets_sync_status', 30)->nullable()->index()->after('tickets_next_sync_at');
            $table->text('tickets_sync_error')->nullable()->after('tickets_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('xs2_events', function (Blueprint $table): void {
            $table->dropIndex(['tickets_next_sync_at']);
            $table->dropIndex(['tickets_sync_status']);
            $table->dropColumn([
                'tickets_last_incremental_sync_at',
                'tickets_last_full_sync_at',
                'tickets_next_sync_at',
                'tickets_sync_status',
                'tickets_sync_error',
            ]);
        });
    }
};
