<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xs2_sandbox_test_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('xs2_sandbox_test_orders', 'xs2_guest_data_request')) {
                $table->json('xs2_guest_data_request')->nullable()->after('xs2_booking_response');
            }
            if (! Schema::hasColumn('xs2_sandbox_test_orders', 'xs2_guest_data_response')) {
                $table->json('xs2_guest_data_response')->nullable()->after('xs2_guest_data_request');
            }
        });
    }

    public function down(): void
    {
        Schema::table('xs2_sandbox_test_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('xs2_sandbox_test_orders', 'xs2_guest_data_response')) {
                $table->dropColumn('xs2_guest_data_response');
            }
            if (Schema::hasColumn('xs2_sandbox_test_orders', 'xs2_guest_data_request')) {
                $table->dropColumn('xs2_guest_data_request');
            }
        });
    }
};
