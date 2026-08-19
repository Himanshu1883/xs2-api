<?php

namespace Tests\Feature;

use App\Exceptions\Integrations\Xs2RequestException;
use App\Models\ExternalListingMapping;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Services\Xs2\Xs2CheckoutValidationService;
use App\Services\Xs2\Xs2Client;
use App\Services\Xs2\Xs2GuestValidationService;
use App\Services\Xs2\Xs2TicketNormalizer;
use App\Services\Xs2\Xs2TicketRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class Xs2CheckoutValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_a_current_available_ticket(): void
    {
        $result = $this->service($this->payload())->validate('XS2-ticket-1', 2, 10000, 'EUR');

        $this->assertTrue($result['valid']);
        $this->assertSame('valid', $result['reason_code']);
    }

    public function test_it_rejects_unavailable_and_insufficient_ticket_inventory(): void
    {
        $unavailable = $this->service($this->payload(['ticket_status' => 'unavailable']))->validate('XS2-ticket-1', 1, 10000, 'EUR');
        $insufficient = $this->service($this->payload(['stock' => 1]))->validate('XS2-ticket-1', 2, 10000, 'EUR');

        $this->assertSame('ticket_unavailable', $unavailable['reason_code']);
        $this->assertSame('insufficient_stock', $insufficient['reason_code']);
    }

    public function test_it_rejects_changed_price_currency_and_invalid_quantity(): void
    {
        $price = $this->service($this->payload())->validate('XS2-ticket-1', 2, 9999, 'EUR');
        $currency = $this->service($this->payload())->validate('XS2-ticket-1', 2, 10000, 'USD');
        $quantity = $this->service($this->payload(['flags' => ['pairs_only']]))->validate('XS2-ticket-1', 3, 10000, 'EUR');

        $this->assertSame('price_changed', $price['reason_code']);
        $this->assertSame('currency_changed', $currency['reason_code']);
        $this->assertSame('invalid_quantity', $quantity['reason_code']);
    }

    public function test_it_accepts_a_package_rate_ticket_as_one_package_purchase(): void
    {
        $package = $this->service($this->payload([
            'flags' => ['package_rate'],
            'package_quantity' => 4,
            'net_rate' => 60000,
            'stock' => 3,
        ]))->validate('XS2-ticket-1', 1, 60000, 'EUR');

        $this->assertTrue($package['valid']);
        $this->assertSame('valid', $package['reason_code']);
    }

    public function test_it_rejects_expired_and_temporarily_unavailable_tickets(): void
    {
        $expired = $this->service($this->payload(['ticket_validuntil' => now()->subMinute()->toIso8601String()]))->validate('XS2-ticket-1', 2, 10000, 'EUR');
        $client = Mockery::mock(Xs2Client::class);
        $client->shouldReceive('getTicket')->andThrow(new Xs2RequestException('temporary failure'));
        $temporary = $this->serviceWithClient($client)->validate('XS2-ticket-1', 2, 10000, 'EUR');

        $this->assertSame('ticket_expired', $expired['reason_code']);
        $this->assertSame('xs2_temporarily_unavailable', $temporary['reason_code']);
    }

    /** @param array<string,mixed> $payload */
    private function service(array $payload): Xs2CheckoutValidationService
    {
        $client = Mockery::mock(Xs2Client::class);
        $client->shouldReceive('getTicket')->once()->with('ticket-1')->andReturn($payload);

        return $this->serviceWithClient($client);
    }

    private function serviceWithClient(Xs2Client $client): Xs2CheckoutValidationService
    {
        $event = Xs2Event::query()->firstOrCreate([
            'external_event_id' => 'event-1',
        ], [
            'event_name' => 'Fixture',
            'raw_payload' => [],
        ]);
        $ticket = Xs2Ticket::query()->firstOrCreate([
            'external_ticket_id' => 'ticket-1',
        ], [
            'xs2_event_id' => $event->id,
            'external_event_id' => 'event-1',
            'ticket_status' => 'available',
            'stock' => 4,
            'min_order' => 1,
            'net_rate' => 10000,
            'currency_code' => 'EUR',
            'flags' => [],
            'options' => [],
            'sales_periods' => [],
            'raw_payload' => [],
            'sync_status' => 'pending',
        ]);
        ExternalListingMapping::query()->firstOrCreate([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
        ], [
            'seller_reference' => 'XS2-ticket-1',
            'status' => 'active',
        ]);

        return new Xs2CheckoutValidationService(
            $client,
            app(Xs2TicketNormalizer::class),
            app(Xs2TicketRuleService::class),
            app(Xs2GuestValidationService::class),
        );
    }

    /** @param array<string,mixed> $override @return array<string,mixed> */
    private function payload(array $override = []): array
    {
        return [
            'ticket_id' => 'ticket-1',
            'event_id' => 'event-1',
            'ticket_status' => 'available',
            'stock' => 4,
            'min_order' => 1,
            'net_rate' => 10000,
            'currency_code' => 'EUR',
            'flags' => [],
            'options' => [],
            'sales_periods' => [],
            ...$override,
        ];
    }
}
