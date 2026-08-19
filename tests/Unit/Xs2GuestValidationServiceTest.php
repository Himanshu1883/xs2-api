<?php

namespace Tests\Unit;

use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Services\Xs2\Xs2AwayTeamContextService;
use App\Services\Xs2\Xs2GuestValidationService;
use App\Services\Xs2\Xs2TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class Xs2GuestValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_guest_with_away_team_nationality_when_flagged(): void
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-1',
            'event_name' => 'Celtic vs Lask',
            'visitingteam_id' => 'away-team',
            'visitingteam_name' => 'Lask',
            'visitingteam_iso_country' => 'AUT',
            'raw_payload' => [],
        ]);

        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-1',
            'external_event_id' => 'event-1',
            'ticket_status' => 'available',
            'stock' => 2,
            'min_order' => 1,
            'flags' => ['no_awayteam_nationality_allowed'],
            'options' => [],
            'sales_periods' => [],
            'raw_payload' => [],
            'sync_status' => 'pending',
        ]);

        $awayTeam = Mockery::mock(Xs2AwayTeamContextService::class);
        $awayTeam->shouldReceive('resolve')->andReturn([
            'team_name' => 'Lask',
            'iso_country' => 'AUT',
            'province' => null,
        ]);

        $service = new Xs2GuestValidationService($awayTeam, app(Xs2TextNormalizer::class));
        $result = $service->validateTicketGuests($ticket, [
            ['first_name' => 'John', 'last_name' => 'Doe', 'country_of_residence' => 'AUT'],
        ]);

        $this->assertFalse($result->valid);
        $this->assertSame('away_team_nationality_not_allowed', $result->reasonCode);
    }

    public function test_it_allows_guest_with_different_nationality(): void
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-1',
            'event_name' => 'Celtic vs Lask',
            'visitingteam_iso_country' => 'AUT',
            'raw_payload' => [],
        ]);

        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-1',
            'external_event_id' => 'event-1',
            'ticket_status' => 'available',
            'stock' => 2,
            'min_order' => 1,
            'flags' => ['no_awayteam_nationality_allowed'],
            'options' => [],
            'sales_periods' => [],
            'raw_payload' => [],
            'sync_status' => 'pending',
        ]);

        $awayTeam = Mockery::mock(Xs2AwayTeamContextService::class);
        $awayTeam->shouldReceive('resolve')->andReturn([
            'team_name' => 'Lask',
            'iso_country' => 'AUT',
            'province' => null,
        ]);

        $service = new Xs2GuestValidationService($awayTeam, app(Xs2TextNormalizer::class));
        $result = $service->validateTicketGuests($ticket, [
            ['first_name' => 'John', 'last_name' => 'Doe', 'country_of_residence' => 'GBR'],
        ]);

        $this->assertTrue($result->valid);
    }

    public function test_it_rejects_guest_with_away_team_province_when_flagged(): void
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-1',
            'event_name' => 'Home vs Away',
            'visitingteam_province' => 'Catalonia',
            'raw_payload' => [],
        ]);

        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-1',
            'external_event_id' => 'event-1',
            'ticket_status' => 'available',
            'stock' => 2,
            'min_order' => 1,
            'flags' => ['no_awayteam_province_allowed'],
            'options' => [],
            'sales_periods' => [],
            'raw_payload' => [],
            'sync_status' => 'pending',
        ]);

        $awayTeam = Mockery::mock(Xs2AwayTeamContextService::class);
        $awayTeam->shouldReceive('resolve')->andReturn([
            'team_name' => 'Away',
            'iso_country' => 'ESP',
            'province' => 'Catalonia',
        ]);

        $service = new Xs2GuestValidationService($awayTeam, app(Xs2TextNormalizer::class));
        $result = $service->validateTicketGuests($ticket, [
            ['first_name' => 'Jane', 'last_name' => 'Doe', 'province' => 'Catalonia'],
        ]);

        $this->assertFalse($result->valid);
        $this->assertSame('away_team_province_not_allowed', $result->reasonCode);
    }
}
