<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('listing_split_activities')) {
            return;
        }

        DB::statement('ALTER TABLE listing_split_activities MODIFY message TEXT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('listing_split_activities')) {
            return;
        }

        DB::statement('ALTER TABLE listing_split_activities MODIFY message VARCHAR(255) NULL');
    }
};
