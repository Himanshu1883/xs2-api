<?php

namespace App\Services\SellerApi;

use App\Jobs\CreateXs2SandboxOrderFromSbOrder;
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
        private readonly SbOrderXs2GuestDataSyncService $guestDataSync,
    ) {}

    /**
     * @return array{
     *     fetched:int,
     *     created:int,
     *     updated:int,
     *     attendees:int,
     *     stock_reconcile_queued:int,
     *     xs2_orders_queued:int,
     *     pages:int,
     *     api_total:?int,
     *     listing_base_url:?string,
     *     status:string,
     *     completed_at:string,
     *     error:?string
     * }
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
            'pages' => 0,
            'api_total' => null,
            'listing_base_url' => null,
        ];

        try {
            $summary['listing_base_url'] = $this->client->resolvedListingBaseUrl();
            $payload = $this->client->fetchAllBookings($query);
            $summary['pages'] = (int) ($payload['pages'] ?? 0);
            $summary['api_total'] = isset($payload['total']) && is_numeric($payload['total'])
                ? (int) $payload['total']
                : null;
            if (is_string($payload['listing_base_url'] ?? null) && $payload['listing_base_url'] !== '') {
                $summary['listing_base_url'] = $payload['listing_base_url'];
            }
            $rows = $this->extractBookingRows($payload);

            $touchedListingIds = [];

            foreach (array_values(array_filter($rows, is_array(...))) as $row) {
                $this->upsertBooking($row, $summary, $touchedListingIds, false);
            }

            $reconcile = $this->listingSales->queueStockReconcileForListingIds($touchedListingIds);
            $summary['stock_reconcile_queued'] = $reconcile['queued'];

            return $this->finalizeRun($summary);
        } catch (Throwable $exception) {
            try {
                $summary['listing_base_url'] ??= $this->client->resolvedListingBaseUrl();
            } catch (Throwable) {
                // ignore — host is diagnostic only
            }

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
        $bookingNo = $this->bookingNumberFromRow($row);
        if ($bookingNo === null) {
            throw new \InvalidArgumentException('Booking payload is missing booking_no.');
        }

        $summary = [
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'attendees' => 0,
            'stock_reconcile_queued' => 0,
            'xs2_orders_queued' => 0,
        ];
        $touchedListingIds = [];
        $createdBefore = SbOrder::query()->where('booking_no', $bookingNo)->exists();

        $this->upsertBooking($row, $summary, $touchedListingIds, false);

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

    public function syncOrder(SbOrder $order, bool $forceAttendees = false): SbOrder
    {
        $bookingNo = $this->nullableString($order->booking_no);
        if ($bookingNo === null) {
            throw new \RuntimeException('Order is missing a booking number.');
        }

        $rows = $this->extractBookingRows($this->client->fetchBookings(['booking_no' => $bookingNo]));
        $match = null;
        foreach (array_values(array_filter($rows, is_array(...))) as $row) {
            if ($this->bookingNumberFromRow($row) === $bookingNo) {
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
            'xs2_orders_queued' => 0,
        ];
        $touchedListingIds = [];
        $this->upsertBooking($match, $summary, $touchedListingIds, $forceAttendees);
        $this->listingSales->queueStockReconcileForListingIds($touchedListingIds);

        return SbOrder::query()
            ->where('booking_no', $bookingNo)
            ->with(['attendees', 'xs2Order'])
            ->withCount('attendees')
            ->firstOrFail();
    }

    /**
     * Fetch attendee_details from Seats Broker for one order. Manual admin actions pass $force = true
     * so they can re-fetch even after cron has marked the order as fetched.
     */
    public function fetchAttendees(SbOrder $order, bool $force = true): SbOrder
    {
        return $this->syncOrder($order, $force);
    }

    /**
     * Cron path: poll SB orders that have never successfully stored attendee_details.
     *
     * @return array{fetched:int, skipped:int, failed:int, errors:list<array{sb_order_id:int|null, message:string}>}
     */
    public function fetchPendingAttendees(?int $limit = null): array
    {
        $limit = $limit ?? max(1, (int) config('xs2.sb_order_guest_data_sync.batch_limit', 50));
        $summary = [
            'fetched' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        if (Schema::hasTable('xs2_sync_states')) {
            Xs2SyncState::query()->firstOrCreate(['resource' => SbOrderXs2GuestDataSyncService::SYNC_RESOURCE])->update([
                'status' => 'running',
                'last_attempted_at' => now(),
                'last_error' => null,
            ]);
        }

        $query = SbOrder::query()->activeSold()->orderBy('id');
        if (Schema::hasColumn('sb_orders', 'attendee_fetched_at')) {
            $query->whereNull('attendee_fetched_at');
        }

        foreach ($query->limit($limit)->get() as $order) {
            if (Schema::hasColumn('sb_orders', 'attendee_fetched_at') && $order->attendee_fetched_at !== null) {
                $summary['skipped']++;

                continue;
            }

            try {
                $refreshed = $this->syncOrder($order, false);
                $refreshed->loadMissing('attendees');
                if ($refreshed->attendee_fetched_at !== null || $refreshed->attendees->isNotEmpty()) {
                    $summary['fetched']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (Throwable $exception) {
                $summary['failed']++;
                $summary['errors'][] = [
                    'sb_order_id' => $order->id,
                    'message' => $exception->getMessage(),
                ];
                if (Schema::hasColumn('sb_orders', 'attendee_fetch_error')) {
                    $order->forceFill([
                        'attendee_fetch_error' => mb_substr($exception->getMessage(), 0, 2000),
                    ])->save();
                }
            }
        }

        if (Schema::hasTable('xs2_sync_states')) {
            $state = Xs2SyncState::query()->firstOrCreate(['resource' => SbOrderXs2GuestDataSyncService::SYNC_RESOURCE]);
            $state->update([
                'status' => $summary['failed'] > 0 ? 'failed' : 'idle',
                'last_attempted_at' => now(),
                'last_successful_at' => $summary['failed'] === 0 ? now() : $state->last_successful_at,
                'last_error' => $summary['errors'][0]['message'] ?? null,
            ]);
        }

        return $summary;
    }

    /** @return list<array<string, mixed>> */
    private function extractBookingRows(array $response): array
    {
        $candidates = [
            data_get($response, 'result'),
            data_get($response, 'results'),
            data_get($response, 'results.data'),
            data_get($response, 'results.bookings'),
            data_get($response, 'data'),
            data_get($response, 'data.bookings'),
            data_get($response, 'bookings'),
            $response,
        ];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || $candidate === []) {
                continue;
            }

            // Associative wrapper (meta/status) — keep looking for a list of bookings.
            if (! array_is_list($candidate)) {
                continue;
            }

            $rows = array_values(array_filter($candidate, is_array(...)));
            if ($rows === []) {
                continue;
            }

            // Prefer lists that look like booking rows.
            $first = $rows[0];
            if (
                $this->bookingNumberFromRow($first) !== null
                || array_key_exists('booking_status', $first)
                || array_key_exists('attendee_details', $first)
                || array_key_exists('ticket_id', $first)
            ) {
                return $rows;
            }
        }

        // Empty list responses are valid (no bookings yet).
        foreach ([data_get($response, 'result'), data_get($response, 'results'), data_get($response, 'data')] as $emptyCandidate) {
            if (is_array($emptyCandidate) && array_is_list($emptyCandidate) && $emptyCandidate === []) {
                return [];
            }
        }

        throw new \RuntimeException('Seller API booking response is missing a result array.');
    }

    /** @param  array<string, mixed>  $row */
    private function bookingNumberFromRow(array $row): ?string
    {
        foreach (['booking_no', 'booking_number', 'bookingNo', 'order_no', 'order_number'] as $key) {
            $value = $this->nullableString($row[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $summary
     * @param  list<string>  $touchedListingIds
     */
    private function upsertBooking(array $row, array &$summary, array &$touchedListingIds, bool $forceAttendees = false): void
    {
        $bookingNo = $this->bookingNumberFromRow($row);
        if ($bookingNo === null) {
            return;
        }

        $summary['fetched']++;

        $attendees = is_array($row['attendee_details'] ?? null) ? $row['attendee_details'] : [];
        $attributes = $this->orderAttributes($row);

        $queueXs2SandboxOrder = false;

        DB::transaction(function () use ($bookingNo, $attributes, $attendees, &$summary, &$touchedListingIds, &$queueXs2SandboxOrder, $forceAttendees): void {
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

            $this->upsertAttendees($order, $attendees, $summary, $forceAttendees);

            $order->load('attendees');
            $queueXs2SandboxOrder = $this->xs2SandboxOrders->queueIfEligible($order);
            $this->xs2SandboxOrders->recordQueueDecision($order);
        });

        if ($queueXs2SandboxOrder) {
            $order = SbOrder::query()->where('booking_no', $bookingNo)->first();
            if ($order !== null) {
                CreateXs2SandboxOrderFromSbOrder::dispatch($order->id);
                $summary['xs2_orders_queued'] = ($summary['xs2_orders_queued'] ?? 0) + 1;
            }
        }
    }

    /**
     * Persist attendee_details at most once per order unless $forceAttendees is true.
     *
     * @param  list<mixed>  $attendees
     * @param  array<string, int>  $summary
     */
    private function upsertAttendees(SbOrder $order, array $attendees, array &$summary, bool $forceAttendees): void
    {
        $incoming = array_values(array_filter($attendees, is_array(...)));
        $alreadyFetched = Schema::hasColumn('sb_orders', 'attendee_fetched_at')
            && $order->attendee_fetched_at !== null;

        if ($alreadyFetched && ! $forceAttendees) {
            return;
        }

        if ($incoming === []) {
            if ($forceAttendees && Schema::hasColumn('sb_orders', 'attendee_fetch_error')) {
                $order->forceFill([
                    'attendee_fetch_error' => 'No attendee details returned from Seats Broker.',
                ])->save();
            }

            return;
        }

        SbOrderAttendee::query()->where('sb_order_id', $order->id)->delete();

        $position = 0;
        foreach ($incoming as $attendee) {
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

        $updates = [];
        if (Schema::hasColumn('sb_orders', 'attendee_fetched_at')) {
            $updates['attendee_fetched_at'] = now();
        }
        if (Schema::hasColumn('sb_orders', 'attendee_fetch_error')) {
            $updates['attendee_fetch_error'] = null;
        }
        if ($updates !== []) {
            $order->forceFill($updates)->save();
        }

        $this->guestDataSync->ensureLinkedXs2OrderHasSbAttendees($order->fresh(['attendees', 'xs2Order']));
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
     * @param  array{fetched:int, created:int, updated:int, attendees:int, stock_reconcile_queued:int, xs2_orders_queued:int, pages?:int, api_total?:?int, listing_base_url?:?string}  $summary
     * @return array{
     *     fetched:int,
     *     created:int,
     *     updated:int,
     *     attendees:int,
     *     stock_reconcile_queued:int,
     *     xs2_orders_queued:int,
     *     pages:int,
     *     api_total:?int,
     *     listing_base_url:?string,
     *     status:string,
     *     completed_at:string,
     *     error:?string
     * }
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
                    'pages' => (int) ($summary['pages'] ?? 0),
                    'api_total' => $summary['api_total'] ?? null,
                    'listing_base_url' => $summary['listing_base_url'] ?? null,
                ],
            ]);
        }

        $summary['pages'] = (int) ($summary['pages'] ?? 0);
        $summary['api_total'] = array_key_exists('api_total', $summary) && is_numeric($summary['api_total'])
            ? (int) $summary['api_total']
            : ($summary['api_total'] ?? null);
        $summary['listing_base_url'] = isset($summary['listing_base_url']) && is_string($summary['listing_base_url'])
            ? $summary['listing_base_url']
            : null;

        $summary['status'] = $failed ? 'failed' : 'completed';
        $summary['completed_at'] = now()->toIso8601String();
        $summary['error'] = $failed ? $fatalError : null;

        return $summary;
    }
}
