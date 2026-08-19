<?php

namespace Tests\Unit;

use App\Services\SellerApi\SellerApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SellerApiEventsFetchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.seller_api.base_url', 'https://externalapi.seatsbrokers.com');
        config()->set('services.seller_api.api_key', 'test-bearer-token');
        config()->set('services.seller_api.events_endpoint', '/api/events');
        config()->set('services.seller_api.catalog_per_page', 2);
    }

    public function test_fetch_all_events_paginates_with_bearer_token(): void
    {
        Http::fake([
            'https://externalapi.seatsbrokers.com/api/events*page=1*' => Http::response([
                'data' => [
                    ['event_id' => 'a', 'match_name' => 'Event A'],
                    ['event_id' => 'b', 'match_name' => 'Event B'],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 2],
            ]),
            'https://externalapi.seatsbrokers.com/api/events*page=2*' => Http::response([
                'data' => [
                    ['event_id' => 'c', 'match_name' => 'Event C'],
                ],
                'meta' => ['current_page' => 2, 'last_page' => 2],
            ]),
        ]);

        $events = app(SellerApiClient::class)->fetchAllEvents(2);

        $this->assertCount(3, $events);
        $this->assertSame('Event C', $events[2]['match_name']);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/events')
            && ($request->header('Authorization')[0] ?? '') === 'Bearer test-bearer-token');
    }

    public function test_fetch_all_venues_paginates_with_bearer_token(): void
    {
        config()->set('services.seller_api.venues_endpoint', '/api/venues');
        config()->set('seller-api.venues_endpoint', '/api/venues');

        Http::fake([
            'https://externalapi.seatsbrokers.com/api/venues*page=1*' => Http::response([
                'data' => [
                    ['s_id' => 1, 'name' => 'Venue A', 'blocks' => []],
                    ['s_id' => 2, 'name' => 'Venue B', 'blocks' => []],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 2],
            ]),
            'https://externalapi.seatsbrokers.com/api/venues*page=2*' => Http::response([
                'data' => [
                    ['s_id' => 3, 'name' => 'Venue C', 'blocks' => []],
                ],
                'meta' => ['current_page' => 2, 'last_page' => 2],
            ]),
        ]);

        $venues = app(SellerApiClient::class)->fetchAllVenues(2);

        $this->assertCount(3, $venues);
        $this->assertSame('Venue C', $venues[2]['name']);

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/venues')
            && ($request->header('Authorization')[0] ?? '') === 'Bearer test-bearer-token');
    }
}
