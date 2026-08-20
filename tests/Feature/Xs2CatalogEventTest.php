<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Xs2Event;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Xs2CatalogEventTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();
        config()->set('services.xs2.base_url', 'https://xs2.test');
        config()->set('services.xs2.api_key', 'test-key');
        config()->set('services.xs2.api_key_header', 'X-Api-Key');
        config()->set('services.xs2.events_endpoint', '/v1/events');
        config()->set('services.xs2.event_detail_endpoint', '/v1/events/{event_id}');
        config()->set('services.xs2.rate_limit_pacing', false);
        config()->set('services.xs2.retry_times', 1);
        config()->set('services.xs2.sports', 'soccer');
    }

    public function test_admin_can_search_and_sync_single_xs2_catalog_event(): void
    {
        Http::fake([
            'https://xs2.test/v1/events/xs2-catalog-1' => Http::response([
                'event' => [
                    'event_id' => 'xs2-catalog-1',
                    'event_name' => 'Alpha FC vs Beta FC',
                    'date_start' => '2026-09-01T18:00:00',
                    'date_stop' => '2026-09-01T20:00:00',
                    'event_status' => 'notstarted',
                    'tournament_name' => 'Serie A',
                    'venue_name' => 'Stadium',
                    'city' => 'Milan',
                    'sport_type' => 'soccer',
                    'hometeam_name' => 'Alpha FC',
                    'visiting_name' => 'Beta FC',
                ],
            ]),
            'https://xs2.test/v1/events*' => Http::response([
                'events' => [[
                    'event_id' => 'xs2-catalog-1',
                    'event_name' => 'Alpha FC vs Beta FC',
                    'date_start' => '2026-09-01T18:00:00',
                    'date_stop' => '2026-09-01T20:00:00',
                    'event_status' => 'notstarted',
                    'tournament_name' => 'Serie A',
                    'venue_name' => 'Stadium',
                    'city' => 'Milan',
                    'sport_type' => 'soccer',
                    'hometeam_name' => 'Alpha FC',
                    'visiting_name' => 'Beta FC',
                ]],
                'pagination' => ['page' => 1, 'total_pages' => 1, 'total_size' => 1],
            ]),
        ]);

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/xs2/catalog/events/search?sport=soccer&tournament_name=Serie+A&search=Alpha')
            ->assertOk()
            ->assertJsonPath('data.0.external_event_id', 'xs2-catalog-1')
            ->assertJsonPath('data.0.already_synced', false)
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonPath('meta.sport', 'soccer');

        $this->withToken($token)
            ->postJson('/api/admin/xs2/catalog/events/sync', [
                'external_event_id' => 'xs2-catalog-1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.external_event_id', 'xs2-catalog-1')
            ->assertJsonPath('data.mapping_status', 'pending');

        $this->assertSame(1, Xs2Event::count());
    }

    public function test_admin_can_fetch_second_page_of_xs2_catalog_events(): void
    {
        $pageOneEvents = array_map(
            fn (int $index): array => [
                'event_id' => "xs2-catalog-{$index}",
                'event_name' => "Alpha FC vs Beta FC {$index}",
                'date_start' => '2026-09-01T18:00:00',
                'date_stop' => '2026-09-01T20:00:00',
                'event_status' => 'notstarted',
                'tournament_name' => 'Serie A',
                'venue_name' => 'Stadium',
                'city' => 'Milan',
                'sport_type' => 'soccer',
            ],
            range(1, 20),
        );

        Http::fake([
            'https://xs2.test/v1/events*page=1*' => Http::response([
                'events' => $pageOneEvents,
                'pagination' => ['page' => 1, 'next_page' => 2, 'total_size' => 25],
            ]),
            'https://xs2.test/v1/events*page=2*' => Http::response([
                'events' => [[
                    'event_id' => 'xs2-catalog-21',
                    'event_name' => 'Alpha FC vs Beta FC 21',
                    'date_start' => '2026-09-02T18:00:00',
                    'date_stop' => '2026-09-02T20:00:00',
                    'event_status' => 'notstarted',
                    'tournament_name' => 'Serie A',
                    'venue_name' => 'Stadium',
                    'city' => 'Milan',
                    'sport_type' => 'soccer',
                ]],
                'pagination' => ['page' => 2, 'total_pages' => 2, 'total_size' => 25],
            ]),
        ]);

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/xs2/catalog/events/search?sport=soccer&tournament_name=Serie+A&page=1&per_page=20')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonPath('meta.pagination.last_page', 2)
            ->assertJsonPath('meta.pagination.total', 25);

        $this->withToken($token)
            ->getJson('/api/admin/xs2/catalog/events/search?sport=soccer&tournament_name=Serie+A&page=2&per_page=20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_event_id', 'xs2-catalog-21')
            ->assertJsonPath('meta.pagination.current_page', 2)
            ->assertJsonPath('meta.pagination.last_page', 2)
            ->assertJsonPath('meta.pagination.total', 25);
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create(['user_type' => 6]);

        return $admin->createToken('xs2-catalog-event-test')->plainTextToken;
    }
}
