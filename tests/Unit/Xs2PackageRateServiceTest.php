<?php

namespace Tests\Unit;

use App\Services\Xs2\Xs2PackageRateService;
use App\Services\Xs2\Xs2TicketNormalizer;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class Xs2PackageRateServiceTest extends TestCase
{
    public function test_normal_ticket_has_no_package_fields(): void
    {
        $resolved = app(Xs2PackageRateService::class)->resolveFromPayload([
            'ticket_id' => 'ticket-1',
            'flags' => [],
            'net_rate' => 15000,
            'stock' => 4,
        ]);

        $this->assertFalse($resolved['is_package_rate']);
        $this->assertNull($resolved['package_quantity']);
        $this->assertNull($resolved['package_price']);
    }

    public function test_package_ticket_uses_explicit_package_quantity_and_price(): void
    {
        $resolved = app(Xs2PackageRateService::class)->resolveFromPayload([
            'ticket_id' => 'ticket-package-4',
            'flags' => ['package_rate'],
            'package_quantity' => 4,
            'net_rate' => 60000,
            'face_value' => 60000,
        ]);

        $this->assertTrue($resolved['is_package_rate']);
        $this->assertSame(4, $resolved['package_quantity']);
        $this->assertSame(60000, $resolved['package_price']);
    }

    public function test_package_ticket_can_use_min_order_when_above_one(): void
    {
        $resolved = app(Xs2PackageRateService::class)->resolveFromPayload([
            'ticket_id' => 'ticket-package-2',
            'flags' => ['package_rate'],
            'min_order' => 2,
            'net_rate' => 35000,
        ]);

        $this->assertTrue($resolved['is_package_rate']);
        $this->assertSame(2, $resolved['package_quantity']);
        $this->assertSame(35000, $resolved['package_price']);
    }

    public function test_missing_package_quantity_is_logged_and_left_null(): void
    {
        Log::shouldReceive('channel')->once()->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $resolved = app(Xs2PackageRateService::class)->resolveFromPayload([
            'ticket_id' => 'ticket-package-missing-qty',
            'event_id' => 'event-1',
            'flags' => ['package_rate'],
            'net_rate' => 60000,
        ]);

        $this->assertTrue($resolved['is_package_rate']);
        $this->assertNull($resolved['package_quantity']);
        $this->assertSame(60000, $resolved['package_price']);
    }

    public function test_normalizer_persists_package_fields(): void
    {
        $normalized = app(Xs2TicketNormalizer::class)->normalize([
            'ticket_id' => 'ticket-package-4',
            'event_id' => 'event-1',
            'flags' => ['package_rate'],
            'package_quantity' => 4,
            'net_rate' => 60000,
            'stock' => 3,
            'min_order' => 1,
        ]);

        $this->assertTrue($normalized['is_package_rate']);
        $this->assertSame(4, $normalized['package_quantity']);
        $this->assertSame(60000, $normalized['package_price']);
        $this->assertSame(60000, $normalized['net_rate']);
    }

    public function test_normalizer_keeps_normal_ticket_unchanged(): void
    {
        $normalized = app(Xs2TicketNormalizer::class)->normalize([
            'ticket_id' => 'ticket-normal',
            'event_id' => 'event-1',
            'flags' => [],
            'net_rate' => 15000,
            'stock' => 4,
            'min_order' => 1,
        ]);

        $this->assertFalse($normalized['is_package_rate']);
        $this->assertNull($normalized['package_quantity']);
        $this->assertNull($normalized['package_price']);
    }
}
