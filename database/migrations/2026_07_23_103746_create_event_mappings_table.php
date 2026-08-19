<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('event_mappings')) {
            return;
        }

        Schema::create('event_mappings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('xs2_event_id')
                ->constrained('xs2_events')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('m_id')->nullable();

            /*
            * mapped:
            * Successfully mapped.
            *
            * pending:
            * Possible matches or unresolved local references require admin review.
            *
            * created:
            * A new local event was created.
            *
            * ignored:
            * Admin does not want to import this event.
            */
            $table->string('status', 30)->default('pending');

            /*
            * automatic, manual, created, exact
            */
            $table->string('mapping_method', 30)->nullable();

            $table->decimal('match_score', 5, 2)->nullable();
            $table->json('match_details')->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            /*
            * One XS2 event should only have one canonical mapping.
            */
            $table->unique('xs2_event_id');

            $table->index(['status', 'match_score']);
            $table->index('m_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_mappings');
    }
};
