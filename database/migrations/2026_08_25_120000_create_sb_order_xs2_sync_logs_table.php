<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sb_order_xs2_sync_logs')) {
            return;
        }

        Schema::create('sb_order_xs2_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sb_order_id')->constrained('sb_orders')->cascadeOnDelete();
            $table->foreignId('xs2_order_id')->nullable()->constrained('xs2_orders')->nullOnDelete();
            $table->string('status', 32)->index();
            $table->text('skip_reason')->nullable();
            $table->json('reservation_request')->nullable();
            $table->json('reservation_response')->nullable();
            $table->unsignedSmallInteger('reservation_response_status')->nullable();
            $table->json('reservation_response_headers')->nullable();
            $table->json('booking_request')->nullable();
            $table->json('booking_response')->nullable();
            $table->unsignedSmallInteger('booking_response_status')->nullable();
            $table->json('booking_response_headers')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique('sb_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sb_order_xs2_sync_logs');
    }
};
