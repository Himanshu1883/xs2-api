<?php

namespace Tests\Feature;

use App\Jobs\SyncXs2OrderGuestDataFromSbOrder;
use App\Models\ExternalListingMapping;
use App\Models\ListingSplit;
use App\Models\SbOrder;
use App\Models\SbOrderAttendee;
use App\Models\Xs2Event;
use App\Models\Xs2Order;
use App\Models\Xs2Ticket;
use App\Services\SellerApi\ListingSalesService;
use App\Services\SellerApi\SellerApiClient;
use App\Services\SellerApi\SellerBookingSyncService;
use App\Services\Xs2\SbOrderXs2GuestDataSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SbOrderXs2GuestDataSyncTest extends TestCase
{
    private const BOOKINGORDER_ID = 'xs2-bko-guest-sync';

    private const TICKET_ID = 'xs2-ticket-guest-sync_tck';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();

        config()->set('xs2.sandbox.api_url', 'https://sandbox.xs2.test');
        config()->set('xs2.sandbox.api_key', 'sandbox-key');
        config()->set('xs2.sandbox.auto_create_orders_from_sb', true);
        config()->set('xs2.sb_order_guest_data_sync.enabled', true);
        config()->set('xs2.bookingorder_guestdata_endpoint', '/v1/bookingorders/{bookingorder_id}/guestdata');
        config()->set('xs2.ticket_guestdata_endpoint', '/v1/tickets/{ticket_id}/guestdata');
        config()->set('xs2.sandbox.retry_times', 1);
    }

    public function test_sb_booking_update_persists_attendees_without_auto_pushing_guest_data(): void
    {
        Queue::fake();

        $sbOrder = $this->seedLinkedOrder(withAttendees: false);
        $xs2Order = Xs2Order::query()->where('sb_order_id', $sbOrder->id)->firstOrFail();
        $xs2Order->fill([
            'xs2_bookingorder_id' => self::BOOKINGORDER_ID,
            'xs2_booking_id' => 'xs2-booking-guest-sync',
            'external_ticket_id' => self::TICKET_ID,
        ])->save();

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('fetchBookings')
            ->once()
            ->andReturn([
                'result' => [[
                    'booking_no' => $sbOrder->booking_no,
                    'booking_status' => SbOrder::STATUS_CONFIRMED,
                    'ticket_id' => 906584,
                    'listing_id' => '841765',
                    'quantity' => 1,
                    'attendee_details' => [[
                        'first_name' => 'Jane',
                        'last_name' => 'Doe',
                        'email' => 'jane@example.com',
                        'passport' => 'AB1234567',
                    ]],
                ]],
            ]);

        $listingSales = Mockery::mock(ListingSalesService::class);
        $listingSales->shouldReceive('queueStockReconcileForListingIds')->once()->andReturn(['queued' => 0]);

        app(SellerBookingSyncService::class, [
            'client' => $client,
            'listingSales' => $listingSales,
        ])->syncOrder($sbOrder->fresh(), true);

        $sbOrder->refresh();
        $this->assertNotNull($sbOrder->attendee_fetched_at);
        $this->assertDatabaseHas('sb_order_attendees', [
            'sb_order_id' => $sbOrder->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
        $this->assertDatabaseHas('xs2_order_attendees', [
            'xs2_order_id' => $xs2Order->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
        $this->assertNotNull($xs2Order->fresh()->attendees_copied_from_sb_at);
        Queue::assertNotPushed(SyncXs2OrderGuestDataFromSbOrder::class);
        Queue::assertNotPushed(\App\Jobs\CreateXs2SandboxOrderFromSbOrder::class);
    }

    public function test_service_pushes_sb_attendees_to_xs2_booking_guest_data(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders/'.self::BOOKINGORDER_ID.'/guestdata*' => Http::sequence()
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
                            'first_name' => 'Jane',
                            'last_name' => 'Doe',
                            'passport_number' => 'AB1234567',
                        ]],
                    ]],
                ]),
        ]);

        $sbOrder = $this->seedLinkedOrder(withAttendees: true);
        $xs2Order = Xs2Order::query()->where('sb_order_id', $sbOrder->id)->firstOrFail();
        $xs2Order->fill([
            'xs2_bookingorder_id' => self::BOOKINGORDER_ID,
            'xs2_booking_id' => 'xs2-booking-guest-sync',
            'external_ticket_id' => self::TICKET_ID,
            'is_sandbox' => true,
        ])->save();

        $result = app(SbOrderXs2GuestDataSyncService::class)->syncForSbOrder($sbOrder->fresh(['attendees', 'xs2Order']));

        $this->assertTrue($result['synced']);
        $this->assertFalse($result['skipped']);

        $xs2Order->refresh();
        $this->assertNotNull($xs2Order->guest_data_synced_at);
        $this->assertNull($xs2Order->guest_data_sync_error);
        $this->assertNotNull($xs2Order->guest_data_source_fingerprint);
        $this->assertDatabaseHas('xs2_order_attendees', [
            'xs2_order_id' => $xs2Order->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'passport' => 'AB1234567',
        ]);

        Http::assertSent(function ($request): bool {
            $guest = data_get($request->data(), 'items.0.guests.0');

            return $request->method() === 'PUT'
                && str_contains($request->url(), '/v1/bookingorders/'.self::BOOKINGORDER_ID.'/guestdata')
                && data_get($request->data(), 'items.0.ticket_id') === self::TICKET_ID
                && is_array($guest)
                && $guest['first_name'] === 'Jane'
                && $guest['lead_guest'] === true
                && $guest['street_name'] === 'Not provided'
                && $guest['city'] === 'Barcelona'
                && $guest['zip'] === '00000'
                && ! array_key_exists('guest_id', $guest)
                && ! array_key_exists('reservation_id', $guest)
                && ! array_key_exists('ticket_id', $guest)
                && ! array_key_exists('conditions', $guest);
        });
    }

    public function test_service_skips_when_guest_data_already_synced_for_same_attendees(): void
    {
        Http::fake();

        $sbOrder = $this->seedLinkedOrder(withAttendees: true);
        $xs2Order = Xs2Order::query()->where('sb_order_id', $sbOrder->id)->firstOrFail();
        $fingerprint = app(SbOrderXs2GuestDataSyncService::class);
        $sbOrderFresh = $sbOrder->fresh('attendees');

        $xs2Order->fill([
            'xs2_bookingorder_id' => self::BOOKINGORDER_ID,
            'external_ticket_id' => self::TICKET_ID,
            'is_sandbox' => true,
            'guest_data_synced_at' => now(),
            'guest_data_source_fingerprint' => (function () use ($sbOrderFresh): string {
                $service = app(SbOrderXs2GuestDataSyncService::class);
                $reflection = new \ReflectionClass($service);
                $method = $reflection->getMethod('attendeeFingerprint');
                $method->setAccessible(true);

                return $method->invoke($service, $sbOrderFresh);
            })(),
        ])->save();

        $result = app(SbOrderXs2GuestDataSyncService::class)->syncForSbOrder($sbOrderFresh);

        $this->assertTrue($result['skipped']);
        Http::assertNothingSent();
    }

    public function test_command_fetches_attendees_once_then_skips(): void
    {
        $sbOrder = $this->seedLinkedOrder(withAttendees: false);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('fetchBookings')
            ->once()
            ->with(['booking_no' => $sbOrder->booking_no])
            ->andReturn([
                'result' => [[
                    'booking_no' => $sbOrder->booking_no,
                    'booking_status' => SbOrder::STATUS_CONFIRMED,
                    'ticket_id' => 906584,
                    'listing_id' => '841765',
                    'quantity' => 1,
                    'attendee_details' => [[
                        'first_name' => 'Jane',
                        'last_name' => 'Doe',
                        'email' => 'jane@example.com',
                    ]],
                ]],
            ]);
        $this->app->instance(SellerApiClient::class, $client);

        $listingSales = Mockery::mock(ListingSalesService::class);
        $listingSales->shouldReceive('queueStockReconcileForListingIds')->once()->andReturn(['queued' => 0]);
        $this->app->instance(ListingSalesService::class, $listingSales);

        $exitCode = Artisan::call('xs2:sync-order-guest-data');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1 fetched', Artisan::output());
        $this->assertNotNull($sbOrder->fresh()->attendee_fetched_at);

        $second = Artisan::call('xs2:sync-order-guest-data');
        $this->assertSame(0, $second);
        $this->assertStringContainsString('0 fetched', Artisan::output());
    }

    public function test_service_skips_when_sb_attendees_missing_required_guest_fields(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders/'.self::BOOKINGORDER_ID.'/guestdata*' => Http::response([
                'items' => [[
                    'ticket_id' => self::TICKET_ID,
                    'guests' => [[
                        'first_name' => ['condition' => 'required'],
                        'last_name' => ['condition' => 'required'],
                        'date_of_birth' => ['condition' => 'required'],
                    ]],
                ]],
            ]),
        ]);

        $sbOrder = $this->seedLinkedOrder(withAttendees: true);
        $xs2Order = Xs2Order::query()->where('sb_order_id', $sbOrder->id)->firstOrFail();
        $xs2Order->fill([
            'xs2_bookingorder_id' => self::BOOKINGORDER_ID,
            'xs2_booking_id' => 'xs2-booking-guest-sync',
            'external_ticket_id' => self::TICKET_ID,
            'is_sandbox' => true,
        ])->save();

        $result = app(SbOrderXs2GuestDataSyncService::class)->syncForSbOrder($sbOrder->fresh(['attendees', 'xs2Order']));

        $this->assertTrue($result['skipped']);
        $this->assertStringContainsString('date of birth', strtolower((string) $result['reason']));
        Http::assertSentCount(1);
    }

    public function test_service_re_resolves_bookingorder_id_when_booking_id_was_stored(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders?*' => Http::response([
                'bookingorders' => [[
                    'booking_id' => 'xs2-booking-guest-sync',
                    'bookingorder_id' => self::BOOKINGORDER_ID,
                ]],
            ]),
            'https://sandbox.xs2.test/v1/bookingorders/'.self::BOOKINGORDER_ID.'/guestdata*' => Http::sequence()
                ->push([
                    'items' => [[
                        'ticket_id' => self::TICKET_ID,
                        'guests' => [[
                            'first_name' => ['condition' => 'required'],
                            'last_name' => ['condition' => 'required'],
                        ]],
                    ]],
                ])
                ->push(['guestdata_status' => 'completed']),
        ]);

        $sbOrder = $this->seedLinkedOrder(withAttendees: true);
        $xs2Order = Xs2Order::query()->where('sb_order_id', $sbOrder->id)->firstOrFail();
        $xs2Order->fill([
            'external_order_id' => 'xs2-booking-guest-sync',
            'xs2_bookingorder_id' => 'xs2-booking-guest-sync',
            'xs2_booking_id' => 'xs2-booking-guest-sync',
            'external_ticket_id' => self::TICKET_ID,
            'is_sandbox' => true,
        ])->save();

        $result = app(SbOrderXs2GuestDataSyncService::class)->syncForSbOrder($sbOrder->fresh(['attendees', 'xs2Order']));

        $this->assertTrue($result['synced']);
        $this->assertSame(self::BOOKINGORDER_ID, $xs2Order->fresh()->xs2_bookingorder_id);
    }

    public function test_service_uses_split_listing_ticket_id_for_guest_data_push(): void
    {
        $masterTicketId = '65e39feec62e49dc8f2e486023c7bd6b_spp';
        $splitTicketId = $masterTicketId.'-S2';

        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders/'.self::BOOKINGORDER_ID.'/guestdata*' => Http::sequence()
                ->push([
                    'items' => [[
                        'ticket_id' => $splitTicketId,
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
                        'ticket_id' => $splitTicketId,
                        'guests' => [[
                            'first_name' => 'Jane',
                            'last_name' => 'Doe',
                            'passport_number' => 'AB1234567',
                        ]],
                    ]],
                ]),
        ]);

        $sbOrder = $this->seedSplitLinkedOrder($masterTicketId, '920288');
        $xs2Order = Xs2Order::query()->where('sb_order_id', $sbOrder->id)->firstOrFail();
        $xs2Order->fill([
            'xs2_bookingorder_id' => self::BOOKINGORDER_ID,
            'xs2_booking_id' => 'xs2-booking-guest-sync',
            'external_ticket_id' => $masterTicketId,
            'is_sandbox' => true,
        ])->save();

        $result = app(SbOrderXs2GuestDataSyncService::class)->syncForSbOrder($sbOrder->fresh(['attendees', 'xs2Order']));

        $this->assertTrue($result['synced']);

        Http::assertSent(function ($request) use ($splitTicketId): bool {
            return $request->method() === 'PUT'
                && data_get($request->data(), 'items.0.ticket_id') === $splitTicketId;
        });
    }

    public function test_push_guest_data_includes_xs2_response_body_on_failure(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders/'.self::BOOKINGORDER_ID.'/guestdata*' => Http::sequence()
                ->push([
                    'items' => [[
                        'ticket_id' => self::TICKET_ID,
                        'guests' => [[
                            'first_name' => ['condition' => 'required'],
                            'last_name' => ['condition' => 'required'],
                        ]],
                    ]],
                ])
                ->push([
                    'message' => 'Invalid or missing guest data',
                    'errors' => ['country_of_residence' => 'must be ISO alpha-3'],
                ], 422),
        ]);

        $sbOrder = $this->seedLinkedOrder(withAttendees: true);
        $xs2Order = Xs2Order::query()->where('sb_order_id', $sbOrder->id)->firstOrFail();
        $xs2Order->fill([
            'xs2_bookingorder_id' => self::BOOKINGORDER_ID,
            'external_ticket_id' => self::TICKET_ID,
            'is_sandbox' => true,
        ])->save();

        app(SbOrderXs2GuestDataSyncService::class)->copyAttendeesFromSbOrder($sbOrder);

        $result = app(SbOrderXs2GuestDataSyncService::class)->pushGuestDataForXs2Order($xs2Order->fresh(['attendees', 'sbOrder']));

        $this->assertFalse($result['synced']);
        $this->assertStringContainsString('Invalid or missing guest data', (string) $result['error']);
        $this->assertStringContainsString('country_of_residence', (string) $result['error']);

        $this->assertDatabaseHas('xs2_order_guest_data_logs', [
            'xs2_order_id' => $xs2Order->id,
            'response_status' => 422,
        ]);
    }

    private function seedSplitLinkedOrder(string $masterTicketId, string $seatsbrokerListingId): SbOrder
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'evt-guest-split',
            'event_name' => 'Guest Split Event',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => $masterTicketId,
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Tribuna',
            'net_rate' => 10000,
            'currency_code' => 'EUR',
            'is_sandbox' => true,
            'ticket_status' => 'available',
            'stock' => 10,
            'sync_status' => 'pending',
            'raw_payload' => [],
        ]);

        ListingSplit::query()->create([
            'master_listing_id' => $ticket->id,
            'split_order' => 2,
            'seller_reference' => 'XS2-'.$masterTicketId.'-S2',
            'quantity' => 2,
            'price' => 120.00,
            'seatsbroker_listing_id' => $seatsbrokerListingId,
            'status' => 'active',
            'sync_status' => 'synced',
        ]);

        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-GUEST-SPLIT-001',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'booking_status_text' => 'Confirmed',
            'ticket_id' => (int) $seatsbrokerListingId,
            'listing_id' => $seatsbrokerListingId,
            'quantity' => 1,
            'match_name' => 'Guest Split Match',
        ]);

        Xs2Order::query()->create([
            'external_order_id' => self::BOOKINGORDER_ID,
            'is_sandbox' => true,
            'sb_order_id' => $sbOrder->id,
            'xs2_booking_id' => 'xs2-booking-guest-sync',
            'xs2_bookingorder_id' => self::BOOKINGORDER_ID,
            'xs2_reservation_id' => 'xs2-reservation-guest-split_rsv',
            'external_ticket_id' => $masterTicketId,
            'quantity' => 1,
            'order_status' => 'confirmed',
        ]);

        SbOrderAttendee::query()->create([
            'sb_order_id' => $sbOrder->id,
            'position' => 0,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'passport' => 'AB1234567',
        ]);

        return $sbOrder;
    }

    private function seedLinkedOrder(bool $withAttendees): SbOrder
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'evt-guest-sync',
            'event_name' => 'Guest Sync Event',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => self::TICKET_ID,
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Tribuna',
            'net_rate' => 10000,
            'currency_code' => 'EUR',
            'is_sandbox' => true,
            'ticket_status' => 'available',
            'stock' => 10,
            'sync_status' => 'pending',
            'raw_payload' => [],
        ]);

        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'seller_listing_id' => '841765',
            'seller_reference' => 'XS2-ref',
            'status' => 'active',
        ]);

        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-GUEST-001',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'booking_status_text' => 'Confirmed',
            'ticket_id' => 906584,
            'listing_id' => '841765',
            'quantity' => 1,
            'match_name' => 'Guest Sync Match',
        ]);

        Xs2Order::query()->create([
            'external_order_id' => self::BOOKINGORDER_ID,
            'is_sandbox' => true,
            'sb_order_id' => $sbOrder->id,
            'xs2_booking_id' => 'xs2-booking-guest-sync',
            'xs2_bookingorder_id' => self::BOOKINGORDER_ID,
            'xs2_reservation_id' => 'xs2-reservation-guest-sync_rsv',
            'external_ticket_id' => self::TICKET_ID,
            'quantity' => 1,
            'order_status' => 'confirmed',
        ]);

        if ($withAttendees) {
            SbOrderAttendee::query()->create([
                'sb_order_id' => $sbOrder->id,
                'position' => 0,
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'jane@example.com',
                'passport' => 'AB1234567',
            ]);
        }

        return $sbOrder;
    }
}
