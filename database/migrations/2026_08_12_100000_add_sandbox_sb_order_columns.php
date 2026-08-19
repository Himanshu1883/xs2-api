<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('xs2_tickets') && ! Schema::hasColumn('xs2_tickets', 'is_sandbox')) {
            Schema::table('xs2_tickets', function (Blueprint $table): void {
                $table->boolean('is_sandbox')->default(false)->after('sync_error')->index();
            });
        }

        if (! Schema::hasTable('xs2_orders')) {
            return;
        }

        Schema::table('xs2_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('xs2_orders', 'is_sandbox')) {
                $table->boolean('is_sandbox')->default(false)->after('external_order_id')->index();
            }
            if (! Schema::hasColumn('xs2_orders', 'sb_order_id')) {
                $table->foreignId('sb_order_id')->nullable()->after('is_sandbox')->constrained('sb_orders')->nullOnDelete();
                $table->unique('sb_order_id');
            }
            if (! Schema::hasColumn('xs2_orders', 'xs2_reservation_id')) {
                $table->string('xs2_reservation_id', 128)->nullable()->after('external_ticket_id')->index();
            }
            if (! Schema::hasColumn('xs2_orders', 'xs2_booking_id')) {
                $table->string('xs2_booking_id', 128)->nullable()->after('xs2_reservation_id')->index();
            }
            if (! Schema::hasColumn('xs2_orders', 'xs2_bookingorder_id')) {
                $table->string('xs2_bookingorder_id', 128)->nullable()->after('xs2_booking_id')->index();
            }
            if (! Schema::hasColumn('xs2_orders', 'sandbox_sync_error')) {
                $table->text('sandbox_sync_error')->nullable()->after('raw_payload');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('xs2_orders')) {
            Schema::table('xs2_orders', function (Blueprint $table): void {
                if (Schema::hasColumn('xs2_orders', 'sb_order_id')) {
                    $table->dropConstrainedForeignId('sb_order_id');
                }
                foreach (['is_sandbox', 'xs2_reservation_id', 'xs2_booking_id', 'xs2_bookingorder_id', 'sandbox_sync_error'] as $column) {
                    if (Schema::hasColumn('xs2_orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('xs2_tickets') && Schema::hasColumn('xs2_tickets', 'is_sandbox')) {
            Schema::table('xs2_tickets', function (Blueprint $table): void {
                $table->dropColumn('is_sandbox');
            });
        }
    }
};
