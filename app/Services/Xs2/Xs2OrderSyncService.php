<?php

namespace App\Services\Xs2;

use App\Exceptions\Integrations\Xs2ConfigurationException;
use App\Exceptions\Integrations\Xs2RequestException;
use App\Models\SbOrder;
use App\Models\Xs2Order;
use App\Models\Xs2OrderAttendee;
use App\Services\Admin\ApiEnvironmentService;
use App\Services\Xs2\SbOrderXs2GuestDataSyncService;
use App\Support\Xs2BookingOrderIdentity;
use Illuminate\Support\Facades\DB;

/**
 * Sync XS2 booking orders into xs2_orders / xs2_order_attendees.
 *
 * Uses the active Create Order API environment (XS2_ORDERS_ACTIVE_ENVIRONMENT):
 * production → Xs2Client GET /v1/bookingorders on api.xs2event.com;
 * sandbox → Xs2SandboxService on testapi.xs2event.com.
 */
class Xs2OrderSyncService
{
    public function __construct(
        private readonly ApiEnvironmentService $apiEnvironment,
        private readonly Xs2Client $client,
        private readonly Xs2SandboxService $sandbox,
        private readonly SbOrderXs2GuestDataSyncService $guestDataSync,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array{fetched:int, created:int, updated:int, attendees:int, endpoint:string, environment:string, is_sandbox:bool}
     */
    public function validateConfiguration(): void
    {
        $isSandbox = $this->isSandboxEnvironment();

        if ($isSandbox) {
            if (! $this->sandbox->isConfigured()) {
                throw new Xs2ConfigurationException(
                    'XS2 sandbox test flow is not configured. Set XS2_SANDBOX_API_URL and XS2_SANDBOX_API_KEY in .env.',
                );
            }

            return;
        }

        if (! $this->client->isOrdersConfigured()) {
            throw new Xs2ConfigurationException(
                'XS2 production order sync is not configured. Set XS2_BASE_URL and XS2_API_KEY in .env (or Admin → API Config).',
            );
        }
    }

    /**
     * @return array{endpoint: string, environment: string, is_sandbox: bool}
     */
    public function environmentMeta(): array
    {
        $isSandbox = $this->isSandboxEnvironment();

        return [
            'endpoint' => $this->bookingOrdersEndpoint($isSandbox),
            'environment' => $isSandbox ? 'sandbox' : 'production',
            'is_sandbox' => $isSandbox,
        ];
    }

    public function sync(array $query = []): array
    {
        $isSandbox = $this->isSandboxEnvironment();
        $endpoint = $this->bookingOrdersEndpoint($isSandbox);

        $this->validateConfiguration();

        try {
            $rows = $this->fetchAllBookingOrders($query, $isSandbox);
        } catch (Xs2ConfigurationException $exception) {
            $hint = $isSandbox
                ? ' Configure XS2_SANDBOX_API_URL and XS2_SANDBOX_API_KEY for sandbox order sync.'
                : ' Configure XS2_BASE_URL and XS2_API_KEY for production order sync.';

            throw new Xs2ConfigurationException(
                $exception->getMessage().$hint,
                (int) $exception->getCode(),
                $exception,
            );
        } catch (Xs2RequestException $exception) {
            $status = $exception->status;
            $apiLabel = $isSandbox ? 'XS2 Test API' : 'XS2 Production API';
            $hint = $status === 404
                ? ' '.$apiLabel.' returned 404 for GET '.$endpoint.'.'
                : ' Expected GET '.$endpoint.' on the '.$apiLabel.' to return bookingorders.';

            throw new Xs2RequestException($exception->getMessage().$hint, $status);
        }

        $summary = [
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'attendees' => 0,
            'endpoint' => $endpoint,
            'environment' => $isSandbox ? 'sandbox' : 'production',
            'is_sandbox' => $isSandbox,
        ];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->upsertOrder($row, $summary, $isSandbox);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function fetchAllBookingOrders(array $query, bool $isSandbox): array
    {
        $pageSize = max(1, min(100, (int) config('xs2.page_size', 100)));
        $maxPages = max(1, (int) config('xs2.max_pages', 50));
        $page = 1;
        $rows = [];

        for ($iteration = 0; $iteration < $maxPages; $iteration++) {
            $response = $this->fetchBookingOrdersPage(array_merge($query, [
                'page' => $page,
                'page_size' => $pageSize,
            ]), $isSandbox);

            $orders = $response['bookingorders'] ?? [];
            if (! is_array($orders) || $orders === []) {
                break;
            }

            foreach ($orders as $order) {
                if (is_array($order)) {
                    $rows[] = $order;
                }
            }

            $totalPages = (int) data_get($response, 'pagination.total_pages', 0);
            $nextPage = data_get($response, 'pagination.next_page');

            if ($nextPage !== null && $nextPage !== '' && (int) $nextPage !== $page) {
                $page = (int) $nextPage;

                continue;
            }

            if ($totalPages > 0 && $page >= $totalPages) {
                break;
            }

            if (count($orders) < $pageSize) {
                break;
            }

            $page++;
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $query */
    private function fetchBookingOrdersPage(array $query, bool $isSandbox): array
    {
        return $isSandbox
            ? $this->sandbox->fetchBookingOrders($query)
            : $this->client->fetchBookingOrders($query);
    }

    private function isSandboxEnvironment(): bool
    {
        return $this->apiEnvironment->xs2OrdersEnvironment() === ApiEnvironmentService::ENV_SANDBOX;
    }

    private function bookingOrdersEndpoint(bool $isSandbox): string
    {
        return $isSandbox
            ? (string) config('xs2.sandbox.bookingorders_endpoint', '/v1/bookingorders')
            : (string) config('xs2.bookingorders_endpoint', '/v1/bookingorders');
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int|string|bool>  $summary
     */
    private function upsertOrder(array $row, array &$summary, bool $isSandbox): void
    {
        $externalId = $this->externalOrderId($row);
        if ($externalId === null) {
            return;
        }

        $summary['fetched'] = (int) $summary['fetched'] + 1;

        $attendees = $this->attendeeRows($row);
        $attributes = $this->orderAttributes($row, $isSandbox);

        DB::transaction(function () use ($externalId, $attributes, $attendees, $row, &$summary): void {
            $existing = $this->findExistingOrder($externalId, $row);

            $attributes['sb_order_id'] = $existing?->sb_order_id ?? $this->resolveSbOrderId($row);

            if ($existing !== null && Xs2BookingOrderIdentity::orderHasPendingBookingOrderId($existing)) {
                $attributes['external_order_id'] = $externalId;
            }

            if ($existing === null) {
                $order = Xs2Order::query()->create([
                    'external_order_id' => $externalId,
                    ...$attributes,
                ]);
                $summary['created'] = (int) $summary['created'] + 1;
            } else {
                $existing->fill($attributes)->save();
                $order = $existing;
                $summary['updated'] = (int) $summary['updated'] + 1;
            }

            if ($attendees !== []) {
                Xs2OrderAttendee::query()->where('xs2_order_id', $order->id)->delete();

                $position = 0;
                foreach ($attendees as $attendee) {
                    Xs2OrderAttendee::query()->create([
                        'xs2_order_id' => $order->id,
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
                    $summary['attendees'] = (int) $summary['attendees'] + 1;
                }
            } elseif ($order->sb_order_id !== null) {
                $sbOrder = SbOrder::query()->with(['attendees', 'xs2Order'])->find($order->sb_order_id);
                if ($sbOrder !== null) {
                    $this->guestDataSync->ensureLinkedXs2OrderHasSbAttendees($sbOrder);
                }
            }
        });
    }

    /** @param  array<string, mixed>  $row */
    private function findExistingOrder(string $externalId, array $row): ?Xs2Order
    {
        $existing = Xs2Order::query()
            ->where('external_order_id', $externalId)
            ->orWhere('xs2_bookingorder_id', $externalId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $sbOrderId = $this->resolveSbOrderId($row);
        if ($sbOrderId === null) {
            return null;
        }

        return Xs2Order::query()
            ->where('sb_order_id', $sbOrderId)
            ->where('external_order_id', 'like', Xs2BookingOrderIdentity::PENDING_EXTERNAL_ORDER_PREFIX.'%')
            ->orderByDesc('id')
            ->first();
    }

    /** @param  array<string, mixed>  $row */
    private function externalOrderId(array $row): ?string
    {
        foreach (['bookingorder_id', 'id', 'order_id', 'external_order_id'] as $key) {
            $value = $this->nullableString($row[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $row */
    private function resolveSbOrderId(array $row): ?int
    {
        foreach (['booking_reference', 'invoice_reference', 'payment_reference'] as $field) {
            $reference = $this->nullableString($row[$field] ?? null);
            if ($reference === null) {
                continue;
            }

            $sbOrder = SbOrder::query()
                ->whereRaw('UPPER(booking_no) = ?', [mb_strtoupper($reference)])
                ->first();
            if ($sbOrder !== null) {
                return $sbOrder->id;
            }
        }

        $bookingCode = $this->nullableString($row['booking_code'] ?? null);
        if ($bookingCode !== null) {
            $sbOrder = SbOrder::query()
                ->whereDoesntHave('xs2Order')
                ->whereRaw('UPPER(booking_no) = ?', [mb_strtoupper($bookingCode)])
                ->first();
            if ($sbOrder !== null) {
                return $sbOrder->id;
            }
        }

        $externalReference = $this->nullableString($row['external_reference_id'] ?? null);
        if ($externalReference !== null) {
            $sbOrder = SbOrder::query()
                ->whereDoesntHave('xs2Order')
                ->whereRaw('UPPER(booking_no) = ?', [mb_strtoupper($externalReference)])
                ->first();
            if ($sbOrder !== null) {
                return $sbOrder->id;
            }
        }

        $bookingEmail = $this->nullableString($row['booking_email'] ?? null);
        if ($bookingEmail === null) {
            return null;
        }

        $sbOrder = SbOrder::query()
            ->whereDoesntHave('xs2Order')
            ->whereHas('attendees', fn ($query) => $query->where('email', $bookingEmail))
            ->orderByDesc('id')
            ->first();

        if ($sbOrder !== null) {
            return $sbOrder->id;
        }

        return SbOrder::query()
            ->whereDoesntHave('xs2Order')
            ->where('raw_payload->buyer_email', $bookingEmail)
            ->orderByDesc('id')
            ->value('id');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function attendeeRows(array $row): array
    {
        $guestDataSync = app(SbOrderXs2GuestDataSyncService::class);

        foreach (['attendees', 'attendee_details', 'guests', 'guest_details'] as $key) {
            $value = $row[$key] ?? null;
            if (is_array($value)) {
                return array_values(array_filter(
                    $value,
                    fn ($attendee): bool => is_array($attendee) && $guestDataSync->attendeeRowHasMeaningfulData($attendee),
                ));
            }
        }

        $items = $row['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $guests = $item['guests'] ?? null;
            if (is_array($guests) && $guests !== []) {
                return array_values(array_filter(
                    $guests,
                    fn ($attendee): bool => is_array($attendee) && $guestDataSync->attendeeRowHasMeaningfulData($attendee),
                ));
            }
        }

        return [];
    }

    /** @param  array<string, mixed>  $row @return array<string, mixed> */
    private function orderAttributes(array $row, bool $isSandbox): array
    {
        $items = is_array($row['items'] ?? null)
            ? array_values(array_filter($row['items'], is_array(...)))
            : [];
        $firstItem = $items[0] ?? [];

        $bookingOrderId = $this->externalOrderId($row);
        $bookingId = $this->nullableString($row['booking_id'] ?? null);
        $status = $row['logistic_status'] ?? $row['booking_status'] ?? $row['status'] ?? null;

        return [
            'is_sandbox' => $isSandbox,
            'xs2_reservation_id' => $this->nullableString($row['reservation_id'] ?? null),
            'xs2_booking_id' => $bookingId,
            'xs2_bookingorder_id' => $bookingOrderId,
            'order_status' => is_scalar($status) ? $this->nullableString((string) $status) : null,
            'order_status_text' => $this->nullableString(
                $row['guestdata_status']
                    ?? $row['status_text']
                    ?? $row['booking_code']
                    ?? null,
            ),
            'ticket_amount' => $this->nullableDecimal(
                $row['ticket_amount']
                    ?? $row['amount']
                    ?? $row['total']
                    ?? $firstItem['sales_price']
                    ?? $firstItem['net_rate']
                    ?? null,
            ),
            'currency_type' => $this->nullableString(
                $row['currency_type']
                    ?? $row['currency']
                    ?? $row['currency_code']
                    ?? $firstItem['currency_code']
                    ?? null,
            ),
            'event_name' => $this->nullableString(
                $row['event_name'] ?? $row['match_name'] ?? $row['name'] ?? null,
            ),
            'venue_name' => $this->nullableString(
                $row['venue_name'] ?? $row['stadium_name'] ?? null,
            ),
            'event_date' => $this->nullableDate(
                $row['event_date'] ?? $row['date_start'] ?? $row['match_date'] ?? $row['date'] ?? null,
            ),
            'event_time' => $this->nullableString(
                $row['event_time'] ?? $row['match_time'] ?? $row['time'] ?? null,
            ),
            'external_event_id' => $this->nullableString(
                $row['event_id'] ?? $row['external_event_id'] ?? null,
            ),
            'external_ticket_id' => $this->nullableString(
                $firstItem['ticket_id'] ?? $row['ticket_id'] ?? $row['external_ticket_id'] ?? null,
            ),
            'quantity' => $this->resolveQuantity($items, $row),
            'seat_category' => $this->nullableString(
                $firstItem['category_name']
                    ?? $firstItem['ticket_name']
                    ?? $row['seat_category']
                    ?? $row['category_name']
                    ?? $row['category']
                    ?? null,
            ),
            'ticket_block' => $this->nullableString(
                $firstItem['ticket_block'] ?? $row['ticket_block'] ?? $row['block'] ?? null,
            ),
            'row' => $this->nullableString($firstItem['row'] ?? $row['row'] ?? null),
            'section' => $this->nullableString($firstItem['section'] ?? $row['section'] ?? null),
            'buyer_first_name' => $this->nullableString($row['buyer_first_name'] ?? null),
            'buyer_last_name' => $this->nullableString($row['buyer_last_name'] ?? null),
            'buyer_email' => $this->nullableString($row['booking_email'] ?? $row['buyer_email'] ?? null),
            'raw_payload' => $row,
            'sandbox_sync_error' => null,
            'synced_at' => now(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $row
     */
    private function resolveQuantity(array $items, array $row): ?int
    {
        if ($items !== []) {
            $quantity = 0;
            foreach ($items as $item) {
                $quantity += max(1, (int) ($item['quantity'] ?? 1));
            }

            return $quantity > 0 ? $quantity : null;
        }

        return $this->nullableInt($row['quantity'] ?? $row['qty'] ?? null);
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

        $amount = (float) $value;
        if ($amount > 1000) {
            $amount /= 100;
        }

        return number_format($amount, 2, '.', '');
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
}
