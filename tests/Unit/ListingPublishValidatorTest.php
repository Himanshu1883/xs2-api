<?php

namespace Tests\Unit;

use App\Exceptions\Integrations\ListingTransformationException;
use App\Models\EventMapping;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\Xs2\ListingPublishValidator;
use Tests\TestCase;

class ListingPublishValidatorTest extends TestCase
{
    private ListingPublishValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = app(ListingPublishValidator::class);
    }

    public function test_validate_payload_rejects_missing_ticket_category(): void
    {
        $this->expectException(ListingTransformationException::class);
        $this->expectExceptionMessage('ticket_category');

        $this->validator->validatePayload([
            'match_id' => 45,
            'seller_reference' => 'XS2-ref-1',
            'ticket_type' => 2,
            'split_type' => 3,
            'seller_id' => 77,
            'quantity' => 2,
            'price' => '100.00',
        ]);
    }

    public function test_validate_payload_accepts_integer_ticket_category(): void
    {
        $this->validator->validatePayload([
            'match_id' => 45,
            'seller_reference' => 'XS2-ref-1',
            'ticket_category' => 4,
            'ticket_type' => 2,
            'split_type' => 3,
            'seller_id' => 77,
            'quantity' => 2,
            'price' => '100.00',
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_validate_for_publish_rejects_missing_match_id(): void
    {
        $ticket = new Xs2Ticket([
            'category_name' => 'Longside',
            'currency_code' => 'EUR',
            'net_rate' => 10000,
        ]);
        $ticket->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));
        $mapping = new EventMapping(['status' => 'mapped']);

        $this->expectException(ListingTransformationException::class);
        $this->expectExceptionMessage('match_id');

        $this->validator->validateForPublish($ticket, $mapping);
    }

    public function test_validate_for_publish_rejects_empty_category_name(): void
    {
        $ticket = new Xs2Ticket([
            'category_name' => '',
            'currency_code' => 'EUR',
            'net_rate' => 10000,
        ]);
        $ticket->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));
        $mapping = new EventMapping(['m_id' => 45, 'status' => 'mapped']);

        $this->expectException(ListingTransformationException::class);
        $this->expectExceptionMessage('XS2 ticket category');

        $this->validator->validateForPublish($ticket, $mapping, new Xs2TicketMappingState([
            'mapping_status' => 'ready_to_publish',
        ]));
    }
}
