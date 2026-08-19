<?php

namespace Tests\Feature;

use App\Models\ExternalListingMapping;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Services\Xs2\Xs2Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CheckoutGuestValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_endpoint_rejects_away_team_nationality(): void
    {
        $this->seedTicketWithFlag(['no_awayteam_nationality_allowed'], 'AUT');

        $client = Mockery::mock(Xs2Client::class);
        $client->shouldReceive('getTicket')->once()->andReturn($this->ticketPayload());
        $this->app->instance(Xs2Client::class, $client);

        $response = $this->postJson('/api/checkout/validate', [
            'seller_reference' => 'XS2-ticket-1',
            'quantity' => 2,
            'expected_price' => 10000,
            'expected_currency' => 'EUR',
            'guests' => [
                ['first_name' => 'John', 'last_name' => 'Doe', 'country_of_residence' => 'AUT'],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.reason_code', 'away_team_nationality_not_allowed');
    }

    /** @param list<string> $flags */
    private function seedTicketWithFlag(array $flags, ?string $awayCountry = null): void
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-1',
            'event_name' => 'Celtic vs Lask',
            'visitingteam_name' => 'Lask',
            'visitingteam_iso_country' => $awayCountry,
            'raw_payload' => [],
        ]);

        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-1',
            'external_event_id' => 'event-1',
            'ticket_status' => 'available',
            'stock' => 4,
            'min_order' => 1,
            'net_rate' => 10000,
            'currency_code' => 'EUR',
            'flags' => $flags,
            'options' => [],
            'sales_periods' => [],
            'raw_payload' => [],
            'sync_status' => 'pending',
        ]);

        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'seller_reference' => 'XS2-ticket-1',
            'status' => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function ticketPayload(): array
    {
        return [
            'ticket_id' => 'ticket-1',
            'event_id' => 'event-1',
            'ticket_status' => 'available',
            'stock' => 4,
            'min_order' => 1,
            'net_rate' => 10000,
            'currency_code' => 'EUR',
            'flags' => ['no_awayteam_nationality_allowed'],
            'options' => [],
            'sales_periods' => [],
        ];
    }
}
