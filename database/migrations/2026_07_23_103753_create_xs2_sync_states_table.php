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
        Schema::create('xs2_sync_states', function (Blueprint $table) {
            $table->id();

            /*
            * Examples:
            * events:soccer
            * events:formula1
            * venues
            * categories
            */
            $table->string('resource')->unique();

            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_successful_at')->nullable();

            $table->string('status', 30)->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('xs2_sync_states');
    }
};
