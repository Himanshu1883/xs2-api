<?php

namespace Tests\Feature;

use App\Models\EventMapping;
use App\Models\User;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Xs2PackageRateListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_ticket_resource_exposes_package_rate_fields(): void
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-package-1',
            'event_name' => 'Package Fixture',
            'raw_payload' => [],
        ]);
        $mapping = EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 100,
            'status' => 'mapped',
        ]);
        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-package-admin',
            'external_event_id' => 'event-package-1',
            'category_name' => 'Hospitality Package',
            'ticket_status' => 'available',
            'stock' => 3,
            'min_order' => 1,
            'net_rate' => 60000,
            'currency_code' => 'EUR',
            'flags' => ['package_rate'],
            'is_package_rate' => true,
            'package_quantity' => 4,
            'package_price' => 60000,
            'options' => [],
            'sales_periods' => [],
            'raw_payload' => [],
            'sync_status' => 'synced',
        ]);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $mapping->id,
            'mapping_status' => 'ready_to_publish',
        ]);

        $this->withToken($this->adminToken())
            ->getJson("/api/admin/xs2/events/{$mapping->id}/tickets")
            ->assertOk()
            ->assertJsonPath('data.0.is_package_rate', true)
            ->assertJsonPath('data.0.package_quantity', 4)
            ->assertJsonPath('data.0.package_price', 600)
            ->assertJsonPath('data.0.net_rate', 600);
    }

    private function adminToken(): string
    {
        return User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;
    }
}
