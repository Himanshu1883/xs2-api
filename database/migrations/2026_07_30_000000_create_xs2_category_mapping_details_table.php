<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A category mapping can legitimately span many physical stadium_details
     * rows (a tier such as "Distinti" covers several named blocks), which the
     * xs2_category_mappings.stadium_detail_id/stadium_seat_id scalar columns
     * cannot express. This adds a child table instead. Those scalar columns
     * are intentionally left in place, unused, rather than dropped.
     */
    public function up(): void
    {
        Schema::create('xs2_category_mapping_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('xs2_category_mapping_id')->constrained('xs2_category_mappings')->cascadeOnDelete();
            // Legacy master-data IDs deliberately have no foreign keys here,
            // matching the existing convention on xs2_category_mappings/xs2_stadium_mappings.
            $table->unsignedBigInteger('stadium_detail_id');
            $table->unsignedBigInteger('stadium_seat_id')->nullable();
            // Denormalized legacy display text, snapshotted at match time so
            // the admin UI can render a list without a legacy-schema join.
            $table->string('block')->nullable();
            $table->string('section')->nullable();
            $table->string('name')->nullable();
            $table->string('stadium_seat_name')->nullable();
            $table->timestamps();
            // Explicit short name: MySQL's auto-generated name for this column
            // pair exceeds the 64-character identifier limit.
            $table->unique(['xs2_category_mapping_id', 'stadium_detail_id'], 'xs2_cat_mapping_detail_unique');
            $table->index('stadium_detail_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xs2_category_mapping_details');
    }
};
