<?php

namespace Tests\Unit;

use App\Models\EventMapping;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\Xs2\ListingPublishReadinessService;
use App\Services\Xs2\ListingPublishValidator;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ListingPublishReadinessServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_assess_returns_not_ready_when_event_mapping_is_missing(): void
    {
        Schema::shouldReceive('hasTable')->with('xs2_ticket_mapping_states')->andReturn(false);

        $ticket = new Xs2Ticket(['category_name' => 'Longside', 'currency_code' => 'EUR', 'net_rate' => 10000]);
        $event = new Xs2Event(['event_status' => 'notstarted', 'date_start_local' => now()->addDay()]);
        $event->setRelation('mapping', null);
        $ticket->setRelation('xs2Event', $event);

        $service = new ListingPublishReadinessService(
            Mockery::mock(Xs2TicketMappingStatusService::class),
            Mockery::mock(ListingPublishValidator::class),
        );

        $result = $service->assess($ticket);

        $this->assertFalse($result['ready']);
        $this->assertStringContainsString('event mapping', strtolower((string) $result['error']));
    }

    public function test_assess_returns_ready_when_event_is_mapped_and_validation_succeeds(): void
    {
        Schema::shouldReceive('hasTable')->with('xs2_ticket_mapping_states')->andReturn(true);

        $event = new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => now()->addDay(),
        ]);
        $mapping = new EventMapping(['m_id' => 45, 'status' => 'mapped']);
        $event->setRelation('mapping', $mapping);

        $ticket = new Xs2Ticket([
            'category_name' => 'Longside',
            'currency_code' => 'EUR',
            'net_rate' => 10000,
        ]);
        $ticket->setRelation('xs2Event', $event);

        $mappingState = new Xs2TicketMappingState(['mapping_status' => 'ready_to_publish']);

        $mappingStatuses = Mockery::mock(Xs2TicketMappingStatusService::class);
        $mappingStatuses->shouldReceive('resolveIfStale')->once()->andReturn($mappingState);

        $validator = Mockery::mock(ListingPublishValidator::class);
        $validator->shouldReceive('validateForPublish')->once();

        $service = new ListingPublishReadinessService($mappingStatuses, $validator);

        $result = $service->assess($ticket);

        $this->assertTrue($result['ready']);
        $this->assertNull($result['error']);
    }

    public function test_assess_returns_ready_when_category_mapping_is_pending_and_xs2_name_is_present(): void
    {
        Schema::shouldReceive('hasTable')->with('xs2_ticket_mapping_states')->andReturn(true);

        $event = new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => now()->addDay(),
        ]);
        $mapping = new EventMapping(['m_id' => 45, 'status' => 'mapped']);
        $event->setRelation('mapping', $mapping);

        $ticket = new Xs2Ticket([
            'category_name' => 'Matchday Premium',
            'currency_code' => 'EUR',
            'net_rate' => 46500,
        ]);
        $ticket->setRelation('xs2Event', $event);

        $mappingState = new Xs2TicketMappingState(['mapping_status' => 'pending_category_mapping']);

        $mappingStatuses = Mockery::mock(Xs2TicketMappingStatusService::class);
        $mappingStatuses->shouldReceive('resolveIfStale')->once()->andReturn($mappingState);

        $validator = Mockery::mock(ListingPublishValidator::class);
        $validator->shouldReceive('validateForPublish')->once();

        $service = new ListingPublishReadinessService($mappingStatuses, $validator);

        $result = $service->assess($ticket);

        $this->assertTrue($result['ready']);
        $this->assertNull($result['error']);
    }

    public function test_assess_returns_not_ready_when_mapping_resolve_throws(): void
    {
        Schema::shouldReceive('hasTable')->with('xs2_ticket_mapping_states')->andReturn(true);

        $event = new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => now()->addDay(),
        ]);
        $mapping = new EventMapping(['m_id' => 45, 'status' => 'mapped']);
        $event->setRelation('mapping', $mapping);

        $ticket = new Xs2Ticket([
            'category_name' => 'Longside',
            'currency_code' => 'EUR',
            'net_rate' => 10000,
        ]);
        $ticket->setRelation('xs2Event', $event);

        $mappingStatuses = Mockery::mock(Xs2TicketMappingStatusService::class);
        $mappingStatuses->shouldReceive('resolveIfStale')
            ->once()
            ->andThrow(new \TypeError('loadMissing() on null'));

        $service = new ListingPublishReadinessService(
            $mappingStatuses,
            Mockery::mock(ListingPublishValidator::class),
        );

        $result = $service->assess($ticket);

        $this->assertFalse($result['ready']);
        $this->assertSame('loadMissing() on null', $result['error']);
    }
}
