<?php

namespace Tests\Unit;

use App\Models\Xs2Ticket;
use App\Services\Xs2\Xs2TicketRuleService;
use Tests\TestCase;

class Xs2TicketRuleServiceTest extends TestCase
{
    public function test_it_respects_minimum_order_and_stock(): void
    {
        $ticket = new Xs2Ticket(['stock' => 5, 'min_order' => 2, 'flags' => []]);

        $this->assertSame([2, 3, 4, 5], app(Xs2TicketRuleService::class)->allowedQuantities($ticket));
    }

    public function test_pairs_only_allows_even_quantities_only(): void
    {
        $ticket = new Xs2Ticket(['stock' => 6, 'min_order' => 1, 'flags' => ['pairs_only']]);

        $this->assertSame([2, 4, 6], app(Xs2TicketRuleService::class)->allowedQuantities($ticket));
    }

    public function test_no_max_minus_one_does_not_leave_one_ticket(): void
    {
        $ticket = new Xs2Ticket(['stock' => 5, 'min_order' => 1, 'flags' => ['no_max_minus_1']]);

        $this->assertSame([1, 2, 3, 5], app(Xs2TicketRuleService::class)->allowedQuantities($ticket));
    }

    public function test_package_rates_allow_package_counts_up_to_stock(): void
    {
        $ticket = new Xs2Ticket(['stock' => 6, 'min_order' => 1, 'flags' => ['package_rate']]);

        $this->assertSame([1, 2, 3, 4, 5, 6], app(Xs2TicketRuleService::class)->allowedQuantities($ticket));
    }
}
