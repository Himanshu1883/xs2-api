<?php

namespace Tests\Feature;

use App\Models\Xs2Event;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportXs2SandboxEventCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();

        config()->set('xs2.sandbox.api_url', 'https://sandbox.xs2.test');
        config()->set('xs2.sandbox.api_key', 'sandbox-key');
        config()->set('xs2.sandbox.api_key_header', 'X-Api-Key');
        config()->set('xs2.sandbox.test_event_id', 'barcelona-sandbox-event_gnr');
        config()->set('xs2.event_detail_endpoint', '/v1/events/{event_id}');
        config()->set('xs2.mapping.event_auto_map_threshold', 100);
        config()->set('xs2.mapping.event_pending_threshold', 65);
    }

    public function test_command_imports_configured_sandbox_event_into_xs2_events(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/events/barcelona-sandbox-event_gnr' => Http::response([
                'event' => [
                    'event_id' => 'barcelona-sandbox-event_gnr',
                    'event_name' => 'FC Barcelona vs Athletic Bilbao',
                    'date_start' => '2026-08-27T21:00:00',
                    'date_stop' => '2026-08-27T23:00:00',
                    'event_status' => 'notstarted',
                    'tournament_name' => 'La Liga',
                    'venue_name' => 'Camp Nou',
                    'city' => 'Barcelona',
                    'sport_type' => 'soccer',
                    'hometeam_name' => 'FC Barcelona',
                    'visiting_name' => 'Athletic Bilbao',
                ],
            ]),
        ]);

        $this->artisan('xs2:import-sandbox-event')
            ->assertSuccessful()
            ->expectsOutputToContain('FC Barcelona vs Athletic Bilbao');

        $event = Xs2Event::query()->with('mapping')->where('external_event_id', 'barcelona-sandbox-event_gnr')->first();

        $this->assertNotNull($event);
        $this->assertSame('FC Barcelona vs Athletic Bilbao', $event->event_name);
        $this->assertNotNull($event->mapping);
        $this->assertSame('pending', $event->mapping->status);
    }

    public function test_command_skips_existing_event_without_force(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/events/barcelona-sandbox-event_gnr' => Http::response([
                'event' => [
                    'event_id' => 'barcelona-sandbox-event_gnr',
                    'event_name' => 'FC Barcelona vs Athletic Bilbao',
                    'date_start' => '2026-08-27T21:00:00',
                    'date_stop' => '2026-08-27T23:00:00',
                    'event_status' => 'notstarted',
                    'sport_type' => 'soccer',
                    'hometeam_name' => 'FC Barcelona',
                    'visiting_name' => 'Athletic Bilbao',
                ],
            ]),
        ]);

        $this->artisan('xs2:import-sandbox-event')->assertSuccessful();
        Http::assertSentCount(1);

        $this->artisan('xs2:import-sandbox-event')
            ->assertSuccessful()
            ->expectsOutputToContain('Skipping');

        Http::assertSentCount(1);
    }
}
