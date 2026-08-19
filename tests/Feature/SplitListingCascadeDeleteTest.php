<?php

namespace Tests\Feature;

use App\Jobs\DeleteSplitListings;
use App\Jobs\DeleteXs2SellerListing;
use App\Models\ListingSplit;
use App\Models\User;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SplitListingCascadeDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_admin_can_queue_cascade_delete_for_one_sublisting(): void
    {
        Queue::fake();
        [$ticket, $split] = $this->splitTicket(activeSplits: 4);

        $this->withToken($this->adminToken())
            ->deleteJson("/api/admin/xs2/listings/{$ticket->id}/split/{$split->id}")
            ->assertAccepted()
            ->assertJsonPath('data.ticket_id', $ticket->id)
            ->assertJsonPath('data.trigger_split_id', $split->id)
            ->assertJsonPath('data.queued', true);

        Queue::assertPushed(
            DeleteSplitListings::class,
            fn (DeleteSplitListings $job): bool => $job->ticketId === $ticket->id
                && $job->triggerSplitId === $split->id,
        );
    }

    public function test_admin_can_delete_all_splits_via_master_split_endpoint(): void
    {
        Queue::fake();
        [$ticket] = $this->splitTicket(activeSplits: 4);

        $this->withToken($this->adminToken())
            ->deleteJson("/api/admin/xs2/listings/{$ticket->id}/split")
            ->assertAccepted()
            ->assertJsonPath('data.ticket_id', $ticket->id)
            ->assertJsonPath('data.queued', true);

        Queue::assertPushed(
            DeleteSplitListings::class,
            fn (DeleteSplitListings $job): bool => $job->ticketId === $ticket->id
                && $job->triggerSplitId === null,
        );
    }

    public function test_cascade_delete_rejects_split_from_other_master(): void
    {
        [$ticketA] = $this->splitTicket(activeSplits: 2);
        [, $foreignSplit] = $this->splitTicket(activeSplits: 2);

        $this->withToken($this->adminToken())
            ->deleteJson("/api/admin/xs2/listings/{$ticketA->id}/split/{$foreignSplit->id}?sync=1")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['split']);
    }

    public function test_master_delete_listing_cascades_to_all_sublistings_on_sb(): void
    {
        config()->set('services.seller_api.listing_base_url', 'https://seller.test');
        config()->set('services.seller_api.delete_listing_endpoint', '/api/ticket/delete');
        config()->set('services.seller_api.seller_id', 77);
        config()->set('services.seller_api.api_key', 'seller-test-key');

        Http::fake([
            'https://seller.test/api/ticket/delete' => Http::response([
                'status' => 1,
                'message' => 'Ticket deleted successfully',
            ]),
        ]);

        [$ticket] = $this->splitTicket(activeSplits: 3);
        $ticket->xs2Event->mapping()->create([
            'm_id' => 9020,
            'status' => 'mapped',
        ]);

        DeleteXs2SellerListing::dispatchSync($ticket->id);

        $ticket->refresh();
        $this->assertFalse((bool) $ticket->split_enabled);
        $this->assertSame('pending', $ticket->sync_status);
        $this->assertSame(0, $ticket->listingSplits()->where('status', 'active')->count());
        $this->assertSame(3, $ticket->listingSplits()->where('status', 'deleted')->count());

        $deleteRequests = collect(Http::recorded())->filter(
            fn (array $record): bool => $record[0]->method() === 'POST'
                && str_contains($record[0]->url(), '/api/ticket/delete'),
        );
        $this->assertGreaterThanOrEqual(3, $deleteRequests->count());
        $this->assertTrue($deleteRequests->contains(
            fn (array $record): bool => collect($record[0]->data())->contains(
                fn (array $part): bool => $part['name'] === 'ticket_id' && (string) $part['contents'] === '912501',
            ),
        ));
    }

    /** @return array{0: Xs2Ticket, 1: ListingSplit} */
    private function splitTicket(int $activeSplits = 2): array
    {
        $event = Xs2Event::query()->create([
            'event_status' => 'available',
            'date_start_local' => now()->addWeek(),
        ]);

        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'split-cascade-'.uniqid(),
            'external_event_id' => 'event-1',
            'ticket_status' => 'available',
            'stock' => 8,
            'sync_status' => 'synced',
            'split_enabled' => true,
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 5,
        ]);

        $first = null;
        for ($order = 1; $order <= $activeSplits; $order++) {
            $split = ListingSplit::query()->create([
                'master_listing_id' => $ticket->id,
                'seatsbroker_listing_id' => (string) (912500 + $order),
                'seller_reference' => 'XS2-'.$ticket->external_ticket_id.'-S'.$order,
                'quantity' => 2,
                'price' => 100 + (($order - 1) * 5),
                'split_order' => $order,
                'status' => 'active',
                'sync_status' => 'synced',
            ]);
            $first ??= $split;
        }

        return [$ticket, $first ?? throw new \RuntimeException('Expected at least one split.')];
    }

    private function adminToken(): string
    {
        return User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;
    }

    private function createTables(): void
    {
        foreach ([
            'listing_split_activities',
            'external_listing_mappings',
            'listing_splits',
            'event_mappings',
            'xs2_tickets',
            'xs2_events',
            'personal_access_tokens',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
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
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('date_start_local')->nullable();
            $table->string('event_status')->nullable();
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();
        });
        Schema::create('event_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->unsignedInteger('m_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
        Schema::create('xs2_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->string('external_ticket_id')->unique();
            $table->string('external_event_id')->nullable();
            $table->string('ticket_status')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->string('sync_status')->nullable();
            $table->text('sync_error')->nullable();
            $table->boolean('split_enabled')->default(false);
            $table->unsignedInteger('split_quantity')->nullable();
            $table->string('price_increment_type', 20)->nullable();
            $table->decimal('price_increment_value', 12, 2)->nullable();
            $table->string('split_sync_status', 30)->default('idle');
            $table->text('split_sync_error')->nullable();
            $table->timestamps();
        });
        Schema::create('listing_splits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_listing_id');
            $table->string('seatsbroker_listing_id')->nullable();
            $table->string('seller_reference')->unique();
            $table->unsignedInteger('quantity');
            $table->decimal('price', 12, 2);
            $table->unsignedSmallInteger('split_order');
            $table->string('status', 20)->default('active');
            $table->string('sync_status', 30)->default('pending');
            $table->string('last_payload_hash', 64)->nullable();
            $table->json('last_request')->nullable();
            $table->json('last_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
        Schema::create('listing_split_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_listing_id');
            $table->unsignedBigInteger('listing_split_id')->nullable();
            $table->string('action', 40);
            $table->string('message');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('external_listing_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->unsignedBigInteger('xs2_ticket_id');
            $table->unsignedInteger('local_event_id')->nullable();
            $table->unsignedBigInteger('event_mapping_id')->nullable();
            $table->string('seller_listing_id')->nullable();
            $table->string('seller_reference')->unique();
            $table->string('status')->default('pending');
            $table->string('last_payload_hash')->nullable();
            $table->unsignedInteger('last_pushed_quantity')->nullable();
            $table->unsignedBigInteger('last_pushed_price')->nullable();
            $table->json('last_request')->nullable();
            $table->json('last_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });
    }
}
