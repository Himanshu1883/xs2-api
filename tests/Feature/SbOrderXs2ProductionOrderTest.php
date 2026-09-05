<?php

namespace Tests\Feature;

use App\Models\ExternalListingMapping;
use App\Models\SbOrder;
use App\Models\SbOrderAttendee;
use App\Models\Xs2Event;
use App\Models\Xs2Order;
use App\Models\Xs2Ticket;
use App\Services\Admin\ApiEnvironmentService;
use App\Services\Admin\IntegrationSettingService;
use App\Services\Xs2\SbOrderXs2GuestDataSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SbOrderXs2ProductionOrderTest extends TestCase
{
    private const BOOKINGORDER_ID = 'production-bko-guest-sync';

    private const TICKET_ID = 'production-ticket-guest-sync_tck';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();

        config()->set('xs2.sandbox.auto_create_orders_from_sb', true);
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::XS2_BASE_URL,
            'https://api.xs2.test',
        );
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::XS2_API_KEY,
            'production-key',
            secret: true,
        );
        config()->set('xs2.bookings_endpoint', '/v1/bookings');
        config()->set('xs2.bookingorders_endpoint', '/v1/bookingorders');
        config()->set('xs2.bookingorder_detail_endpoint', '/v1/bookingorders/{bookingorder_id}');
        config()->set('xs2.bookingorder_guestdata_endpoint', '/v1/bookingorders/{bookingorder_id}/guestdata');
        config()->set('xs2.ticket_guestdata_endpoint', '/v1/tickets/{ticket_id}/guestdata');
    }

    public function test_service_pushes_sb_attendees_to_production_xs2_booking_guest_data(): void
    {
        Http::fake([
            'https://api.xs2.test/v1/bookingorders/'.self::BOOKINGORDER_ID.'/guestdata*' => Http::sequence()
                ->push([
                    'items' => [[
                        'ticket_id' => self::TICKET_ID,
                        'guests' => [[
                            'first_name' => ['condition' => 'required'],
                            'last_name' => ['condition' => 'required'],
                            'passport_number' => ['condition' => 'required'],
                        ]],
                    ]],
                ])
                ->push([
                    'guestdata_status' => 'completed',
                    'items' => [[
                        'ticket_id' => self::TICKET_ID,
                        'guests' => [[
                            'first_name' => 'John',
                            'last_name' => 'Smith',
                            'passport_number' => 'CD9876543',
                        ]],
                    ]],
                ]),
        ]);

        $sbOrder = $this->seedLinkedOrder();
        $xs2Order = Xs2Order::query()->where('sb_order_id', $sbOrder->id)->firstOrFail();
        $xs2Order->fill([
            'xs2_bookingorder_id' => self::BOOKINGORDER_ID,
            'xs2_booking_id' => 'production-booking-guest-sync',
            'external_ticket_id' => self::TICKET_ID,
            'is_sandbox' => false,
        ])->save();

        $result = app(SbOrderXs2GuestDataSyncService::class)->syncForSbOrder($sbOrder->fresh(['attendees', 'xs2Order']));

        $this->assertTrue($result['synced']);
        $this->assertFalse($result['skipped']);

        $xs2Order->refresh();
        $this->assertNotNull($xs2Order->guest_data_synced_at);
        $this->assertNull($xs2Order->guest_data_sync_error);

        Http::assertSent(function ($request): bool {
            $guest = data_get($request->data(), 'items.0.guests.0');

            return $request->method() === 'PUT'
                && str_contains($request->url(), 'https://api.xs2.test/v1/bookingorders/'.self::BOOKINGORDER_ID.'/guestdata')
                && data_get($guest, 'first_name') === 'John'
                && data_get($guest, 'last_name') === 'Smith'
                && data_get($guest, 'passport_number') === 'CD9876543';
        });
    }

    private function seedLinkedOrder(): SbOrder
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'production-event-guest',
            'event_name' => 'Real Madrid vs Test',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        $ticket = Xs2Ticket::query()->create([
            'external_ticket_id' => self::TICKET_ID,
            'external_event_id' => $event->external_event_id,
            'xs2_event_id' => $event->id,
            'is_sandbox' => false,
            'ticket_status' => 'available',
            'stock' => 10,
            'net_rate' => 15000,
            'currency_code' => 'EUR',
            'sync_status' => 'pending',
            'raw_payload' => [],
        ]);

        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'seller_listing_id' => '906584',
            'seller_reference' => 'XS2-ref',
            'status' => 'active',
        ]);

        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-PROD-GUEST',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'ticket_id' => 906584,
            'listing_id' => '841765',
            'quantity' => 1,
            'match_name' => 'Real Madrid vs Test',
        ]);

        SbOrderAttendee::query()->create([
            'sb_order_id' => $sbOrder->id,
            'position' => 0,
            'first_name' => 'John',
            'last_name' => 'Smith',
            'passport' => 'CD9876543',
            'email' => 'john@example.com',
        ]);

        Xs2Order::query()->create([
            'external_order_id' => self::BOOKINGORDER_ID,
            'is_sandbox' => false,
            'sb_order_id' => $sbOrder->id,
            'external_ticket_id' => self::TICKET_ID,
            'quantity' => 1,
            'order_status' => 'confirmed',
            'synced_at' => now(),
        ]);

        return $sbOrder;
    }
}
