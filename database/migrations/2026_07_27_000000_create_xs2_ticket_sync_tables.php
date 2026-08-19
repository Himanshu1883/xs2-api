<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xs2_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('xs2_event_id')->constrained()->cascadeOnDelete();
            $table->string('external_ticket_id', 100)->unique();
            $table->string('external_event_id', 100)->index();
            $table->string('category_id', 100)->nullable();
            $table->string('category_name')->nullable();
            $table->string('sub_category')->nullable();
            $table->string('ticket_title')->nullable();
            $table->string('ticket_type', 50)->nullable();
            $table->string('ticket_status', 50)->nullable()->index();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('min_order')->default(1);
            $table->unsignedBigInteger('net_rate')->nullable();
            $table->unsignedBigInteger('face_value')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->dateTime('ticket_valid_from')->nullable();
            $table->dateTime('ticket_valid_until')->nullable();
            $table->json('flags')->nullable();
            $table->json('options')->nullable();
            $table->json('sales_periods')->nullable();
            $table->json('raw_payload');
            $table->dateTime('external_created_at')->nullable();
            $table->dateTime('external_updated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->string('sync_status', 30)->default('pending')->index();
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('xs2_event_id')->constrained()->cascadeOnDelete();
            $table->string('external_category_id', 100);
            $table->string('external_event_id', 100)->index();
            $table->string('category_name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('party_size_together')->default(false);
            $table->json('raw_payload');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['external_category_id', 'external_event_id']);
        });
        Schema::create('external_listing_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30);
            $table->foreignId('xs2_ticket_id')->constrained()->cascadeOnDelete();
            $table->integer('local_event_id')->nullable();
            $table->foreignId('event_mapping_id')->nullable()->constrained('event_mappings')->nullOnDelete();
            $table->string('seller_listing_id')->nullable();
            $table->string('seller_reference')->unique();
            $table->string('status', 30)->default('pending');
            $table->string('last_payload_hash', 64)->nullable();
            $table->unsignedInteger('last_pushed_quantity')->nullable();
            $table->unsignedBigInteger('last_pushed_price')->nullable();
            $table->json('last_request')->nullable();
            $table->json('last_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'xs2_ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_listing_mappings');
        Schema::dropIfExists('xs2_categories');
        Schema::dropIfExists('xs2_tickets');
    }
};
