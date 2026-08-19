<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('countries')) {
            Schema::create('countries', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('sortname')->nullable();
                $table->string('name');
                $table->integer('phonecode')->default(0);
                $table->integer('add_by')->default(0);
                $table->string('create_date')->default('');
            });
        }

        if (! Schema::hasTable('cities')) {
            Schema::create('cities', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name');
                $table->integer('state_id')->default(0);
                $table->integer('add_by')->default(0);
                $table->string('create_date')->default('');
            });
        }

        if (! Schema::hasTable('game_category')) {
            Schema::create('game_category', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('category_name');
                $table->integer('parent_cat_id')->default(0);
                $table->string('image')->nullable();
                $table->string('create_date')->nullable();
                $table->integer('status')->default(1);
                $table->integer('store_id')->nullable();
                $table->integer('add_by')->default(0);
            });
        }

        if (! Schema::hasTable('tournament')) {
            Schema::create('tournament', function (Blueprint $table): void {
                $table->increments('t_id');
                $table->string('tournament_name');
                $table->string('status')->default('1');
                $table->string('create_date')->nullable();
                $table->string('popular_tournament')->default('0');
                $table->integer('sort_by')->default(0);
                $table->integer('show_in_list')->default(1);
                $table->string('attendee_status')->default('0');
                $table->integer('category')->nullable();
                $table->string('source_type')->nullable();
                $table->integer('sitemap_status')->default(0);
                $table->integer('show_on_footer')->default(0);
            });
        }

        if (! Schema::hasTable('teams')) {
            Schema::create('teams', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('team_name');
                $table->string('category')->nullable();
                $table->string('team_image')->nullable();
                $table->string('create_date')->nullable();
                $table->integer('status')->default(1);
                $table->integer('show_status')->default(1);
                $table->integer('store_id')->nullable();
                $table->string('source_type')->nullable();
                $table->integer('sitemap_status')->default(0);
                $table->integer('show_on_footer')->default(0);
            });
        }

        if (! Schema::hasTable('stadium')) {
            Schema::create('stadium', function (Blueprint $table): void {
                $table->integer('s_id')->primary();
                $table->integer('stadium_type')->default(1);
                $table->string('stadium_image')->nullable();
                $table->string('stadium_name')->nullable();
                $table->integer('country')->nullable();
                $table->integer('city')->nullable();
                $table->string('width')->default('');
                $table->string('height')->default('');
                $table->string('main_team')->default('');
                $table->text('map_code');
                $table->string('status')->default('1');
                $table->string('attendee_status')->default('0');
                $table->string('create_date')->default('');
                $table->text('stadium_name_ar');
                $table->string('source_type')->nullable();
                $table->string('category')->nullable();
            });
        }

        if (! Schema::hasTable('stadium_seats')) {
            Schema::create('stadium_seats', function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->string('seat_category');
                $table->string('category_color')->nullable();
                $table->string('status');
                $table->string('create_date');
                $table->string('event_type')->default('match');
                $table->string('source_type')->default('1boxoffice');
            });
        }

        if (! Schema::hasTable('stadium_details')) {
            Schema::create('stadium_details', function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->integer('stadium_id');
                $table->string('full_block_name')->nullable();
                $table->string('block_id');
                $table->integer('category')->nullable();
                $table->string('block_color');
                $table->integer('match_id')->nullable();
                $table->string('active_color')->nullable();
                $table->string('source_type')->nullable();
            });
        }

        if (! Schema::hasTable('match_info')) {
            Schema::create('match_info', function (Blueprint $table): void {
                $table->integer('m_id')->primary();
                $table->string('match_name');
                $table->string('extra_title')->nullable();
                $table->string('team_1')->nullable();
                $table->string('team_2')->nullable();
                $table->string('hometown')->nullable();
                $table->string('tournament')->nullable();
                $table->string('slug')->nullable();
                $table->string('status')->default('1');
                $table->string('availability')->nullable();
                $table->string('matchticket')->nullable();
                $table->string('daysremaining')->nullable();
                $table->string('description')->nullable();
                $table->string('meta_title')->nullable();
                $table->string('meta_description')->nullable();
                $table->string('hot_tickets')->nullable();
                $table->dateTime('match_date');
                $table->string('match_time')->nullable();
                $table->unsignedInteger('venue')->nullable();
                $table->string('city')->nullable();
                $table->string('country')->nullable();
                $table->string('create_date')->nullable();
                $table->string('event_type')->nullable();
                $table->string('price_type')->nullable();
                $table->unsignedInteger('store_id')->nullable();
                $table->string('xs2event_id')->nullable();
                $table->string('source_type')->nullable();
                $table->string('category')->nullable();
                $table->integer('tixstock_status')->nullable();
                $table->integer('oneclicket_status')->nullable();
                $table->integer('xs2event_status')->nullable();
                $table->integer('oneboxoffice_status')->nullable();
                $table->integer('upcoming_events')->default(0);
                $table->string('url_key')->default('');
                $table->integer('request')->default(0);
                $table->integer('epl_status')->default(0);
                $table->integer('confirm_status')->default(0);
                $table->integer('affiliate_status')->default(0);
                $table->tinyInteger('show_match_name')->default(0);
            });
        }
    }

    public function down(): void
    {
        // Shared legacy catalogue tables; do not drop in rollback.
    }
};
