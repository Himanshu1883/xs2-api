<?php

namespace App\Services\SellerApi;

use App\Jobs\CreateXs2SandboxOrderFromSbOrder;
use App\Jobs\SyncXs2OrderGuestDataFromSbOrder;
use App\Models\SbOrder;
use App\Models\SbOrderAttendee;
use App\Models\Xs2SyncState;
use App\Services\Xs2\SbOrderXs2GuestDataSyncService;
use App\Services\Xs2\SbOrderXs2SandboxOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Fetch Seatsbrokers Seller API bookings (GET /api/booking) and upsert into sb_orders / sb_order_attendees.
 */
class SellerBookingSyncService
{
    public const SYNC_RESOURCE = 'sb-bookings:sync';

    public function __construct(
        private readonly SellerApiClient $client,
        private readonly ListingSalesService $listingSales,
        private readonly SbOrderXs2SandboxOrderService $xs2SandboxOrders,
        private readonly SbOrderXs2GuestDataSyncService $xs2GuestDataSync,
    ) {}

    /**
     * @return array{fetched:int, created:int, updated:int, attendees:int, stock_reconcile_queued:int, xs2_orders_queued:int}
     */
    public function sync(array $query = []): array
    {
        if (Schema::hasTable('xs2_sync_states')) {
            Xs2SyncState::query()->firstOrCreate(['resource' => self::SYNC_RESOURCE])->update([
                'status' => 'running',
                'last_attempted_at' => now(),
                'last_error' => null,
            ]);
        }

        $summary = [
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'attendees' => 0,
            'stock_reconcile_queued' => 0,
            'xs2_orders_queued' => 0,
        ];

        try {
            $rows = $this->extractBookingRows($this->client->fetchBookings($query));

            $touchedListingIds = [];

            foreach (array_values(array_filter($rows, is_array(...))) as $row) {
                $this->upsertBooking($row, $summary, $touchedListingIds);
            }

            $reconcile = $this->listingSales->queueStockReconcileForListingIds($touchedListingIds);
            $summary['stock_reconcile_queued'] = $reconcile['queued'];

            return $this->finalizeRun($summary);
        } catch (Throwable $exception) {
            return $this->finalizeRun($summary, $exception->getMessage());
        }
    }

    /**
     * Upsert a single booking payload (Seller API row or SB webhook body).
     *
     * @param  array<string, mixed>  $row
     * @return array{booking_no:string, created:bool, updated:bool, sb_order_id:int, attendees:int, stock_reconcile_queued:int}
     */
    public function processBookingPayload(array $row): array
    {
        $bookingNo = $this->nullableString($row['booking_no'] ?? null);
        if ($bookingNo === null) {
            throw new \InvalidArgumentException('Booking payload is missing booking_no.');
        }

        $summary = [
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'attendees' => 0,
            'stock_reconcile_queued' => 0,
        ];
        $touchedListingIds = [];
        $createdBefore = SbOrder::query()->where('booking_no', $bookingNo)->exists();

        $this->upsertBooking($row, $summary, $touchedListingIds);

        $reconcile = $this->listingSales->queueStockReconcileForListingIds($touchedListingIds);
        $summary['stock_reconcile_queued'] = $reconcile['queued'];

        $order = SbOrder::query()->where('booking_no', $bookingNo)->firstOrFail();

        return [
            'booking_no' => $bookingNo,
            'created' => ! $createdBefore,
            'updated' => $createdBefore,
            'sb_order_id' => $order->id,
            'attendees' => $summary['attendees'],
            'stock_reconcile_queued' => $summary['stock_reconcile_queued'],
        ];
    }

