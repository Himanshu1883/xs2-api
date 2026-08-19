<?php

namespace Tests\Unit;

use App\Exceptions\Integrations\SellerApiRequestException;
use App\Models\EventMapping;
use App\Models\Xs2CategoryMapping;
use App\Models\Xs2CategoryMappingDetail;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\SellerApi\ListingSalesService;
use App\Services\SellerApi\SellerApiClient;
use App\Services\SellerApi\SellerApiDebugRecorder;
use App\Services\Xs2\Xs2SellerListingTransformer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SellerApiContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.seller_api.base_url', 'https://seller.test');
        config()->set('services.seller_api.listing_base_url', 'https://seller.test');
        config()->set('services.seller_api.api_key', 'seller-test-key');
        config()->set('services.seller_api.api_key_header', 'apiKey');
        config()->set('services.seller_api.idempotency_key_header', 'Idempotency-Key');
        config()->set('services.seller_api.create_listing_endpoint', '/api/ticket/create');
        config()->set('services.seller_api.update_listing_endpoint', '/api/ticket/edit');
        config()->set('services.seller_api.disable_listing_endpoint', '/api/ticket/update_status');
        config()->set('services.seller_api.delete_listing_endpoint', '/api/ticket/delete');
        config()->set('services.seller_api.get_listing_endpoint', '/api/ticket');
        config()->set('services.seller_api.ticket_dropdown_endpoint', '/api/ticket_dropdown');
        config()->set('services.seller_api.seller_id', 77);
        config()->set('services.seller_api.price_uses_minor_units', false);
    }

    public function test_client_posts_delete_listing_with_ticket_match_and_seller_ids(): void
    {
        Http::fake([
            'https://sandbox-sellerapi.seatsbrokers.com/api/ticket/delete' => Http::response([
                'status' => 1,
                'message' => 'Ticket deleted successfully',
            ]),
        ]);

        $response = app(SellerApiClient::class)->deleteListing('872865', [
            'match_id' => 9020,
            'seller_id' => 77,
        ]);

        $this->assertSame(1, $response['status']);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'sandbox-sellerapi.seatsbrokers.com/api/ticket/delete')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'ticket_id' && (string) $part['contents'] === '872865')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'match_id' && (string) $part['contents'] === '9020')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'seller_id' && (string) $part['contents'] === '77'));
    }

    public function test_client_posts_listing_to_the_contract_endpoint_with_api_key(): void
    {
        Http::fake([
            'https://seller.test/api/ticket/create' => Http::response(['ticket_id' => 123]),
        ]);

        $response = app(SellerApiClient::class)->createListing([
            'match_id' => 45,
            'ticket_type' => 2,
            'quantity' => 4,
            'seller_reference' => 'XS2-xs2-ticket-1-event-45',
        ], 'XS2-xs2-ticket-1-event-45');

        $this->assertSame(123, $response['ticket_id']);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://seller.test/api/ticket/create'
            && ($request->header('apiKey')[0] ?? null) === 'seller-test-key'
            && ($request->header('Idempotency-Key')[0] ?? null) === 'XS2-xs2-ticket-1-event-45'
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'seller_reference'
                && $part['contents'] === 'XS2-xs2-ticket-1-event-45'));
    }

    public function test_client_rejects_a_create_response_without_a_listing_id(): void
    {
        Http::fake([
            'https://seller.test/api/ticket/create' => Http::response(['success' => true]),
        ]);

        $this->expectException(SellerApiRequestException::class);
        $this->expectExceptionMessage('missing a listing ID');

        app(SellerApiClient::class)->createListing([
            'match_id' => 45,
            'seller_reference' => 'XS2-xs2-ticket-1-event-45',
        ], 'XS2-xs2-ticket-1-event-45');
    }

    public function test_ticket_dropdown_uses_listing_host_when_catalog_is_externalapi(): void
    {
        config()->set('services.seller_api.base_url', 'https://externalapi.seatsbrokers.com');
        config()->set('services.seller_api.listing_base_url', '');
        config()->set('seller-api.listing_base_url', '');

        Http::fake([
            'https://sandbox-sellerapi.seatsbrokers.com/*' => Http::response([
                'result' => [
                    'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                    'category' => [['id' => 4, 'category_name' => 'Shortside']],
                    'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                ],
            ]),
        ]);

        $response = app(SellerApiClient::class)->ticketDropdown(9020);

        $this->assertSame(2, data_get($response, 'result.ticket_type.0.id'));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'sandbox-sellerapi.seatsbrokers.com')
            && str_contains($request->url(), 'ticket_dropdown')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'match_id' && (string) $part['contents'] === '9020'));
    }

    public function test_client_throws_on_http_200_route_not_found_envelope(): void
    {
        Http::fake([
            'https://seller.test/api/ticket_dropdown' => Http::response([
                'status' => 0,
                'error_code' => 500,
                'message' => 'Something went wrong. Please try again later.',
                'error' => 'The route api/ticket_dropdown could not be found.',
            ]),
        ]);

        $this->expectException(SellerApiRequestException::class);
        $this->expectExceptionMessage('route api/ticket_dropdown could not be found');

        app(SellerApiClient::class)->ticketDropdown(9020);
    }

    public function test_client_throws_on_nested_results_status_zero(): void
    {
        Http::fake([
            'https://seller.test/api/ticket_dropdown' => Http::response([
                'results' => [
                    'message' => 'Invalid API key or Account is in-active33',
                    'status' => 0,
                ],
            ]),
        ]);

        $this->expectException(SellerApiRequestException::class);
        $this->expectExceptionMessage('Invalid API key');

        app(SellerApiClient::class)->ticketDropdown(9020);
    }

    public function test_eticket_resolves_to_e_tickets_ticket_type_name_in_dropdown(): void
    {
        Cache::forget('seller-api:ticket-dropdown:45');
        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(45)->andReturn([
            'result' => [
                'ticket_type' => [
                    ['id' => 1, 'ticket_type_name' => 'Season Card'],
                    ['id' => 2, 'ticket_type_name' => 'E-Tickets'],
                    ['id' => 3, 'ticket_type_name' => 'Paper Tickets'],
                ],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [['id' => 4, 'category_name' => 'Longside Upper Tier']],
            ],
        ]);
        $client->shouldReceive('sellerId')->once()->andReturn(77);

        $mapping = new EventMapping(['m_id' => 45]);
        $mapping->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));

        $payload = $this->transformer($client)->transform(
            new Xs2Ticket([
                'external_ticket_id' => 'xs2-eticket-type',
                'ticket_type' => 'eticket',
                'ticket_status' => 'available',
                'stock' => 2,
                'category_name' => 'Longside Upper Tier',
                'currency_code' => 'EUR',
                'net_rate' => 5000,
                'flags' => [],
                'options' => [],
            ]),
            $mapping,
        );

        $this->assertSame(2, $payload['ticket_type']);
    }

    public function test_etickets_and_e_tickets_xs2_aliases_resolve_to_e_tickets(): void
    {
        foreach (['etickets', 'e-tickets', 'e_tickets'] as $xs2Type) {
            Cache::forget('seller-api:ticket-dropdown:45');
            $client = Mockery::mock(SellerApiClient::class);
            $client->shouldReceive('ticketDropdown')->once()->with(45)->andReturn([
                'result' => [
                    'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                    'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                    'category' => [['id' => 4, 'category_name' => 'Longside Upper Tier']],
                ],
            ]);
            $client->shouldReceive('sellerId')->once()->andReturn(77);

            $mapping = new EventMapping(['m_id' => 45]);
            $mapping->setRelation('xs2Event', new Xs2Event([
                'event_status' => 'notstarted',
                'date_start_local' => '2999-01-01 12:00:00',
            ]));

            $payload = $this->transformer($client)->transform(
                new Xs2Ticket([
                    'external_ticket_id' => 'xs2-'.$xs2Type,
                    'ticket_type' => $xs2Type,
                    'ticket_status' => 'available',
                    'stock' => 2,
                    'category_name' => 'Longside Upper Tier',
                    'currency_code' => 'EUR',
                    'net_rate' => 5000,
                    'flags' => [],
                    'options' => [],
                ]),
                $mapping,
            );

            $this->assertSame(2, $payload['ticket_type'], "Failed for XS2 ticket type {$xs2Type}");
        }
    }

    public function test_transformer_matches_e_ticket_singular_name_in_dropdown(): void
    {
        Cache::forget('seller-api:ticket-dropdown:45');
        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(45)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Ticket']],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [['id' => 4, 'category_name' => 'Longside Upper Tier']],
            ],
        ]);
        $client->shouldReceive('sellerId')->once()->andReturn(77);

        $mapping = new EventMapping(['m_id' => 45]);
        $mapping->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));

        $payload = $this->transformer($client)->transform(
            new Xs2Ticket([
                'external_ticket_id' => 'xs2-eticket-singular',
                'ticket_type' => 'etickets',
                'ticket_status' => 'available',
                'stock' => 2,
                'category_name' => 'Longside Upper Tier',
                'currency_code' => 'EUR',
                'net_rate' => 5000,
                'flags' => [],
                'options' => [],
            ]),
            $mapping,
        );

        $this->assertSame(2, $payload['ticket_type']);
    }

    public function test_create_listing_debug_payload_redacts_api_key_header(): void
    {
        Http::fake([
            'https://seller.test/api/ticket/create' => Http::response(['ticket_id' => 555]),
        ]);

        config()->set('services.seller_api.base_url', 'https://seller.test');
        config()->set('services.seller_api.listing_base_url', 'https://seller.test');
        config()->set('services.seller_api.api_key', 'super-secret-seller-key');
        config()->set('services.seller_api.api_key_header', 'apiKey');
        config()->set('services.seller_api.create_listing_endpoint', '/api/ticket/create');
        config()->set('services.seller_api.seller_id', 77);

        $recorder = app(SellerApiDebugRecorder::class);
        $recorder->enable();

        app(SellerApiClient::class)->createListing(['match_id' => 45, 'quantity' => 1], 'ref-1');

        $interactions = $recorder->flush();
        $this->assertCount(1, $interactions);
        $this->assertSame('create_listing', $interactions[0]['operation']);
        $this->assertStringContainsString('*', (string) data_get($interactions[0], 'request_headers.apiKey'));
        $this->assertStringNotContainsString('super-secret-seller-key', json_encode($interactions[0]));
    }

    public function test_transformer_builds_a_seller_contract_listing_payload(): void
    {
        Cache::forget('seller-api:ticket-dropdown:45');
        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(45)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'In Pairs']],
                'category' => [['id' => 4, 'category_name' => 'Longside Upper Tier']],
            ],
        ]);
        $client->shouldReceive('sellerId')->once()->andReturn(77);

        $mapping = new EventMapping(['m_id' => 45]);
        $mapping->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));

        $payload = $this->transformer($client)->transform(
            new Xs2Ticket([
                'external_ticket_id' => 'xs2-ticket-1',
                'ticket_type' => 'eticket',
                'ticket_status' => 'available',
                'stock' => 4,
                'category_name' => 'Longside Upper Tier',
                'currency_code' => 'EUR',
                'net_rate' => 10000,
                'flags' => ['pairs_only'],
                'options' => [],
            ]),
            $mapping,
            $this->mappedCategoryState(),
        );

        $this->assertSame([
            'seller_reference' => 'XS2-xs2-ticket-1',
            'match_id' => 45,
            'ticket_type' => 2,
            'quantity' => 4,
            'ticket_category' => 4,
            'ticket_block' => '',
            'ticket_row' => '',
            'home_town' => 0,
            'price_type' => 'EUR',
            'price' => '100.00',
            'ticket_details' => '',
            'split_type' => 3,
            'facevalue' => '100.00',
            'seller_id' => 77,
            'status' => '1',
        ], $payload);
    }

    public function test_transformer_fallback_payload_matches_mapped_payload_except_category_field(): void
    {
        Cache::forget('seller-api:ticket-dropdown:45');
        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(45)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'In Pairs']],
                'category' => [['id' => 4, 'category_name' => 'Longside Upper Tier']],
            ],
        ]);
        $client->shouldReceive('sellerId')->twice()->andReturn(77);

        $mapping = new EventMapping(['m_id' => 45]);
        $mapping->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));
        $ticket = new Xs2Ticket([
            'external_ticket_id' => 'xs2-ticket-1',
            'ticket_type' => 'eticket',
            'ticket_status' => 'available',
            'stock' => 4,
            'category_name' => 'Longside Upper Tier',
            'currency_code' => 'EUR',
            'net_rate' => 10000,
            'flags' => ['pairs_only'],
            'options' => [],
        ]);

        $transformer = $this->transformer($client);
        $mappedPayload = $transformer->transform($ticket, $mapping, $this->mappedCategoryState());
        $fallbackPayload = $transformer->transform($ticket, $mapping, $this->fallbackCategoryState());

        $this->assertSame(4, $mappedPayload['ticket_category']);
        $this->assertArrayNotHasKey('category_name', $mappedPayload);
        $this->assertSame('Longside Upper Tier', $fallbackPayload['category_name']);
        $this->assertArrayNotHasKey('ticket_category', $fallbackPayload);

        $mappedComparable = $mappedPayload;
        unset($mappedComparable['ticket_category']);
        $fallbackComparable = $fallbackPayload;
        unset($fallbackComparable['category_name']);

        ksort($mappedComparable);
        ksort($fallbackComparable);
        $this->assertSame($mappedComparable, $fallbackComparable);
    }

    #[DataProvider('unsellableEvents')]
    public function test_transformer_deactivates_listings_for_unsellable_events(array $eventAttributes): void
    {
        Cache::forget('seller-api:ticket-dropdown:45');
        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(45)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [['id' => 4, 'category_name' => 'Longside Upper Tier']],
            ],
        ]);
        $client->shouldReceive('sellerId')->once()->andReturn(77);
        $mapping = new EventMapping(['m_id' => 45]);
        $mapping->setRelation('xs2Event', new Xs2Event($eventAttributes));

        $payload = $this->transformer($client)->transform(
            new Xs2Ticket([
                'external_ticket_id' => 'xs2-unsellable-ticket',
                'ticket_type' => 'eticket',
                'ticket_status' => 'available',
                'stock' => 4,
                'category_name' => 'Longside Upper Tier',
                'currency_code' => 'EUR',
                'net_rate' => 10000,
                'flags' => [],
                'options' => [],
            ]),
            $mapping,
        );

        $this->assertSame(0, $payload['quantity']);
        $this->assertSame('0', $payload['status']);
    }

    public function test_transformer_maps_net_rate_to_seller_price_in_major_units(): void
    {
        Cache::forget('seller-api:ticket-dropdown:9300');
        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(9300)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [['id' => 4, 'category_name' => 'Longside Upper Tier']],
            ],
        ]);
        $client->shouldReceive('sellerId')->once()->andReturn(77);

        $mapping = new EventMapping(['m_id' => 9300]);
        $mapping->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));

        $payload = $this->transformer($client)->transform(
            new Xs2Ticket([
                'external_ticket_id' => 'xs2-net-rate-118',
                'ticket_type' => 'eticket',
                'ticket_status' => 'available',
                'stock' => 8,
                'category_name' => 'Longside Upper Tier',
                'currency_code' => 'EUR',
                'net_rate' => 11800,
                'face_value' => 11800,
                'flags' => [],
                'options' => [],
            ]),
            $mapping,
        );

        $this->assertSame('118.00', $payload['price']);
        $this->assertSame('118.00', $payload['facevalue']);
        $this->assertSame('EUR', $payload['price_type']);
        $this->assertSame(8, $payload['quantity']);
        $this->assertSame(2, $payload['ticket_type']);
    }

    public function test_transformer_maps_net_rate_to_seller_price_in_minor_units_when_configured(): void
    {
        config()->set('services.seller_api.price_uses_minor_units', true);

        Cache::forget('seller-api:ticket-dropdown:9300');
        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(9300)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [['id' => 4, 'category_name' => 'Longside Upper Tier']],
            ],
        ]);
        $client->shouldReceive('sellerId')->once()->andReturn(77);

        $mapping = new EventMapping(['m_id' => 9300]);
        $mapping->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));

        $payload = $this->transformer($client)->transform(
            new Xs2Ticket([
                'external_ticket_id' => 'xs2-net-rate-102',
                'ticket_type' => 'eticket',
                'ticket_status' => 'available',
                'stock' => 8,
                'category_name' => 'Longside Upper Tier',
                'currency_code' => 'EUR',
                'net_rate' => 10200,
                'face_value' => 10200,
                'flags' => [],
                'options' => [],
            ]),
            $mapping,
        );

        $this->assertSame(10200, $payload['price']);
        $this->assertSame(10200, $payload['facevalue']);
    }

    public function test_transformer_uses_face_value_for_facevalue_when_it_differs_from_net_rate(): void
    {
        Cache::forget('seller-api:ticket-dropdown:45');
        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(45)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [['id' => 4, 'category_name' => 'Longside Upper Tier']],
            ],
        ]);
        $client->shouldReceive('sellerId')->once()->andReturn(77);

        $mapping = new EventMapping(['m_id' => 45]);
        $mapping->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));

        $payload = $this->transformer($client)->transform(
            new Xs2Ticket([
                'external_ticket_id' => 'xs2-face-vs-net',
                'ticket_type' => 'eticket',
                'ticket_status' => 'available',
                'stock' => 2,
                'category_name' => 'Longside Upper Tier',
                'currency_code' => 'EUR',
                'net_rate' => 10200,
                'face_value' => 15000,
                'flags' => [],
                'options' => [],
            ]),
            $mapping,
        );

        $this->assertSame('102.00', $payload['price']);
        $this->assertSame('150.00', $payload['facevalue']);
    }

    public static function unsellableEvents(): array
    {
        return [
            'cancelled future event' => [[
                'event_status' => 'cancelled',
                'date_start_local' => '2999-01-01 12:00:00',
            ]],
            'closed future event' => [[
                'event_status' => 'closed',
                'date_start_local' => '2999-01-01 12:00:00',
            ]],
            'no-sale future event' => [[
                'event_status' => 'nosale',
                'date_start_local' => '2999-01-01 12:00:00',
            ]],
            'postponed future event' => [[
                'event_status' => 'postponed',
                'date_start_local' => '2999-01-01 12:00:00',
            ]],
            'sold-out future event' => [[
                'event_status' => 'soldout',
                'date_start_local' => '2999-01-01 12:00:00',
            ]],
            'past active event' => [[
                'event_status' => 'notstarted',
                'date_start_local' => '2000-01-01 12:00:00',
            ]],
        ];
    }

    private function transformer(SellerApiClient $client): Xs2SellerListingTransformer
    {
        $listingSales = Mockery::mock(ListingSalesService::class);
        $listingSales->shouldReceive('remainingQuantityForTicket')
            ->andReturnUsing(fn (Xs2Ticket $ticket): int => max(0, (int) ($ticket->stock ?? 0)));

        return new Xs2SellerListingTransformer($client, $listingSales);
    }

    private function mappedCategoryState(): Xs2TicketMappingState
    {
        $detail = new Xs2CategoryMappingDetail([
            'stadium_detail_id' => 1,
            'stadium_seat_id' => 4,
            'stadium_seat_name' => 'Longside Upper Tier',
        ]);
        $categoryMapping = new Xs2CategoryMapping(['status' => 'mapped']);
        $categoryMapping->setRelation('details', collect([$detail]));
        $state = new Xs2TicketMappingState(['mapping_status' => 'ready_to_publish']);
        $state->setRelation('categoryMapping', $categoryMapping);

        return $state;
    }

    private function fallbackCategoryState(): Xs2TicketMappingState
    {
        $categoryMapping = new Xs2CategoryMapping(['status' => 'pending_category_mapping']);
        $state = new Xs2TicketMappingState(['mapping_status' => 'pending_category_mapping']);
        $state->setRelation('categoryMapping', $categoryMapping);

        return $state;
    }
}
