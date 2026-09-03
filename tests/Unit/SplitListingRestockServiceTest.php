<?php

namespace Tests\Unit;

use App\Jobs\PublishSplitListings;
use App\Models\Xs2Ticket;
use App\Services\SplitListings\SplitListingRestockService;
use App\Services\SplitListings\SplitListingService;
use App\Services\Xs2\ListingPublishRuleService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SplitListingRestockServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_is_restock_from_zero_only_when_previous_stock_was_zero(): void
    {
        $service = app(SplitListingRestockService::class);

        $this->assertTrue($service->isRestockFromZero(0, 5));
        $this->assertFalse($service->isRestockFromZero(2, 5));
        $this->assertFalse($service->isRestockFromZero(0, 0));
    }

    public function test_can_republish_when_stored_split_config_exists_and_not_on_sb(): void
    {
        $service = new SplitListingRestockService(
            Mockery::mock(ListingPublishRuleService::class),
            Mockery::mock(SplitListingService::class),
        );

        $ticket = $this->ticketForRepublishCheck(hasLiveSplits: false, hasMasterListing: false);

        $this->assertTrue($service->canRepublishAfterRestock($ticket));
        $this->assertSame(2, $service->resolveSplitConfig($ticket)['split_quantity']);
    }

    public function test_queue_republish_dispatches_publish_split_listings_job(): void
    {
        Queue::fake();

        $splitListings = Mockery::mock(SplitListingService::class);
        $splitListings->shouldReceive('validateConfiguration')
            ->once()
            ->andReturn(['valid' => true, 'errors' => []]);

        $service = new SplitListingRestockService(
            Mockery::mock(ListingPublishRuleService::class),
            $splitListings,
        );

        $ticket = new Xs2Ticket([
            'id' => 42,
            'stock' => 8,
            'split_enabled' => false,
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 5,
            'net_rate' => 10000,
        ]);

        $this->assertTrue($service->queueRepublish($ticket));

        Queue::assertPushed(PublishSplitListings::class, fn (PublishSplitListings $job): bool => $job->ticketId === 42
            && $job->config['split_quantity'] === 2);
    }

    public function test_cannot_republish_when_active_splits_still_have_sb_ids(): void
    {
        $service = new SplitListingRestockService(
            Mockery::mock(ListingPublishRuleService::class),
            Mockery::mock(SplitListingService::class),
        );

        $ticket = $this->ticketForRepublishCheck(hasLiveSplits: true);

        $this->assertFalse($service->canRepublishAfterRestock($ticket));
    }

    public function test_can_republish_when_split_enabled_but_no_live_sb_ids(): void
    {
        $service = new SplitListingRestockService(
            Mockery::mock(ListingPublishRuleService::class),
            Mockery::mock(SplitListingService::class),
        );

        $ticket = $this->ticketForRepublishCheck(
            hasLiveSplits: false,
            hasMasterListing: false,
            attrs: ['split_enabled' => true],
        );

        $this->assertTrue($service->canRepublishAfterRestock($ticket));
    }

    /** @param  array<string, mixed>  $attrs */
    private function ticketForRepublishCheck(
        bool $hasLiveSplits,
        bool $hasMasterListing = true,
        array $attrs = [],
    ): Xs2Ticket {
        $ticket = Mockery::mock(Xs2Ticket::class)->makePartial();
        $ticket->forceFill(array_merge([
            'id' => $hasMasterListing ? 99 : null,
            'stock' => 8,
            'split_enabled' => false,
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 5,
        ], $attrs));

        $splitsQuery = Mockery::mock(HasMany::class);
        $splitsQuery->shouldReceive('where')->with('status', 'active')->andReturnSelf();
        $splitsQuery->shouldReceive('whereNotNull')->with('seatsbroker_listing_id')->andReturnSelf();
        $splitsQuery->shouldReceive('exists')->andReturn($hasLiveSplits);
        $ticket->shouldReceive('listingSplits')->andReturn($splitsQuery);

        return $ticket;
    }
}
