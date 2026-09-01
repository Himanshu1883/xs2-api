<?php

namespace Tests\Unit;

use App\Exceptions\Integrations\ListingTransformationException;
use App\Models\EventMapping;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\Xs2\ListingPublishReadinessService;
use App\Services\Xs2\ListingPublishValidator;
use App\Services\Xs2\Xs2SellerListingTransformer;
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
            Mockery::mock(Xs2SellerListingTransformer::class),
        );

        $result = $service->assess($ticket);

        $this->assertFalse($result['ready']);
        $this->assertStringContainsString('event mapping', strtolower((string) $result['error']));
    }

    public function test_assess_returns_ready_when_validation_and_transform_succeed(): void
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
        $payload = [
            'match_id' => 45,
            'seller_reference' => 'XS2-ref',
            'category_name' => 'Longside',
            'ticket_type' => 2,
            'split_type' => 3,
            'seller_id' => 77,
            'quantity' => 2,
            'price' => '100.00',
        ];

        $mappingStatuses = Mockery::mock(Xs2TicketMappingStatusService::class);
        $mappingStatuses->shouldReceive('resolveIfStale')->once()->andReturn($mappingState);

        $validator = Mockery::mock(ListingPublishValidator::class);
        $validator->shouldReceive('validateForPublish')->once();
        $validator->shouldReceive('validatePayload')->once()->with($payload);

        $transformer = Mockery::mock(Xs2SellerListingTransformer::class);
        $transformer->shouldReceive('transform')->once()->andReturn($payload);

        $service = new ListingPublishReadinessService($mappingStatuses, $validator, $transformer);

        $result = $service->assess($ticket);

        $this->assertTrue($result['ready']);
        $this->assertNull($result['error']);
    }

    public function test_assess_returns_not_ready_when_transform_fails(): void
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

        $transformer = Mockery::mock(Xs2SellerListingTransformer::class);
        $transformer->shouldReceive('transform')
            ->once()
            ->andThrow(new ListingTransformationException('Category does not match SB dropdown.'));

        $service = new ListingPublishReadinessService($mappingStatuses, $validator, $transformer);

        $result = $service->assess($ticket);

        $this->assertFalse($result['ready']);
        $this->assertSame('Category does not match SB dropdown.', $result['error']);
    }
}
