<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('listing_split_activities') || ! Schema::hasColumn('listing_split_activities', 'message')) {
            return;
        }

        Schema::table('listing_split_activities', function ($table): void {
            $table->text('message')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('listing_split_activities') || ! Schema::hasColumn('listing_split_activities', 'message')) {
            return;
        }

        Schema::table('listing_split_activities', function ($table): void {
            $table->string('message', 255)->nullable()->change();
        });
    }
};
