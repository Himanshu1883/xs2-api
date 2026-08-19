<?php

namespace Tests\Feature;

use App\Jobs\DisableSellerListing;
use App\Jobs\PushXs2TicketToSellerApi;
use App\Models\User;
use App\Models\Xs2Category;
use App\Models\Xs2CategoryContext;
use App\Models\Xs2CategoryMapping;
use App\Models\Xs2CategoryMappingDetail;
use App\Models\Xs2Event;
use App\Models\Xs2StadiumMapping;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Xs2CategoryMappingBulkRemovalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_bulk_removal_reconciles_listings_for_every_removed_mapping(): void
    {
        $mappingIds = collect();

        foreach ([1, 2] as $number) {
            $event = Xs2Event::create([
                'external_event_id' => "event-bulk-remove-{$number}",
                'venue_id' => 'venue-1',
            ]);
            $category = Xs2Category::create([
                'xs2_event_id' => $event->id,
                'external_category_id' => "category-bulk-remove-{$number}",
                'external_event_id' => $event->external_event_id,
                'category_name' => 'Longside',
                'raw_payload' => [],
            ]);
            Xs2CategoryContext::create([
                'xs2_category_id' => $category->id,
                'external_venue_id' => 'venue-1',
                'category_type' => 'grandstand',
            ]);
            $mapping = Xs2CategoryMapping::create([
                'xs2_category_id' => $category->id,
                'stadium_id' => 500,
                'status' => 'mapped',
                'mapping_method' => 'manual',
                'manually_confirmed' => true,
                'mapped_at' => now(),
            ]);
            Xs2CategoryMappingDetail::create([
                'xs2_category_mapping_id' => $mapping->id,
                'stadium_detail_id' => 900 + $number,
                'stadium_seat_id' => 77,
            ]);
            $mappingIds->push($mapping->id);
        }

        Queue::fake();

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2/category-mappings/bulk-remove', [
                'stadium_id' => 500,
                'category_name' => 'Longside',
                'external_venue_id' => 'venue-1',
            ])
            ->assertOk()
            ->assertJsonPath('meta.updated_count', 2);

        foreach ($mappingIds as $mappingId) {
            $this->assertDatabaseHas('xs2_category_mappings', [
                'id' => $mappingId,
                'status' => 'pending_category_mapping',
                'manually_confirmed' => false,
            ]);
        }

        $this->assertDatabaseCount('xs2_category_mapping_details', 0);
    }

    public function test_bulk_removal_clears_pending_mappings_that_still_have_suggested_details(): void
    {
        $mapping = $this->createCategoryMapping('remove-pending-details', 'venue-1', 'pending_category_mapping', true);
        Xs2CategoryMappingDetail::create([
            'xs2_category_mapping_id' => $mapping->id,
            'stadium_detail_id' => 902,
            'stadium_seat_id' => 77,
            'block' => 'suggested-block',
            'stadium_seat_name' => 'Longside',
        ]);

        Queue::fake();

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2/category-mappings/bulk-remove', [
                'stadium_id' => 500,
                'category_name' => 'Longside',
                'external_venue_id' => 'venue-1',
            ])
            ->assertOk()
            ->assertJsonPath('meta.updated_count', 1);

        $this->assertDatabaseHas('xs2_category_mappings', [
            'id' => $mapping->id,
            'status' => 'pending_category_mapping',
            'manually_confirmed' => false,
        ]);
        $this->assertDatabaseMissing('xs2_category_mapping_details', [
            'xs2_category_mapping_id' => $mapping->id,
        ]);
    }

    public function test_bulk_removal_with_a_null_venue_only_removes_the_null_context_group(): void
    {
        $nullContextMapping = $this->createCategoryMapping('remove-null-context', null, 'mapped', true);
        $noContextMapping = $this->createCategoryMapping('remove-no-context', null, 'mapped', false);
        $venueMapping = $this->createCategoryMapping('remove-venue-context', 'venue-1', 'mapped', true);

        Queue::fake();

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2/category-mappings/bulk-remove', [
                'stadium_id' => 500,
                'category_name' => 'Longside',
                'external_venue_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('meta.updated_count', 2);

        foreach ([$nullContextMapping, $noContextMapping] as $mapping) {
            $this->assertDatabaseHas('xs2_category_mappings', [
                'id' => $mapping->id,
                'status' => 'pending_category_mapping',
            ]);
            $this->assertDatabaseMissing('xs2_category_mapping_details', [
                'xs2_category_mapping_id' => $mapping->id,
            ]);
        }

        $this->assertDatabaseHas('xs2_category_mappings', [
            'id' => $venueMapping->id,
            'status' => 'mapped',
        ]);
        $this->assertDatabaseHas('xs2_category_mapping_details', [
            'xs2_category_mapping_id' => $venueMapping->id,
        ]);
    }

    public function test_bulk_confirmation_with_a_null_venue_only_confirms_the_null_context_group(): void
    {
        $stadiumMapping = Xs2StadiumMapping::create([
            'stadium_id' => 500,
            'status' => 'mapped',
        ]);
        $nullContextMapping = $this->createCategoryMapping('confirm-null-context', null, 'pending_category_mapping', true, $stadiumMapping->id);
        $noContextMapping = $this->createCategoryMapping('confirm-no-context', null, 'pending_category_mapping', false, $stadiumMapping->id);
        $venueMapping = $this->createCategoryMapping('confirm-venue-context', 'venue-1', 'pending_category_mapping', true, $stadiumMapping->id);

        Queue::fake();

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2/category-mappings/bulk-confirm', [
                'stadium_id' => 500,
                'category_name' => 'Longside',
                'external_venue_id' => null,
                'stadium_detail_id' => 901,
            ])
            ->assertOk()
            ->assertJsonPath('meta.updated_count', 2);

        foreach ([$nullContextMapping, $noContextMapping] as $mapping) {
            $this->assertDatabaseHas('xs2_category_mappings', [
                'id' => $mapping->id,
                'status' => 'mapped',
            ]);
            $this->assertDatabaseHas('xs2_category_mapping_details', [
                'xs2_category_mapping_id' => $mapping->id,
                'stadium_detail_id' => 901,
            ]);
        }

        $this->assertDatabaseHas('xs2_category_mappings', [
            'id' => $venueMapping->id,
            'status' => 'pending_category_mapping',
        ]);
        $this->assertDatabaseMissing('xs2_category_mapping_details', [
            'xs2_category_mapping_id' => $venueMapping->id,
        ]);
    }

    public function test_bulk_confirmation_resolves_pending_tickets_synchronously(): void
    {
        $stadiumMapping = Xs2StadiumMapping::create([
            'xs2_venue_id' => null,
            'stadium_id' => 500,
            'status' => 'mapped',
        ]);
        $event = Xs2Event::create([
            'external_event_id' => 'event-sync-resolve',
            'venue_id' => 'venue-1',
        ]);
        \DB::table('event_mappings')->insert([
            'xs2_event_id' => $event->id,
            'm_id' => 4242,
            'status' => 'mapped',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'category-sync-resolve',
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Longside',
            'raw_payload' => [],
        ]);
        Xs2CategoryContext::create([
            'xs2_category_id' => $category->id,
            'external_venue_id' => 'venue-1',
            'category_type' => 'grandstand',
        ]);
        $mapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'xs2_stadium_mapping_id' => $stadiumMapping->id,
            'stadium_id' => 500,
            'status' => 'pending_category_mapping',
        ]);
        \DB::table('xs2_venues')->insert([
            'id' => 9,
            'external_venue_id' => 'venue-1',
            'venue_name' => 'Test Venue',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('xs2_stadium_mappings')->where('id', $stadiumMapping->id)->update([
            'xs2_venue_id' => 9,
        ]);
        $ticketId = \DB::table('xs2_tickets')->insertGetId([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-sync-resolve',
            'category_id' => $category->external_category_id,
            'category_name' => 'Longside',
            'ticket_status' => 'available',
            'stock' => 5,
            'raw_payload' => json_encode(['venue_id' => 'venue-1']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('xs2_ticket_mapping_states')->insert([
            'xs2_ticket_id' => $ticketId,
            'xs2_category_mapping_id' => $mapping->id,
            'xs2_category_id' => $category->id,
            'mapping_status' => 'pending_category_mapping',
            'mapping_error' => 'No stadium detail met the pending confidence threshold.',
            'last_resolved_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::fake([PushXs2TicketToSellerApi::class, DisableSellerListing::class]);

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2/category-mappings/bulk-confirm', [
                'stadium_id' => 500,
                'category_name' => 'Longside',
                'external_venue_id' => 'venue-1',
                'stadium_detail_id' => 901,
            ])
            ->assertOk()
            ->assertJsonPath('meta.updated_count', 1);

        $this->assertDatabaseHas('xs2_ticket_mapping_states', [
            'xs2_ticket_id' => $ticketId,
            'mapping_status' => 'ready_to_publish',
            'mapping_error' => null,
        ]);
        Queue::assertPushed(PushXs2TicketToSellerApi::class, fn (PushXs2TicketToSellerApi $job): bool => $job->ticketId === $ticketId);
    }

    private function createCategoryMapping(
        string $suffix,
        ?string $externalVenueId,
        string $status,
        bool $createContext,
        ?int $stadiumMappingId = null,
    ): Xs2CategoryMapping {
        $event = Xs2Event::create([
            'external_event_id' => "event-{$suffix}",
            'venue_id' => $externalVenueId,
        ]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => "category-{$suffix}",
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Longside',
            'raw_payload' => [],
        ]);
        if ($createContext) {
            Xs2CategoryContext::create([
                'xs2_category_id' => $category->id,
                'external_venue_id' => $externalVenueId,
                'category_type' => 'grandstand',
            ]);
        }

        $mapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'xs2_stadium_mapping_id' => $stadiumMappingId,
            'stadium_id' => 500,
            'status' => $status,
            'mapping_method' => 'manual',
            'manually_confirmed' => $status === 'mapped',
            'mapped_at' => $status === 'mapped' ? now() : null,
        ]);
        if ($status === 'mapped') {
            Xs2CategoryMappingDetail::create([
                'xs2_category_mapping_id' => $mapping->id,
                'stadium_detail_id' => 1000 + $mapping->id,
                'stadium_seat_id' => 77,
            ]);
        }

        return $mapping;
    }

    private function createTables(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedInteger('user_type')->nullable();
            $table->unsignedInteger('store_id')->default(13);
            $table->boolean('two_factor_enabled')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_id')->unique();
            $table->string('venue_id')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->string('external_category_id');
            $table->string('external_event_id');
            $table->string('category_name')->nullable();
            $table->json('raw_payload');
            $table->timestamps();
        });
        Schema::create('xs2_category_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_category_id')->unique();
            $table->string('external_venue_id')->nullable();
            $table->string('category_type')->nullable();
            $table->json('options')->nullable();
            $table->boolean('on_svg')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_category_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_category_id')->unique();
            $table->unsignedBigInteger('xs2_stadium_mapping_id')->nullable();
            $table->unsignedBigInteger('stadium_id')->nullable();
            $table->unsignedBigInteger('stadium_seat_id')->nullable();
            $table->unsignedBigInteger('stadium_detail_id')->nullable();
            $table->string('status')->default('pending_stadium_mapping');
            $table->string('mapping_method')->nullable();
            $table->boolean('manually_confirmed')->default(false);
            $table->timestamp('mapped_at')->nullable();
            $table->text('mapping_error')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_category_mapping_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_category_mapping_id');
            $table->unsignedBigInteger('stadium_detail_id');
            $table->unsignedBigInteger('stadium_seat_id')->nullable();
            $table->string('block')->nullable();
            $table->string('section')->nullable();
            $table->string('name')->nullable();
            $table->string('stadium_seat_name')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_stadium_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_venue_id')->nullable();
            $table->unsignedBigInteger('stadium_id')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('xs2_venues', function (Blueprint $table): void {
            $table->id();
            $table->string('external_venue_id')->unique();
            $table->string('venue_name')->nullable();
            $table->timestamps();
        });
        Schema::create('event_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id')->unique();
            $table->unsignedBigInteger('m_id')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('xs2_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->string('external_ticket_id')->unique();
            $table->string('category_id')->nullable();
            $table->string('category_name')->nullable();
            $table->string('ticket_status')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_ticket_mapping_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_ticket_id')->unique();
            $table->unsignedBigInteger('event_mapping_id')->nullable();
            $table->unsignedBigInteger('xs2_venue_id')->nullable();
            $table->unsignedBigInteger('xs2_category_id')->nullable();
            $table->unsignedBigInteger('xs2_stadium_mapping_id')->nullable();
            $table->unsignedBigInteger('xs2_category_mapping_id')->nullable();
            $table->string('mapping_status');
            $table->text('mapping_error')->nullable();
            $table->timestamp('last_resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('stadium_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stadium_id');
            $table->string('full_block_name')->nullable();
            $table->unsignedBigInteger('category')->nullable();
        });
        \DB::table('stadium_details')->insert([
            'id' => 901,
            'stadium_id' => 500,
            'full_block_name' => 'Block 901',
            'category' => 77,
        ]);
    }

    private function adminToken(): string
    {
        return User::create([
            'first_name' => 'Provider',
            'last_name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'secret',
            'user_type' => 6,
        ])->createToken('test-token')->plainTextToken;
    }
}
