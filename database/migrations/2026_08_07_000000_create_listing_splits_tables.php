<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xs2_tickets', function (Blueprint $table): void {
            $table->boolean('split_enabled')->default(false)->after('sync_error');
            $table->unsignedInteger('split_quantity')->nullable()->after('split_enabled');
            $table->string('price_increment_type', 20)->nullable()->after('split_quantity');
            $table->decimal('price_increment_value', 12, 2)->nullable()->after('price_increment_type');
            $table->string('split_sync_status', 30)->default('idle')->after('price_increment_value');
            $table->text('split_sync_error')->nullable()->after('split_sync_status');
        });

        Schema::create('listing_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('master_listing_id')->constrained('xs2_tickets')->cascadeOnDelete();
            $table->string('seatsbroker_listing_id')->nullable();
            $table->string('seller_reference')->unique();
            $table->unsignedInteger('quantity');
            $table->decimal('price', 12, 2);
            $table->unsignedSmallInteger('split_order');
            $table->string('status', 20)->default('active')->index();
            $table->string('sync_status', 30)->default('pending')->index();
            $table->string('last_payload_hash', 64)->nullable();
            $table->json('last_request')->nullable();
            $table->json('last_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('master_listing_id');
            $table->index('seatsbroker_listing_id');
            $table->unique(['master_listing_id', 'split_order']);
        });

        Schema::create('listing_split_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('master_listing_id')->constrained('xs2_tickets')->cascadeOnDelete();
            $table->foreignId('listing_split_id')->nullable()->constrained('listing_splits')->nullOnDelete();
            $table->string('action', 50)->index();
            $table->string('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['master_listing_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_split_activities');
        Schema::dropIfExists('listing_splits');

        Schema::table('xs2_tickets', function (Blueprint $table): void {
            $table->dropColumn([
                'split_enabled',
                'split_quantity',
                'price_increment_type',
                'price_increment_value',
                'split_sync_status',
                'split_sync_error',
            ]);
        });
    }
};
