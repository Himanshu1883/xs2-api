<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('xs2_orders')) {
            return;
        }

        Schema::table('xs2_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('xs2_orders', 'guest_data_synced_at')) {
                $table->timestamp('guest_data_synced_at')->nullable()->after('synced_at');
            }
            if (! Schema::hasColumn('xs2_orders', 'guest_data_sync_error')) {
                $table->text('guest_data_sync_error')->nullable()->after('guest_data_synced_at');
            }
            if (! Schema::hasColumn('xs2_orders', 'guest_data_source_fingerprint')) {
                $table->string('guest_data_source_fingerprint', 64)->nullable()->after('guest_data_sync_error');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('xs2_orders')) {
            return;
        }

        Schema::table('xs2_orders', function (Blueprint $table): void {
            foreach (['guest_data_source_fingerprint', 'guest_data_sync_error', 'guest_data_synced_at'] as $column) {
                if (Schema::hasColumn('xs2_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