    public function syncOrder(SbOrder $order): SbOrder
    {
        $bookingNo = $this->nullableString($order->booking_no);
        if ($bookingNo === null) {
            throw new \RuntimeException('Order is missing a booking number.');
        }

        $rows = $this->extractBookingRows($this->client->fetchBookings(['booking_no' => $bookingNo]));
        $match = null;
        foreach (array_values(array_filter($rows, is_array(...))) as $row) {
            if ($this->nullableString($row['booking_no'] ?? null) === $bookingNo) {
                $match = $row;
                break;
            }
        }

        if ($match === null) {
            throw new \RuntimeException(sprintf('Booking %s was not found in Seller API response.', $bookingNo));
        }

        $summary = [
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'attendees' => 0,
            'stock_reconcile_queued' => 0,
        ];
        $touchedListingIds = [];
        $this->upsertBooking($match, $summary, $touchedListingIds);
        $this->listingSales->queueStockReconcileForListingIds($touchedListingIds);

        return SbOrder::query()
            ->where('booking_no', $bookingNo)
            ->with('attendees')
            ->withCount('attendees')
            ->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    private function extractBookingRows(array $response): array
    {
        $rows = data_get($response, 'result');
        if (! is_array($rows)) {
            $rows = data_get($response, 'results', data_get($response, 'data', []));
        }
        if (! is_array($rows)) {
            throw new \RuntimeException('Seller API booking response is missing a result array.');
        }

        return array_values(array_filter($rows, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $summary
     * @param  list<string>  $touchedListingIds
     */
    private function upsertBooking(array $row, array &$summary, array &$touchedListingIds): void
    {
        $bookingNo = $this->nullableString($row['booking_no'] ?? null);
        if ($bookingNo === null) {
            return;
        }

        $summary['fetched']++;

        $attendees = is_array($row['attendee_details'] ?? null) ? $row['attendee_details'] : [];
        $attributes = $this->orderAttributes($row);

        $queueXs2SandboxOrder = false;
        $queueGuestDataSync = false;

        DB::transaction(function () use ($bookingNo, $attributes, $attendees, &$summary, &$touchedListingIds, &$queueXs2SandboxOrder, &$queueGuestDataSync): void {
            $existing = SbOrder::query()->where('booking_no', $bookingNo)->first();
            if ($existing === null) {
                $order = SbOrder::query()->create([
                    'booking_no' => $bookingNo,
                    ...$attributes,
                ]);
                $summary['created']++;
            } else {
                $existing->fill($attributes)->save();
                $order = $existing;
                $summary['updated']++;
            }

            foreach ($this->marketplaceIdsFromOrder($order) as $listingId) {
                $touchedListingIds[] = $listingId;
            }

            SbOrderAttendee::query()->where('sb_order_id', $order->id)->delete();

            $position = 0;
            foreach (array_values(array_filter($attendees, is_array(...))) as $attendee) {
                SbOrderAttendee::query()->create([
                    'sb_order_id' => $order->id,
                    'position' => $position,
                    'first_name' => $this->nullableString($attendee['first_name'] ?? $attendee['firstname'] ?? null),
                    'last_name' => $this->nullableString($attendee['last_name'] ?? $attendee['lastname'] ?? null),
                    'dob' => $this->nullableString($attendee['dob'] ?? $attendee['date_of_birth'] ?? null),
                    'nationality' => $this->nullableString($attendee['nationality'] ?? $attendee['country_of_residence'] ?? null),
                    'province' => $this->nullableString($attendee['province'] ?? $attendee['state'] ?? null),
                    'email' => $this->nullableString($attendee['email'] ?? null),
                    'phone' => $this->nullableString($attendee['phone'] ?? $attendee['mobile'] ?? null),
                    'passport' => $this->nullableString($attendee['passport'] ?? $attendee['passport_number'] ?? null),
                    'gender' => $this->nullableString($attendee['gender'] ?? null),
                    'raw_payload' => $attendee,
                ]);
                $position++;
                $summary['attendees']++;
            }

            $order->load('attendees');
            $queueXs2SandboxOrder = $this->xs2SandboxOrders->queueIfEligible($order);
            $queueGuestDataSync = $attendees !== [] && $this->xs2GuestDataSync->queueIfEligible($order);
        });

        if ($queueXs2SandboxOrder) {
            $order = SbOrder::query()->where('booking_no', $bookingNo)->first();
            if ($order !== null) {
                CreateXs2SandboxOrderFromSbOrder::dispatch($order->id);
                $summary['xs2_orders_queued']++;
            }
        } elseif ($queueGuestDataSync) {
            $order = SbOrder::query()->where('booking_no', $bookingNo)->first();
            if ($order !== null) {
                SyncXs2OrderGuestDataFromSbOrder::dispatch($order->id);
                $summary['xs2_guest_data_queued'] = ($summary['xs2_guest_data_queued'] ?? 0) + 1;
            }
        }
    }

    /** @return list<string> */
    private function marketplaceIdsFromOrder(SbOrder $order): array
    {
        $ids = [];
        if ($order->ticket_id !== null) {
            $ids[] = (string) $order->ticket_id;
        }
        if (is_string($order->listing_id) && $order->listing_id !== '') {
            $ids[] = $order->listing_id;
        }

        return $ids;
    }

    /** @param  array<string, mixed>  $row @return array<string, mixed> */
    private function orderAttributes(array $row): array
    {
        return [
            'booking_status' => $this->nullableInt($row['booking_status'] ?? null),
            'booking_status_text' => $this->nullableString($row['booking_status_text'] ?? null),
            'ticket_amount' => $this->nullableDecimal($row['ticket_amount'] ?? null),
            'currency_type' => $this->nullableString($row['currency_type'] ?? null),
            'match_name' => $this->nullableString($row['match_name'] ?? null),
            'tournament_name' => $this->nullableString($row['tournament_name'] ?? null),
            'stadium_name' => $this->nullableString($row['stadium_name'] ?? null),
            'match_date' => $this->nullableDate($row['match_date'] ?? null),
            'match_time' => $this->nullableString($row['match_time'] ?? null),
            'match_id' => $this->nullableInt($row['match_id'] ?? null),
            'ticket_id' => $this->nullableInt($row['ticket_id'] ?? null),
            'listing_id' => $this->nullableString($row['listing_id'] ?? null),
            'ticketid' => $this->nullableString($row['ticketid'] ?? null),
            'quantity' => $this->nullableInt($row['quantity'] ?? null),
            'split' => $this->nullableInt($row['split'] ?? null),
            'seat_category' => $this->nullableString($row['seat_category'] ?? null),
            'ticket_block' => $this->nullableString($row['ticket_block'] ?? null),
            'row' => $this->nullableString($row['row'] ?? null),
            'section' => $this->nullableString($row['section'] ?? null),
            'listing_note' => $this->nullableString($row['listing_note'] ?? null),
            'ticket_types_name' => $this->nullableString($row['ticket_types_name'] ?? null),
            'buyer_first_name' => $this->nullableString($row['buyer_first_name'] ?? null),
            'buyer_last_name' => $this->nullableString($row['buyer_last_name'] ?? null),
            'raw_payload' => $row,
            'synced_at' => now(),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT);

        return $int === false ? null : $int;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function nullableDate(mixed $value): ?string
    {
        $string = $this->nullableString($value);
        if ($string === null) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $string) === 1) {
            return substr($string, 0, 10);
        }

        try {
            return \Carbon\Carbon::parse($string)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{fetched:int, created:int, updated:int, attendees:int, stock_reconcile_queued:int, xs2_orders_queued:int}  $summary
     * @return array{fetched:int, created:int, updated:int, attendees:int, stock_reconcile_queued:int, xs2_orders_queued:int, status:string, completed_at:string}
     */
    private function finalizeRun(array $summary, ?string $fatalError = null): array
    {
        $failed = filled($fatalError);

        if (Schema::hasTable('xs2_sync_states')) {
            $state = Xs2SyncState::query()->firstOrCreate(['resource' => self::SYNC_RESOURCE]);
            $state->update([
                'status' => $failed ? 'failed' : 'completed',
                'last_attempted_at' => now(),
                'last_successful_at' => $failed ? $state->last_successful_at : now(),
                'last_error' => $failed ? $fatalError : null,
                'metadata' => [
                    'fetched' => (int) ($summary['fetched'] ?? 0),
                    'created' => (int) ($summary['created'] ?? 0),
                    'updated' => (int) ($summary['updated'] ?? 0),
                    'attendees' => (int) ($summary['attendees'] ?? 0),
                    'stock_reconcile_queued' => (int) ($summary['stock_reconcile_queued'] ?? 0),
                    'xs2_orders_queued' => (int) ($summary['xs2_orders_queued'] ?? 0),
                ],
            ]);
        }

        $summary['status'] = $failed ? 'failed' : 'completed';
        $summary['completed_at'] = now()->toIso8601String();

        return $summary;
    }
}
