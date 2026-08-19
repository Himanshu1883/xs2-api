<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xs2_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('external_order_id', 128)->unique();
            $table->string('order_status', 64)->nullable()->index();
            $table->string('order_status_text', 128)->nullable();
            $table->decimal('ticket_amount', 12, 2)->nullable();
            $table->string('currency_type', 16)->nullable();
            $table->string('event_name')->nullable();
            $table->string('venue_name')->nullable();
            $table->date('event_date')->nullable()->index();
            $table->string('event_time', 32)->nullable();
            $table->string('external_event_id', 128)->nullable()->index();
            $table->string('external_ticket_id', 128)->nullable()->index();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('seat_category')->nullable();
            $table->string('ticket_block')->nullable();
            $table->string('row')->nullable();
            $table->string('section')->nullable();
            $table->string('buyer_first_name')->nullable();
            $table->string('buyer_last_name')->nullable();
            $table->string('buyer_email')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();

            $table->index(['buyer_first_name', 'buyer_last_name']);
        });

        Schema::create('xs2_order_attendees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('xs2_order_id')->constrained('xs2_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('dob', 32)->nullable();
            $table->string('nationality')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('passport')->nullable();
            $table->string('gender', 32)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['xs2_order_id', 'position']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xs2_order_attendees');
        Schema::dropIfExists('xs2_orders');
    }
};
