<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sb_orders')) {
            Schema::table('sb_orders', function (Blueprint $table): void {
                if (! Schema::hasColumn('sb_orders', 'attendee_fetched_at')) {
                    $table->timestamp('attendee_fetched_at')->nullable()->index()->after('synced_at');
                }
                if (! Schema::hasColumn('sb_orders', 'attendee_fetch_error')) {
                    $table->text('attendee_fetch_error')->nullable()->after('attendee_fetched_at');
                }
            });

            if (Schema::hasTable('sb_order_attendees') && Schema::hasColumn('sb_orders', 'attendee_fetched_at')) {
                DB::table('sb_orders')
                    ->whereNull('attendee_fetched_at')
                    ->whereExists(function ($query): void {
                        $query->select(DB::raw(1))
                            ->from('sb_order_attendees')
                            ->whereColumn('sb_order_attendees.sb_order_id', 'sb_orders.id');
                    })
                    ->update(['attendee_fetched_at' => now()]);
            }
        }

        if (Schema::hasTable('xs2_orders')) {
            Schema::table('xs2_orders', function (Blueprint $table): void {
                if (! Schema::hasColumn('xs2_orders', 'attendees_copied_from_sb_at')) {
                    $table->timestamp('attendees_copied_from_sb_at')->nullable()->after('guest_data_source_fingerprint');
                }
                if (! Schema::hasColumn('xs2_orders', 'xs2_eticket_request')) {
                    $table->json('xs2_eticket_request')->nullable()->after('attendees_copied_from_sb_at');
                }
                if (! Schema::hasColumn('xs2_orders', 'xs2_eticket_response')) {
                    $table->json('xs2_eticket_response')->nullable()->after('xs2_eticket_request');
                }
                if (! Schema::hasColumn('xs2_orders', 'eticket_fetched_at')) {
                    $table->timestamp('eticket_fetched_at')->nullable()->after('xs2_eticket_response');
                }
                if (! Schema::hasColumn('xs2_orders', 'eticket_error')) {
                    $table->text('eticket_error')->nullable()->after('eticket_fetched_at');
                }
            });
        }

        if (! Schema::hasTable('xs2_order_guest_data_logs')) {
            Schema::create('xs2_order_guest_data_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('xs2_order_id')->constrained('xs2_orders')->cascadeOnDelete();
                $table->json('request_payload')->nullable();
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->json('response_body')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('pushed_at')->nullable();
                $table->timestamps();

                $table->index(['xs2_order_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('xs2_order_guest_data_logs');

        if (Schema::hasTable('xs2_orders')) {
            Schema::table('xs2_orders', function (Blueprint $table): void {
                foreach ([
                    'eticket_error',
                    'eticket_fetched_at',
                    'xs2_eticket_response',
                    'xs2_eticket_request',
                    'attendees_copied_from_sb_at',
                ] as $column) {
                    if (Schema::hasColumn('xs2_orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('sb_orders')) {
            Schema::table('sb_orders', function (Blueprint $table): void {
                foreach (['attendee_fetch_error', 'attendee_fetched_at'] as $column) {
                    if (Schema::hasColumn('sb_orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
