<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xs2_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('xs2_events', 'visitingteam_iso_country')) {
                $table->string('visitingteam_iso_country', 3)->nullable()->after('visitingteam_name');
            }
            if (! Schema::hasColumn('xs2_events', 'visitingteam_province')) {
                $table->string('visitingteam_province')->nullable()->after('visitingteam_iso_country');
            }
        });

        Schema::table('xs2_tickets', function (Blueprint $table): void {
            if (! Schema::hasColumn('xs2_tickets', 'guest_data_requirements')) {
                $table->json('guest_data_requirements')->nullable()->after('sales_periods');
            }
            if (! Schema::hasColumn('xs2_tickets', 'guest_data_synced_at')) {
                $table->timestamp('guest_data_synced_at')->nullable()->after('guest_data_requirements');
            }
        });

        if (Schema::hasTable('sb_order_attendees') && ! Schema::hasColumn('sb_order_attendees', 'province')) {
            Schema::table('sb_order_attendees', function (Blueprint $table): void {
                $table->string('province')->nullable()->after('nationality');
            });
        }

        if (Schema::hasTable('xs2_order_attendees') && ! Schema::hasColumn('xs2_order_attendees', 'province')) {
            Schema::table('xs2_order_attendees', function (Blueprint $table): void {
                $table->string('province')->nullable()->after('nationality');
            });
        }
    }

    public function down(): void
    {
        Schema::table('xs2_events', function (Blueprint $table): void {
            if (Schema::hasColumn('xs2_events', 'visitingteam_province')) {
                $table->dropColumn('visitingteam_province');
            }
            if (Schema::hasColumn('xs2_events', 'visitingteam_iso_country')) {
                $table->dropColumn('visitingteam_iso_country');
            }
        });

        Schema::table('xs2_tickets', function (Blueprint $table): void {
            if (Schema::hasColumn('xs2_tickets', 'guest_data_synced_at')) {
                $table->dropColumn('guest_data_synced_at');
            }
            if (Schema::hasColumn('xs2_tickets', 'guest_data_requirements')) {
                $table->dropColumn('guest_data_requirements');
            }
        });

        if (Schema::hasTable('sb_order_attendees') && Schema::hasColumn('sb_order_attendees', 'province')) {
            Schema::table('sb_order_attendees', function (Blueprint $table): void {
                $table->dropColumn('province');
            });
        }

        if (Schema::hasTable('xs2_order_attendees') && Schema::hasColumn('xs2_order_attendees', 'province')) {
            Schema::table('xs2_order_attendees', function (Blueprint $table): void {
                $table->dropColumn('province');
            });
        }
    }
};
