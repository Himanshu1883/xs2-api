<?php

namespace Tests\Unit;

use App\Jobs\DisableSellerListing;
use App\Jobs\PushXs2TicketToSellerApi;
use App\Jobs\ResolvePendingXs2Listings;
use App\Jobs\SyncXs2EventInventory;
use Tests\TestCase;

class Xs2QueueSerializationTest extends TestCase
{
    public function test_inventory_jobs_are_queue_serializable(): void
    {
        $job = new SyncXs2EventInventory(42, 'full');
        $restored = unserialize(serialize($job));

        $this->assertInstanceOf(SyncXs2EventInventory::class, $restored);
        $this->assertSame(42, $restored->reference);
        $this->assertSame('full', $restored->mode);
    }

    public function test_pending_resolution_jobs_are_queue_serializable(): void
    {
        $job = new ResolvePendingXs2Listings('category', 55);
        $restored = unserialize(serialize($job));

        $this->assertInstanceOf(ResolvePendingXs2Listings::class, $restored);
        $this->assertSame('category', $restored->mappingType);
        $this->assertSame(55, $restored->mappingId);
    }

    public function test_publish_and_disable_jobs_share_a_per_ticket_operation_lock(): void
    {
        $push = new PushXs2TicketToSellerApi(77);
        $disable = new DisableSellerListing(77);
        $pushMiddleware = $push->middleware()[0];
        $disableMiddleware = $disable->middleware()[0];

        $this->assertSame(
            $pushMiddleware->getLockKey($push),
            $disableMiddleware->getLockKey($disable),
        );
        $this->assertStringContainsString('xs2-seller-listing:77', $pushMiddleware->getLockKey($push));
    }
}
