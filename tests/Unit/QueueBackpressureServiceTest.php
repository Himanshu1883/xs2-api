<?php

namespace Tests\Unit;

use App\Services\Admin\QueueBackpressureService;
use App\Services\Admin\QueueProfileService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class QueueBackpressureServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createJobsTable();
        config()->set('services.seller_api.queue', 'seller-api');
    }

    public function test_global_backpressure_excludes_seller_api_queue(): void
    {
        $this->seedPendingJobs('xs2-sync', 200);
        $this->seedPendingJobs('seller-api', 40);

        $service = $this->serviceWithProfile(maxPending: 150);

        $global = $service->status();
        $sellerApi = $service->status('seller-api');

        $this->assertSame(200, $global['pending_jobs']);
        $this->assertTrue($global['overloaded']);
        $this->assertSame(40, $sellerApi['pending_jobs']);
        $this->assertFalse($sellerApi['overloaded']);
    }

    public function test_publish_cron_scope_is_not_blocked_when_only_xs2_sync_is_overloaded(): void
    {
        $this->seedPendingJobs('xs2-sync', 248);
        $this->seedPendingJobs('seller-api', 0);

        $service = $this->serviceWithProfile(maxPending: 150);

        $this->assertTrue($service->shouldSkipScheduledDispatch());
        $this->assertFalse($service->shouldSkipScheduledDispatch('seller-api'));
        $this->assertGreaterThan(0, $service->remainingDispatchBudget('seller-api'));
    }

    public function test_publish_cron_scope_is_blocked_when_seller_api_queue_is_overloaded(): void
    {
        $this->seedPendingJobs('xs2-sync', 0);
        $this->seedPendingJobs('seller-api', 160);

        $service = $this->serviceWithProfile(maxPending: 150);

        $this->assertFalse($service->shouldSkipScheduledDispatch());
        $this->assertTrue($service->shouldSkipScheduledDispatch('seller-api'));
        $this->assertSame(0, $service->remainingDispatchBudget('seller-api'));
    }

    public function test_snapshot_includes_publish_queue_status(): void
    {
        $this->seedPendingJobs('xs2-sync', 180);
        $this->seedPendingJobs('seller-api', 5);

        $snapshot = $this->serviceWithProfile(maxPending: 150)->snapshot();

        $this->assertTrue($snapshot['overloaded']);
        $this->assertFalse($snapshot['publish_overloaded']);
        $this->assertSame('seller-api', $snapshot['publish_queue']);
        $this->assertSame(5, $snapshot['seller_api']['pending_jobs']);
    }

    private function serviceWithProfile(int $maxPending, int $maxDispatch = 30): QueueBackpressureService
    {
        $profiles = Mockery::mock(QueueProfileService::class);
        $profiles->shouldReceive('activeProfile')->andReturn([
            'max_pending_jobs' => $maxPending,
            'max_dispatch_per_run' => $maxDispatch,
        ]);
        $profiles->shouldReceive('activeProfileId')->andReturn('balanced');

        return new QueueBackpressureService($profiles);
    }

    private function seedPendingJobs(string $queue, int $count): void
    {
        $now = now()->getTimestamp();
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $rows[] = [
                'queue' => $queue,
                'payload' => json_encode(['job' => 'test']),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now,
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('jobs')->insert($chunk);
        }
    }

    private function createJobsTable(): void
    {
        Schema::dropIfExists('jobs');
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }
}
