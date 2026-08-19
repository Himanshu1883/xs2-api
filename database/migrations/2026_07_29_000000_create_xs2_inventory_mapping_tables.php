<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * These names were chosen after checking the repository for collisions.
     * They are companion integration tables: no legacy master-data or existing
     * XS2 table is altered by this migration.
     */
    public function up(): void
    {
        Schema::create('xs2_venues', function (Blueprint $table): void {
            $table->id();
            $table->string('external_venue_id', 100)->unique();
            $table->string('venue_name')->nullable();
            $table->string('slug')->nullable();
            $table->string('address')->nullable();
            $table->string('postal_code', 50)->nullable();
            $table->string('city_name')->nullable()->index();
            $table->string('country_name')->nullable();
            $table->string('country_code', 10)->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('raw_payload');
            $table->dateTime('external_created_at')->nullable();
            $table->dateTime('external_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('xs2_stadium_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('xs2_venue_id')->constrained('xs2_venues')->cascadeOnDelete();
            // Legacy master-data IDs deliberately have no foreign keys here.
            $table->unsignedBigInteger('stadium_id')->nullable();
            $table->unsignedBigInteger('resolved_country_id')->nullable();
            $table->unsignedBigInteger('resolved_city_id')->nullable();
            $table->string('status', 40)->default('pending_country_resolution');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('mapping_method', 50)->nullable();
            $table->json('matched_fields')->nullable();
            $table->json('candidate_scores')->nullable();
            $table->boolean('manually_confirmed')->default(false);
            $table->timestamp('mapped_at')->nullable();
            $table->text('mapping_error')->nullable();
            $table->timestamps();
            $table->unique('xs2_venue_id');
            $table->index(['status', 'resolved_city_id']);
        });

        // xs2_categories already exists. This table stores its new integration
        // fields rather than extending that existing production table.
        Schema::create('xs2_category_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('xs2_category_id')->constrained('xs2_categories')->cascadeOnDelete();
            $table->string('external_venue_id', 100)->nullable()->index();
            $table->string('category_type', 80)->nullable();
            $table->string('slug')->nullable();
            $table->json('options')->nullable();
            $table->boolean('on_svg')->nullable();
            $table->dateTime('external_created_at')->nullable();
            $table->dateTime('external_updated_at')->nullable();
            $table->timestamps();
            $table->unique('xs2_category_id');
        });

        Schema::create('xs2_category_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('xs2_category_id')->constrained('xs2_categories')->cascadeOnDelete();
            $table->foreignId('xs2_stadium_mapping_id')->nullable()->constrained('xs2_stadium_mappings')->nullOnDelete();
            // These IDs point to read-only legacy tables and intentionally have no FK.
            $table->unsignedBigInteger('stadium_id')->nullable();
            $table->unsignedBigInteger('stadium_detail_id')->nullable();
            $table->unsignedBigInteger('stadium_seat_id')->nullable();
            $table->string('status', 40)->default('pending_stadium_mapping');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('mapping_method', 50)->nullable();
            $table->json('matched_fields')->nullable();
            $table->json('candidate_scores')->nullable();
            $table->boolean('manually_confirmed')->default(false);
            $table->timestamp('mapped_at')->nullable();
            $table->text('mapping_error')->nullable();
            $table->timestamps();
            $table->unique('xs2_category_id');
            $table->index(['status', 'stadium_id']);
        });

        // xs2_tickets cannot be extended. This companion stores publishing
        // readiness and links all mapping records for each ticket.
        Schema::create('xs2_ticket_mapping_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('xs2_ticket_id')->constrained('xs2_tickets')->cascadeOnDelete();
            $table->foreignId('event_mapping_id')->nullable()->constrained('event_mappings')->nullOnDelete();
            $table->foreignId('xs2_venue_id')->nullable()->constrained('xs2_venues')->nullOnDelete();
            $table->foreignId('xs2_category_id')->nullable()->constrained('xs2_categories')->nullOnDelete();
            $table->foreignId('xs2_stadium_mapping_id')->nullable()->constrained('xs2_stadium_mappings')->nullOnDelete();
            $table->foreignId('xs2_category_mapping_id')->nullable()->constrained('xs2_category_mappings')->nullOnDelete();
            $table->string('mapping_status', 40)->default('pending_event_mapping');
            $table->text('mapping_error')->nullable();
            $table->timestamp('last_resolved_at')->nullable();
            $table->timestamps();
            $table->unique('xs2_ticket_id');
            $table->index('mapping_status');
        });

        // This replaces new sync fields on xs2_events with an isolated state
        // record, keeping the pre-existing event table immutable.
        Schema::create('xs2_event_inventory_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('xs2_event_id')->constrained('xs2_events')->cascadeOnDelete();
            $table->timestamp('tickets_last_incremental_sync_at')->nullable();
            $table->timestamp('tickets_last_full_sync_at')->nullable();
            $table->timestamp('tickets_next_sync_at')->nullable()->index();
            $table->string('tickets_sync_status', 40)->nullable()->index();
            $table->text('tickets_sync_error')->nullable();
            $table->timestamps();
            $table->unique('xs2_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xs2_event_inventory_sync_states');
        Schema::dropIfExists('xs2_ticket_mapping_states');
        Schema::dropIfExists('xs2_category_mappings');
        Schema::dropIfExists('xs2_category_contexts');
        Schema::dropIfExists('xs2_stadium_mappings');
        Schema::dropIfExists('xs2_venues');
    }
};
