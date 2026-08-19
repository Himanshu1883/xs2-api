<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xs2_events', function (Blueprint $table) {
            $table->id();

            $table->string('external_event_id', 100)->unique();
            $table->string('event_name');

            /*
             * XS2 event times are local event times.
             * Keep them as local date/time values.
             */
            $table->dateTime('date_start_local')->nullable();
            $table->dateTime('date_stop_local')->nullable();

            $table->string('event_status', 50)->nullable();

            $table->string('tournament_id', 100)->nullable();
            $table->string('tournament_name')->nullable();
            $table->string('tournament_type', 50)->nullable();
            $table->string('season', 50)->nullable();

            $table->string('venue_id', 100)->nullable();
            $table->string('venue_name')->nullable();
            $table->string('location_id', 100)->nullable();

            $table->string('city')->nullable();
            $table->string('iso_country', 3)->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('sport_type', 50)->nullable();
            $table->boolean('date_confirmed')->default(false);

            $table->dateTime('date_start_main_event')->nullable();
            $table->dateTime('date_stop_main_event')->nullable();

            $table->string('hometeam_id', 100)->nullable();
            $table->string('hometeam_name')->nullable();

            $table->string('visitingteam_id', 100)->nullable();
            $table->string('visitingteam_name')->nullable();

            $table->text('event_description')->nullable();

            /* XS2 EventResponse exposes integer EUR price values. */
            $table->unsignedBigInteger(
                'min_ticket_price_eur'
            )->nullable();

            $table->unsignedBigInteger(
                'max_ticket_price_eur'
            )->nullable();

            $table->unsignedInteger(
                'number_of_tickets'
            )->nullable();

            $table->string('slug')->nullable();
            $table->boolean('is_popular')->default(false);
            $table->string('date_visibility', 50)->nullable();

            $table->dateTime('external_created_at')->nullable();
            $table->dateTime('external_updated_at')->nullable();

            /*
             * Keep the complete XS2 response for debugging
             * and fields that you do not query frequently.
             */
            $table->json('raw_payload');

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('missing_since')->nullable();

            $table->timestamps();

            $table->index(['sport_type', 'date_start_local']);
            $table->index(['event_status', 'date_start_local']);
            $table->index('tournament_id');
            $table->index('venue_id');
            $table->index('external_updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xs2_events');
    }
};
