<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xs2_sandbox_test_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('xs2_sandbox_test_orders', 'xs2_eticket_request')) {
                $table->json('xs2_eticket_request')->nullable()->after('xs2_guest_data_response');
            }
            if (! Schema::hasColumn('xs2_sandbox_test_orders', 'xs2_eticket_response')) {
                $table->json('xs2_eticket_response')->nullable()->after('xs2_eticket_request');
            }
        });
    }

    public function down(): void
    {
        Schema::table('xs2_sandbox_test_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('xs2_sandbox_test_orders', 'xs2_eticket_response')) {
                $table->dropColumn('xs2_eticket_response');
            }
            if (Schema::hasColumn('xs2_sandbox_test_orders', 'xs2_eticket_request')) {
                $table->dropColumn('xs2_eticket_request');
            }
        });
    }
};
