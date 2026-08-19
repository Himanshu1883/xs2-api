<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sb_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('booking_no', 64)->unique();
            $table->unsignedSmallInteger('booking_status')->nullable()->index();
            $table->string('booking_status_text', 64)->nullable();
            $table->decimal('ticket_amount', 12, 2)->nullable();
            $table->string('currency_type', 16)->nullable();
            $table->string('match_name')->nullable();
            $table->string('tournament_name')->nullable();
            $table->string('stadium_name')->nullable();
            $table->date('match_date')->nullable()->index();
            $table->string('match_time', 32)->nullable();
            $table->unsignedBigInteger('match_id')->nullable()->index();
            $table->unsignedBigInteger('ticket_id')->nullable()->index();
            $table->string('listing_id', 64)->nullable()->index();
            $table->string('ticketid', 64)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedInteger('split')->nullable();
            $table->string('seat_category')->nullable();
            $table->string('ticket_block')->nullable();
            $table->string('row')->nullable();
            $table->string('section')->nullable();
            $table->text('listing_note')->nullable();
            $table->string('ticket_types_name')->nullable();
            $table->string('buyer_first_name')->nullable();
            $table->string('buyer_last_name')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();

            $table->index(['buyer_first_name', 'buyer_last_name']);
        });

        Schema::create('sb_order_attendees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sb_order_id')->constrained('sb_orders')->cascadeOnDelete();
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

            $table->unique(['sb_order_id', 'position']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sb_order_attendees');
        Schema::dropIfExists('sb_orders');
    }
};
