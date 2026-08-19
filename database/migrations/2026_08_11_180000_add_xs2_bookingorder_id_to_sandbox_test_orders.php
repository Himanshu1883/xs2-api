<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xs2_sandbox_test_orders', function (Blueprint $table): void {
            $table->string('xs2_bookingorder_id')->nullable()->after('xs2_booking_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('xs2_sandbox_test_orders', function (Blueprint $table): void {
            $table->dropColumn('xs2_bookingorder_id');
        });
    }
};
