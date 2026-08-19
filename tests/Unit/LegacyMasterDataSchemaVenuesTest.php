<?php

namespace Tests\Unit;

use App\Services\Mapping\LegacyMasterDataSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyMasterDataSchemaVenuesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stadium', function (Blueprint $table): void {
            $table->unsignedBigInteger('s_id')->primary();
            $table->string('stadium_name')->nullable();
            $table->unsignedBigInteger('country')->nullable();
            $table->unsignedBigInteger('city')->nullable();
        });

        Schema::create('api_stadium', function (Blueprint $table): void {
            $table->unsignedBigInteger('stadium_id')->primary();
            $table->string('stadium_name')->nullable();
            $table->string('source_type')->default('xs2event');
        });
    }

    public function test_counts_distinct_venues_across_stadium_and_api_stadium(): void
    {
        DB::table('stadium')->insert([
            ['s_id' => 1, 'stadium_name' => 'Alpha Arena', 'country' => 1, 'city' => 10],
            ['s_id' => 2, 'stadium_name' => 'Beta Park', 'country' => 1, 'city' => 10],
        ]);
        DB::table('api_stadium')->insert([
            ['stadium_id' => 2, 'stadium_name' => 'Beta Park', 'source_type' => 'tixstock'],
            ['stadium_id' => 3, 'stadium_name' => 'Catalog Only Ground', 'source_type' => 'tixstock'],
        ]);

        $schema = app(LegacyMasterDataSchema::class);

        $this->assertSame(3, $schema->countDistinctSeatsbrokerVenues());
    }

    public function test_stadium_options_include_api_catalog_matches_by_search(): void
    {
        DB::table('stadium')->insert([
            ['s_id' => 100, 'stadium_name' => 'City Ground', 'country' => 1, 'city' => 50],
        ]);
        DB::table('api_stadium')->insert([
            ['stadium_id' => 900, 'stadium_name' => 'External Catalog Venue', 'source_type' => 'xs2event'],
        ]);

        $schema = app(LegacyMasterDataSchema::class);
        $options = $schema->stadiumOptionsForMapping(50, 'External Catalog', null);

        $this->assertContains(100, collect($options)->pluck('id')->all());
        $this->assertContains(900, collect($options)->pluck('id')->all());
    }

    public function test_stadium_options_collapse_duplicate_names(): void
    {
        Schema::create('stadium_details', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('stadium_id');
        });

        DB::table('stadium')->insert([
            ['s_id' => 10, 'stadium_name' => 'Allianz Arena', 'country' => 1, 'city' => 50],
            ['s_id' => 20, 'stadium_name' => 'Allianz Arena', 'country' => 1, 'city' => 50],
        ]);
        DB::table('stadium_details')->insert([
            ['id' => 1, 'stadium_id' => 10],
            ['id' => 2, 'stadium_id' => 20],
            ['id' => 3, 'stadium_id' => 20],
        ]);

        $schema = app(LegacyMasterDataSchema::class);
        $options = $schema->stadiumOptionsForMapping(50, 'Allianz', null);

        $this->assertCount(1, $options);
        $this->assertSame(20, $options[0]['id']);
        $this->assertSame([10], $options[0]['alternate_ids']);
    }
}
