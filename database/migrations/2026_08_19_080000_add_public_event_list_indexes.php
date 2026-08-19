<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_mappings') && ! Schema::hasIndex('event_mappings', 'event_mappings_m_id_index')) {
            Schema::table('event_mappings', function (Blueprint $table): void {
                $table->index('m_id');
            });
        }

        if (Schema::hasTable('match_info') && ! Schema::hasIndex('match_info', 'match_info_match_date_index')) {
            Schema::table('match_info', function (Blueprint $table): void {
                $table->index('match_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('event_mappings') && Schema::hasIndex('event_mappings', 'event_mappings_m_id_index')) {
            Schema::table('event_mappings', function (Blueprint $table): void {
                $table->dropIndex('event_mappings_m_id_index');
            });
        }

        if (Schema::hasTable('match_info') && Schema::hasIndex('match_info', 'match_info_match_date_index')) {
            Schema::table('match_info', function (Blueprint $table): void {
                $table->dropIndex('match_info_match_date_index');
            });
        }
    }
};
