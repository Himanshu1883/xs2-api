<?php

namespace Tests\Feature;

use App\Jobs\PublishSplitListings;
use App\Models\EventMapping;
use App\Models\Xs2CategoryMapping;
use App\Models\Xs2CategoryMappingDetail;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\Currency\CurrencyConversionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublishSplitListingsCurrencyConversionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        config()->set('currency.enabled', true);
        config()->set('currency.rates.EUR.GBP', 81.67 / 95);
        config()->set('services.xs2.minor_unit_divisor', 100);
        config()->set('services.seller_api.enabled', true);
        config()->set('services.seller_api.base_url', 'https://seller.test');
        config()->set('services.seller_api.listing_base_url', 'https://seller.test');
        config()->set('services.seller_api.api_key', 'seller-test-key');
        config()->set('services.seller_api.api_key_header', 'apiKey');
        config()->set('services.seller_api.idempotency_key_header', 'Idempotency-Key');
        config()->set('services.seller_api.create_listing_endpoint', '/api/ticket/create');
        config()->set('services.seller_api.update_listing_endpoint', '/api/ticket/edit');
        config()->set('services.seller_api.disable_listing_endpoint', '/api/ticket/update_status');
        config()->set('services.seller_api.ticket_dropdown_endpoint', '/api/ticket_dropdown');
        config()->set('services.seller_api.seller_id', 77);
        config()->set('services.seller_api.price_uses_minor_units', false);
        config()->set('services.seller_api.external_reference_prefix', 'XS2-');
        config()->set('listing_publish_rules.enabled', false);
    }

    public function test_publish_split_listings_converts_eur_ticket_to_gbp_for_gbp_only_event(): void
    {
        Cache::flush();

        $capturedPayloads = [];
        Http::fake(function ($request) use (&$capturedPayloads) {
            if (str_contains($request->url(), 'ticket_dropdown')) {
                return Http::response([
                    'result' => [
                        'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                        'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                        'category' => [['id' => 4, 'category_name' => 'Longside Upper Tier']],
                        'currency' => [['currency_code' => 'GBP']],
                    ],
                ]);
            }

            $payload = [];
            foreach ($request->data() as $part) {
                if (is_array($part) && isset($part['name'])) {
                    $payload[$part['name']] = $part['contents'];
                }
            }
            $capturedPayloads[] = $payload;

            return Http::response(['ticket_id' => 9000 + count($capturedPayloads)]);
        });

        $ticket = $this->eurTicketOnGbpEvent(stock: 2);

        $job = new PublishSplitListings($ticket->id, [
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 0,
            'base_price' => 257.0,
        ]);
        $job->handle(app(\App\Services\SplitListings\SplitListingService::class));

        $this->assertNotEmpty($capturedPayloads, 'Expected Seller API createListing to be called.');
        $payload = $capturedPayloads[0];
        $expectedGbp = number_format(
            app(CurrencyConversionService::class)->convertMajor(257.0, 'EUR', 'GBP'),
            2,
            '.',
            ''
        );

        $this->assertSame('GBP', $payload['price_type'] ?? null);
        $this->assertSame($expectedGbp, $payload['price'] ?? null);
        $this->assertSame($expectedGbp, $payload['facevalue'] ?? null);
        $this->assertSame('1', $payload['status'] ?? null);
    }

    public function test_publish_split_listings_uses_ticket_dropdown_currency_when_match_info_price_type_is_wrong(): void
    {
        Cache::flush();

        $capturedPayloads = [];
        Http::fake(function ($request) use (&$capturedPayloads) {
            if (str_contains($request->url(), 'ticket_dropdown')) {
                return Http::response([
                    'result' => [
                        'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                        'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                        'category' => [['id' => 4, 'category_name' => 'Longside Upper Tier']],
                        'currency' => [['currency_code' => 'GBP']],
                    ],
                ]);
            }

            $payload = [];
            foreach ($request->data() as $part) {
                if (is_array($part) && isset($part['name'])) {
                    $payload[$part['name']] = $part['contents'];
                }
            }
            $capturedPayloads[] = $payload;

            return Http::response(['ticket_id' => 9000 + count($capturedPayloads)]);
        });

        $ticket = $this->eurTicketOnGbpEvent(stock: 2, matchInfoPriceType: 'EUR');

        $job = new PublishSplitListings($ticket->id, [
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 0,
            'base_price' => 257.0,
        ]);
        $job->handle(app(\App\Services\SplitListings\SplitListingService::class));

        $this->assertNotEmpty($capturedPayloads, 'Expected Seller API createListing to be called.');
        $payload = $capturedPayloads[0];
        $expectedGbp = number_format(
            app(CurrencyConversionService::class)->convertMajor(257.0, 'EUR', 'GBP'),
            2,
            '.',
            ''
        );

        $this->assertSame('GBP', $payload['price_type'] ?? null);
        $this->assertSame($expectedGbp, $payload['price'] ?? null);
        $this->assertSame($expectedGbp, $payload['facevalue'] ?? null);
    }

    public function test_publish_split_listings_converts_qar_ticket_to_usd_for_usd_event(): void
    {
        Cache::flush();

        $capturedPayloads = [];
        Http::fake(function ($request) use (&$capturedPayloads) {
            if (str_contains($request->url(), 'ticket_dropdown')) {
                return Http::response([
                    'result' => [
                        'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                        'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                        'category' => [['id' => 4, 'category_name' => 'Longside Upper Tier']],
                        'currency' => [['currency_code' => 'USD']],
                    ],
                ]);
            }

            $payload = [];
            foreach ($request->data() as $part) {
                if (is_array($part) && isset($part['name'])) {
                    $payload[$part['name']] = $part['contents'];
                }
            }
            $capturedPayloads[] = $payload;

            return Http::response(['ticket_id' => 9000 + count($capturedPayloads)]);
        });

        $ticket = $this->qarTicketOnUsdEvent(stock: 2);

        $job = new PublishSplitListings($ticket->id, [
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 0,
            'base_price' => 364.0,
        ]);
        $job->handle(app(\App\Services\SplitListings\SplitListingService::class));

        $this->assertNotEmpty($capturedPayloads, 'Expected Seller API createListing to be called.');
        $payload = $capturedPayloads[0];
        $expectedUsd = number_format(
            app(CurrencyConversionService::class)->convertMajor(364.0, 'QAR', 'USD'),
            2,
            '.',
            ''
        );

        $this->assertSame('USD', $payload['price_type'] ?? null);
        $this->assertSame($expectedUsd, $payload['price'] ?? null);
        $this->assertSame($expectedUsd, $payload['facevalue'] ?? null);
    }

    public function test_publish_split_listings_uses_category_name_when_sb_has_no_ticket_plus(): void
    {
        Cache::flush();

        $capturedPayloads = [];
        Http::fake(function ($request) use (&$capturedPayloads) {
            if (str_contains($request->url(), 'ticket_dropdown')) {
                return Http::response([
                    'result' => [
                        'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                        'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                        'category' => [
                            ['id' => 1, 'category_name' => 'Away'],
                            ['id' => 2, 'category_name' => 'Shortside Lower Tier'],
                            ['id' => 3, 'category_name' => 'Longside Upper Tier'],
                            ['id' => 4, 'category_name' => 'Longside Lower Tier'],
                            ['id' => 5, 'category_name' => 'VIP & Hospitality'],
                            ['id' => 6, 'category_name' => 'Shortside Upper Tier'],
                        ],
                        'currency' => [['currency_code' => 'EUR']],
                    ],
                ]);
            }

            $payload = [];
            foreach ($request->data() as $part) {
                if (is_array($part) && isset($part['name'])) {
                    $payload[$part['name']] = $part['contents'];
                }
            }
            $capturedPayloads[] = $payload;

            return Http::response(['ticket_id' => 9400 + count($capturedPayloads)]);
        });

        $ticket = $this->ticketPlusOnMatch9426(stock: 2);

        $job = new PublishSplitListings($ticket->id, [
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 0,
            'base_price' => 120.0,
        ]);
        $job->handle(app(\App\Services\SplitListings\SplitListingService::class));

        $this->assertNotEmpty($capturedPayloads, 'Expected Seller API createListing to be called.');
        $payload = $capturedPayloads[0];
        $this->assertSame('Ticket Plus', $payload['category_name'] ?? null);
        $this->assertArrayNotHasKey('ticket_category', $payload);
    }

    private function ticketPlusOnMatch9426(int $stock): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-ticket-plus',
            'event_name' => 'Ticket Plus Event',
            'hometeam_name' => 'Home',
            'event_status' => 'notstarted',
            'date_start_local' => now()->addWeek(),
            'raw_payload' => [],
        ]);

        $mapping = EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 9426,
            'status' => 'mapped',
        ]);

        $categoryMapping = Xs2CategoryMapping::query()->create([
            'status' => 'pending_category_mapping',
        ]);

        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_event_id' => $event->external_event_id,
            'external_ticket_id' => 'ticket-9426-ticket-plus',
            'ticket_status' => 'available',
            'stock' => $stock,
            'category_name' => 'Ticket Plus',
            'ticket_type' => 'eticket',
            'currency_code' => 'EUR',
            'net_rate' => 12000,
            'face_value' => 12000,
            'flags' => [],
            'options' => [],
            'raw_payload' => [],
        ]);

        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $mapping->id,
            'xs2_category_mapping_id' => $categoryMapping->id,
            'mapping_status' => 'pending_category_mapping',
        ]);

        return $ticket->fresh(['xs2Event.mapping']);
    }

    private function qarTicketOnUsdEvent(int $stock, ?string $matchInfoPriceType = 'USD'): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-qar-usd',
            'event_name' => 'Qatar Event',
            'hometeam_name' => 'Home',
            'event_status' => 'notstarted',
            'date_start_local' => now()->addWeek(),
            'raw_payload' => [],
        ]);

        Schema::table('match_info', function (Blueprint $table): void {
            if (! Schema::hasColumn('match_info', 'price_type')) {
                $table->string('price_type')->nullable();
            }
        });

        \DB::table('match_info')->insert([
            'm_id' => 9001,
            'match_name' => 'Qatar Event',
            'match_date' => now()->addWeek(),
            'price_type' => $matchInfoPriceType,
        ]);

        $mapping = EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 9001,
            'status' => 'mapped',
        ]);

        $categoryMapping = Xs2CategoryMapping::query()->create([
            'status' => 'mapped',
            'manually_confirmed' => true,
            'stadium_seat_id' => 4,
        ]);
        Xs2CategoryMappingDetail::query()->create([
            'xs2_category_mapping_id' => $categoryMapping->id,
            'stadium_detail_id' => 1,
            'stadium_seat_id' => 4,
            'stadium_seat_name' => 'Longside Upper Tier',
        ]);

        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_event_id' => $event->external_event_id,
            'external_ticket_id' => 'ticket-9001-qar',
            'ticket_status' => 'available',
            'stock' => $stock,
            'category_name' => 'Longside Upper Tier',
            'ticket_type' => 'eticket',
            'currency_code' => 'QAR',
            'net_rate' => 36400,
            'face_value' => 36400,
            'flags' => [],
            'options' => [],
            'raw_payload' => [],
        ]);

        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $mapping->id,
            'xs2_category_mapping_id' => $categoryMapping->id,
            'mapping_status' => 'ready_to_publish',
        ]);

        return $ticket->fresh(['xs2Event.mapping']);
    }

    private function eurTicketOnGbpEvent(int $stock, ?string $matchInfoPriceType = 'GBP'): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-tottenham-everton',
            'event_name' => 'Tottenham Hotspur vs Everton',
            'hometeam_name' => 'Tottenham Hotspur',
            'event_status' => 'notstarted',
            'date_start_local' => now()->addWeek(),
            'raw_payload' => [],
        ]);

        Schema::table('match_info', function (Blueprint $table): void {
            if (! Schema::hasColumn('match_info', 'price_type')) {
                $table->string('price_type')->nullable();
            }
        });

        \DB::table('match_info')->insert([
            'm_id' => 5616,
            'match_name' => 'Tottenham Hotspur vs Everton',
            'match_date' => now()->addWeek(),
            'price_type' => $matchInfoPriceType,
        ]);

        $mapping = EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 5616,
            'status' => 'mapped',
        ]);

        $categoryMapping = Xs2CategoryMapping::query()->create([
            'status' => 'mapped',
            'manually_confirmed' => true,
            'stadium_seat_id' => 4,
        ]);
        Xs2CategoryMappingDetail::query()->create([
            'xs2_category_mapping_id' => $categoryMapping->id,
            'stadium_detail_id' => 1,
            'stadium_seat_id' => 4,
            'stadium_seat_name' => 'Longside Upper Tier',
        ]);

        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_event_id' => $event->external_event_id,
            'external_ticket_id' => 'ticket-5616-eur',
            'ticket_status' => 'available',
            'stock' => $stock,
            'category_name' => 'Longside Upper Tier',
            'ticket_type' => 'eticket',
            'currency_code' => 'EUR',
            'net_rate' => 25700,
            'face_value' => 25700,
            'flags' => [],
            'options' => [],
            'raw_payload' => [],
        ]);

        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $mapping->id,
            'xs2_category_mapping_id' => $categoryMapping->id,
            'mapping_status' => 'ready_to_publish',
        ]);

        return $ticket->fresh(['xs2Event.mapping']);
    }

    private function createTables(): void
    {
        foreach ([
            'listing_split_activities',
            'listing_splits',
            'external_listing_mappings',
            'xs2_ticket_mapping_states',
            'xs2_category_mapping_details',
            'xs2_category_mappings',
            'xs2_tickets',
            'event_mappings',
            'xs2_events',
            'match_info',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_id')->nullable();
            $table->string('event_name')->nullable();
            $table->string('hometeam_name')->nullable();
            $table->dateTime('date_start_local')->nullable();
            $table->string('event_status')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();
        });

        Schema::create('match_info', function (Blueprint $table): void {
            $table->increments('m_id');
            $table->string('match_name')->nullable();
            $table->dateTime('match_date')->nullable();
            $table->string('price_type')->nullable();
        });

        Schema::create('event_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->unsignedInteger('m_id')->nullable();
            $table->string('status')->default('pending');
            $table->json('match_details')->nullable();
            $table->timestamps();
        });

        Schema::create('xs2_category_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_category_id')->nullable();
            $table->unsignedBigInteger('xs2_stadium_mapping_id')->nullable();
            $table->unsignedBigInteger('stadium_seat_id')->nullable();
            $table->string('status')->default('pending');
            $table->boolean('manually_confirmed')->default(false);
            $table->json('candidate_scores')->nullable();
            $table->timestamps();
        });

        Schema::create('xs2_category_mapping_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_category_mapping_id');
            $table->unsignedBigInteger('stadium_detail_id')->nullable();
            $table->unsignedBigInteger('stadium_seat_id')->nullable();
            $table->string('stadium_seat_name')->nullable();
            $table->timestamps();
        });

        Schema::create('xs2_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->string('external_event_id')->nullable();
            $table->string('external_ticket_id')->unique();
            $table->string('ticket_status')->nullable();
            $table->string('category_name')->nullable();
            $table->string('ticket_type')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedBigInteger('net_rate')->nullable();
            $table->unsignedBigInteger('face_value')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->json('flags')->nullable();
            $table->json('options')->nullable();
            $table->json('raw_payload')->nullable();
            $table->boolean('split_enabled')->default(false);
            $table->unsignedInteger('split_quantity')->nullable();
            $table->string('price_increment_type', 20)->nullable();
            $table->decimal('price_increment_value', 12, 2)->nullable();
            $table->string('split_sync_status', 30)->default('idle');
            $table->text('split_sync_error')->nullable();
            $table->string('sync_status')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('xs2_ticket_mapping_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_ticket_id');
            $table->unsignedBigInteger('event_mapping_id')->nullable();
            $table->unsignedBigInteger('xs2_venue_id')->nullable();
            $table->unsignedBigInteger('xs2_category_id')->nullable();
            $table->unsignedBigInteger('xs2_stadium_mapping_id')->nullable();
            $table->unsignedBigInteger('xs2_category_mapping_id')->nullable();
            $table->string('mapping_status')->default('pending');
            $table->text('mapping_error')->nullable();
            $table->timestamp('last_resolved_at')->nullable();
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
            $table->string('action', 50);
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('external_listing_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30)->nullable();
            $table->unsignedBigInteger('xs2_ticket_id');
            $table->integer('local_event_id')->nullable();
            $table->unsignedBigInteger('event_mapping_id')->nullable();
            $table->string('seller_listing_id')->nullable();
            $table->string('seller_reference')->nullable();
            $table->string('status', 30)->default('pending');
            $table->timestamps();
        });
    }
}
