<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xs2_sandbox_test_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('seatsbroker_order_id', 64)->unique();
            $table->string('environment', 32)->default('sandbox');
            $table->boolean('is_sandbox')->default(true);
            $table->string('status', 32)->default('draft')->index();

            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->unsignedInteger('quantity')->default(1);

            $table->string('xs2_event_id')->nullable()->index();
            $table->json('xs2_event_payload')->nullable();
            $table->string('xs2_ticket_id')->nullable()->index();
            $table->json('xs2_ticket_payload')->nullable();

            $table->string('xs2_reservation_id')->nullable()->index();
            $table->string('xs2_booking_id')->nullable()->index();
            $table->string('xs2_booking_code', 64)->nullable();

            $table->json('xs2_reservation_request')->nullable();
            $table->json('xs2_reservation_response')->nullable();
            $table->json('xs2_booking_request')->nullable();
            $table->json('xs2_booking_response')->nullable();

            $table->text('last_error')->nullable();
            $table->timestamp('sb_order_created_at')->nullable();
            $table->timestamp('xs2_order_created_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xs2_sandbox_test_orders');
    }
};
