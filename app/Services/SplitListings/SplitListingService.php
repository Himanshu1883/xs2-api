<?php

namespace App\Services\SplitListings;

use App\Contracts\MarketplaceListingPublisher;
use App\Jobs\DeleteXs2SellerListing;
use App\Models\ListingSplit;
use App\Models\ListingSplitActivity;
use App\Models\EventMapping;
use App\Models\Xs2Ticket;
use App\Services\Currency\CurrencyConversionService;
use App\Services\SellerApi\ListingSalesService;
use App\Services\SellerApi\SellerApiClient;
use App\Services\Xs2\ListingPublishValidator;
use App\Services\Xs2\Xs2SellerListingTransformer;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Auto Split Listings orchestrator.
 *
 * Sync rules (called from inventory sync when split_enabled):
 * - Stock decrease → delete trailing active splits from marketplace + mark deleted
 * - Stock 0 / low-stock unpublish → deleteAllListings (hard DELETE on SB)
 * - Ticket/event unavailable (non-stock) → disableAllSplitListings (soft)
 * - Stock increase → create only missing trailing splits
 * - Base price or increment change → recalculate prices + updateExistingListings
 * - Split quantity change → rebuildListings (delete extras / create missing / update rest)
 * - Never create duplicates; never exceed current stock; never emit 0/negative qty
 */
class SplitListingService
{
    /** @var array{pairs_only?: bool}|null */
    private ?array $publishOverrides = null;

    public function __construct(
        private readonly MarketplaceListingPublisher $publisher,
        private readonly Xs2SellerListingTransformer $transformer,
        private readonly SellerApiClient $sellerApi,
        private readonly Xs2TicketMappingStatusService $mappingStatuses,
        private readonly ListingPublishValidator $publishValidator,
        private readonly ?ListingSalesService $listingSales = null,
        private readonly ?CurrencyConversionService $currencyConversion = null,
    ) {}

    private function listingSales(): ListingSalesService
    {
        return $this->listingSales ?? app(ListingSalesService::class);
    }

    private function currencyConversion(): CurrencyConversionService
    {
        return $this->currencyConversion ?? app(CurrencyConversionService::class);
    }

    /**
     * @param  array{split_quantity?: int, price_increment_type?: string, price_increment_value?: float|string, base_price?: float|string|null, stock?: int|null}  $config
     * @return array{valid: bool, errors: list<string>}
     */
    public function validateConfiguration(Xs2Ticket $ticket, array $config = []): array
    {
        $errors = [];
        $stock = (int) ($config['stock'] ?? $ticket->stock);
        $splitQty = (int) ($config['split_quantity'] ?? $ticket->split_quantity ?? 0);
        $type = (string) ($config['price_increment_type'] ?? $ticket->price_increment_type ?? '');
        $value = $config['price_increment_value'] ?? $ticket->price_increment_value;
        $basePrice = $config['base_price'] ?? $this->basePriceMajor($ticket);

        if ($stock <= 0) {
            $errors[] = 'Current stock must be greater than zero.';
        }
        if ($splitQty < 1) {
            $errors[] = 'Split quantity must be at least 1.';
        }
        if (! in_array($type, ['percentage', 'fixed'], true)) {
            $errors[] = 'Price increment type must be percentage or fixed.';
        }
        if ($value === null || $value === '' || ! is_numeric($value) || (float) $value < 0) {
            $errors[] = 'Price increment value must be a non-negative number.';
        }
        if ($type === 'percentage' && is_numeric($value) && (float) $value > 1000) {
            $errors[] = 'Percentage increment cannot exceed 1000%.';
        }
        if ($basePrice === null || ! is_numeric($basePrice) || (float) $basePrice <= 0) {
            $errors[] = 'Base price must be greater than zero.';
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /**
     * Floor division with remainder on the last listing.
     * stock 9 / split 2 → [2,2,2,2,1]. Never loses quantity; never emits 0.
     *
     * @return list<int>
     */
    public function calculateSplitQuantities(int $stock, int $splitQuantity): array
    {
        if ($stock <= 0 || $splitQuantity <= 0) {
            return [];
        }

        $fullChunks = intdiv($stock, $splitQuantity);
        $remainder = $stock % $splitQuantity;
        $quantities = array_fill(0, $fullChunks, $splitQuantity);

        if ($remainder > 0) {
            $quantities[] = $remainder;
        }

        return $quantities;
    }

    /**
     * Percentage: L1=base, Ln=prev*(1+pct/100), round 2 decimals.
     * Fixed: Ln = base + (n-1)*increment.
     *
     * @param  list<int>  $quantities
     * @return list<array{split_order: int, quantity: int, price: float}>
     */
    public function calculatePrices(
        array $quantities,
        float $basePrice,
        string $incrementType,
        float $incrementValue,
    ): array {
        $listings = [];
        $previous = round($basePrice, 2);

        foreach (array_values($quantities) as $index => $quantity) {
            $order = $index + 1;
            if ($index === 0) {
                $price = round($basePrice, 2);
            } elseif ($incrementType === 'fixed') {
                $price = round($basePrice + ($index * $incrementValue), 2);
            } else {
                $price = round($previous * (1 + ($incrementValue / 100)), 2);
            }

            $listings[] = [
                'split_order' => $order,
                'quantity' => $quantity,
                'price' => $price,
            ];
            $previous = $price;
        }

        return $listings;
    }

    /**
     * Preview without persisting. Accepts optional overrides so the modal works before save.
     *
     * @param  array{split_quantity?: int, price_increment_type?: string, price_increment_value?: float|string, base_price?: float|string|null, stock?: int|null}  $config
     * @return array{
     *   listings: list<array{split_order: int, quantity: int, price: float}>,
     *   totals: array{listings_count: int, total_quantity: int, remaining_quantity: int, lowest_price: float|null, highest_price: float|null},
     *   config: array{stock: int, split_quantity: int, base_price: float, price_increment_type: string, price_increment_value: float}
     * }
     */
    public function preview(Xs2Ticket $ticket, array $config = []): array
    {
        $stock = (int) ($config['stock'] ?? $ticket->stock);
        $splitQty = (int) ($config['split_quantity'] ?? $ticket->split_quantity ?? 0);
        $type = (string) ($config['price_increment_type'] ?? $ticket->price_increment_type ?? 'percentage');
        $value = (float) ($config['price_increment_value'] ?? $ticket->price_increment_value ?? 0);
        $basePrice = (float) ($config['base_price'] ?? $this->basePriceMajor($ticket) ?? 0);

        $validation = $this->validateConfiguration($ticket, [
            'stock' => $stock,
            'split_quantity' => $splitQty,
            'price_increment_type' => $type,
            'price_increment_value' => $value,
            'base_price' => $basePrice,
        ]);
        if (! $validation['valid']) {
            throw ValidationException::withMessages(['split' => $validation['errors']]);
        }

        $quantities = $this->calculateSplitQuantities($stock, $splitQty);
        $listings = $this->calculatePrices($quantities, $basePrice, $type, $value);
        $prices = array_column($listings, 'price');
        $totalQty = array_sum($quantities);

        return [
            'listings' => $listings,
            'totals' => [
                'listings_count' => count($listings),
                'total_quantity' => $totalQty,
                'remaining_quantity' => max(0, $stock - $totalQty),
                'lowest_price' => $prices === [] ? null : min($prices),
                'highest_price' => $prices === [] ? null : max($prices),
            ],
            'config' => [
                'stock' => $stock,
                'split_quantity' => $splitQty,
                'base_price' => round($basePrice, 2),
                'price_increment_type' => $type,
                'price_increment_value' => round($value, 2),
            ],
        ];
    }

    /**
     * Persist config and create/update remote listings to match desired plan.
     * Call from a queued job — marketplace API work must not block the HTTP request.
     *
     * @param  array{split_quantity: int, price_increment_type: string, price_increment_value: float|string, base_price?: float|string|null, pairs_only?: bool}  $config
     * @return array{master_listing_id: int, listings_count: int, created: int, updated: int, deleted: int}
     */
    public function publishListings(Xs2Ticket $ticket, array $config): array
    {
        $this->publishOverrides = array_key_exists('pairs_only', $config)
            ? ['pairs_only' => (bool) $config['pairs_only']]
            : null;

        try {
            return $this->publishListingsInternal($ticket, $config);
        } finally {
            $this->publishOverrides = null;
        }
    }

    /**
     * @param  array{split_quantity: int, price_increment_type: string, price_increment_value: float|string, base_price?: float|string|null, pairs_only?: bool}  $config
     * @return array{master_listing_id: int, listings_count: int, created: int, updated: int, deleted: int}
     */
    private function publishListingsInternal(Xs2Ticket $ticket, array $config): array
    {
        $preview = $this->preview($ticket, $config + ['stock' => $ticket->stock]);

        // Retire the 1:1 seller listing before enabling split mode so the
        // disable path does not treat this ticket as an active split master.
        $this->retireSingleListingIfPresent($ticket->fresh());

        DB::transaction(function () use ($ticket, $config): void {
            $locked = Xs2Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'split_enabled' => true,
                'split_quantity' => (int) $config['split_quantity'],
                'price_increment_type' => (string) $config['price_increment_type'],
                'price_increment_value' => (float) $config['price_increment_value'],
                'split_sync_status' => 'publishing',
                'split_sync_error' => null,
                'sync_status' => 'processing',
                'sync_error' => null,
            ]);
        });

        $ticket->refresh();

        try {
            $result = $this->applyDesiredPlan($ticket->fresh(), $preview['listings']);
            $this->logActivity($ticket, null, 'publish', 'Split publish completed.', $result + [
                'listings_count' => count($preview['listings']),
            ]);

            return [
                'master_listing_id' => $ticket->id,
                'listings_count' => count($preview['listings']),
                ...$result,
            ];
        } catch (\Throwable $e) {
            $this->markFailedFromException($ticket->fresh(), $e);
            throw $e;
        }
    }

    /**
     * Reconcile active splits with current stock/price/config.
     *
     * Sync rules:
     * - Stock decrease → delete trailing listings
     * - Stock 0 / low-stock unpublish → deleteAllListings (hard DELETE on SB)
 * - Ticket/event unavailable (non-stock) → disableAllSplitListings (soft)
     * - Stock increase → create only missing
     * - Base price or increment change → update existing prices
     * - Split quantity change → rebuild (delete extras + create missing + update)
     */
    public function syncListings(Xs2Ticket $ticket): array
    {
        $ticket->refresh();

        if (! $ticket->split_enabled) {
            return ['action' => 'skipped', 'reason' => 'split_disabled', 'created' => 0, 'updated' => 0, 'deleted' => 0, 'disabled' => 0];
        }

        if ($ticket->stock <= 0) {
            $result = $this->deleteAllListings($ticket);

            return ['action' => 'deleted_all', 'reason' => 'zero_stock', ...$result];
        }

        if ($this->shouldDeleteForLowStock($ticket)) {
            $result = $this->deleteAllListings($ticket);

            return ['action' => 'deleted_all', 'reason' => 'low_stock', ...$result];
        }

        if ($ticket->ticket_status !== 'available'
            || ! ($ticket->xs2Event?->isSellable() ?? false)) {
            $result = $this->disableAllSplitListings($ticket);

            return ['action' => 'disabled_all', 'reason' => 'unavailable', ...$result];
        }

        $desired = $this->preview($ticket)['listings'];

        DB::transaction(function () use ($ticket): void {
            Xs2Ticket::query()->whereKey($ticket->id)->lockForUpdate()->update([
                'split_sync_status' => 'syncing',
                'split_sync_error' => null,
                'sync_status' => 'processing',
            ]);
        });

        try {
            $result = $this->applyDesiredPlan($ticket->fresh(), $desired);
            $this->logActivity($ticket, null, 'sync', 'Split sync completed.', $result);

            return ['action' => 'synced', ...$result];
        } catch (\Throwable $e) {
            $this->markFailedFromException($ticket->fresh(), $e);
            throw $e;
        }
    }

    /**
     * @param  list<array{split_order: int, quantity: int, price: float}>  $desired
     * @return array{created: int, updated: int, deleted: int}
     */
    public function createMissingListings(Xs2Ticket $ticket, array $desired): array
    {
        $created = 0;
        $existing = $this->activeSplits($ticket)->keyBy('split_order');

        foreach ($desired as $plan) {
            $split = $existing->get($plan['split_order']);
            if ($split && $split->seatsbroker_listing_id) {
                continue;
            }
            $this->createSplitListing($ticket, $plan);
            $created++;
        }

        return ['created' => $created, 'updated' => 0, 'deleted' => 0];
    }

    /**
     * @param  list<array{split_order: int, quantity: int, price: float}>  $desired
     * @return array{created: int, updated: int, deleted: int}
     */
    public function deleteExtraListings(Xs2Ticket $ticket, array $desired): array
    {
        $desiredOrders = collect($desired)->pluck('split_order')->all();
        $deleted = 0;

        foreach ($this->activeSplits($ticket) as $split) {
            if (in_array($split->split_order, $desiredOrders, true)) {
                continue;
            }
            $this->deleteSplitListing($ticket, $split);
            $deleted++;
        }

        return ['created' => 0, 'updated' => 0, 'deleted' => $deleted];
    }

    /**
     * @param  list<array{split_order: int, quantity: int, price: float}>  $desired
     * @return array{created: int, updated: int, deleted: int}
     */
    public function updateExistingListings(Xs2Ticket $ticket, array $desired): array
    {
        $updated = 0;
        $byOrder = collect($desired)->keyBy('split_order');

        foreach ($this->activeSplits($ticket) as $split) {
            $plan = $byOrder->get($split->split_order);
            if (! $plan) {
                continue;
            }
            if (! $split->seatsbroker_listing_id) {
                $this->createSplitListing($ticket, $plan);
                $updated++;

                continue;
            }
            if ($this->splitMatchesPlan($ticket, $split, $plan)) {
                continue;
            }
            if ($this->updateSplitListing($ticket, $split, $plan)) {
                $updated++;
            }
        }

        return ['created' => 0, 'updated' => $updated, 'deleted' => 0];
    }

    /**
     * User-initiated delete of one sublisting cascades to remove every active split
     * for the master ticket on Seats Broker and locally.
     */
    public function deleteOneSplitListingCascade(Xs2Ticket $ticket, ListingSplit $split): array
    {
        if ((int) $split->master_listing_id !== (int) $ticket->id) {
            throw ValidationException::withMessages([
                'split' => ['This sublisting does not belong to the specified master ticket.'],
            ]);
        }

        if ($split->status !== 'active') {
            throw ValidationException::withMessages([
                'split' => ['This sublisting is not active.'],
            ]);
        }

        $activeCount = $this->activeSplits($ticket)->count();
        $this->logActivity($ticket, $split, 'delete_cascade', 'Sublisting delete triggered cascade removal.', [
            'trigger_split_id' => $split->id,
            'trigger_split_order' => $split->split_order,
            'active_splits' => $activeCount,
        ]);

        return $this->deleteAllListings($ticket);
    }

    /**
     * Soft-disable every active split on Seats Broker without removing local rows.
     * Used when stock hits zero, the ticket becomes unavailable, or low-stock unpublish applies.
     *
     * @return array{created: int, updated: int, deleted: int, disabled: int}
     */
    public function shouldDeleteForLowStock(Xs2Ticket $ticket): bool
    {
        $unpublishStockMax = max(0, (int) config('xs2.split_listings.unpublish_stock_max', 0));

        return $unpublishStockMax > 0
            && $ticket->stock > 0
            && $ticket->stock <= $unpublishStockMax;
    }

    public function disableAllSplitListings(Xs2Ticket $ticket): array
    {
        $disabled = 0;
        $locked = Xs2Ticket::query()->whereKey($ticket->id)->firstOrFail();

        foreach ($this->activeSplits($locked) as $split) {
            if (! $split->seatsbroker_listing_id) {
                continue;
            }

            try {
                $payload = [
                    'ticket_id' => $split->seatsbroker_listing_id,
                    'match_id' => $ticket->xs2Event?->mapping?->m_id,
                    'seller_id' => $this->sellerApi->sellerId(),
                ];
                $result = $this->publisher->disable($split->seatsbroker_listing_id, $payload);
                $split->update([
                    'last_request' => $payload,
                    'last_response' => $result['response'],
                    'last_error' => null,
                    'sync_status' => 'synced',
                    'last_synced_at' => now(),
                ]);
                $disabled++;
                $this->logActivity($locked, $split, 'disable', 'Split listing disabled.');
            } catch (\Throwable $e) {
                $split->update(['last_error' => mb_substr($e->getMessage(), 0, 5000)]);
                $this->logActivity($locked, $split, 'disable_fail', $this->formatFailureMessage($e), [
                    'exception' => get_class($e),
                ]);
                Log::channel(config('services.seller_api.log_channel', 'stack'))->warning(
                    'Split listing disable failed.',
                    ['listing_split_id' => $split->id, 'error' => $e->getMessage()]
                );
            }
        }

        $locked->update([
            'split_sync_status' => 'completed',
            'split_sync_error' => null,
            'sync_status' => 'synced',
            'sync_error' => null,
        ]);

        $this->logActivity($locked, null, 'disable_all', 'All split listings disabled.', [
            'disabled' => $disabled,
        ]);

        return ['created' => 0, 'updated' => 0, 'deleted' => 0, 'disabled' => $disabled];
    }

    public function deleteAllListings(Xs2Ticket $ticket): array
    {
        $deleted = 0;
        $locked = Xs2Ticket::query()->whereKey($ticket->id)->firstOrFail();

        foreach ($this->activeSplits($locked) as $split) {
            $this->deleteSplitListing($locked, $split);
            $deleted++;
        }

        $locked->update([
            'split_enabled' => false,
            'split_sync_status' => 'completed',
            'split_sync_error' => null,
            'sync_status' => 'synced',
        ]);

        $this->logActivity($locked, null, 'delete_all', 'All split listings deleted.', [
            'deleted' => $deleted,
        ]);

        return ['created' => 0, 'updated' => 0, 'deleted' => $deleted];
    }

    /**
     * Full rebuild when split quantity changes: delete extras, create missing, update rest.
     *
     * @param  list<array{split_order: int, quantity: int, price: float}>|null  $desired
     */
    public function rebuildListings(Xs2Ticket $ticket, ?array $desired = null): array
    {
        $desired ??= $this->preview($ticket)['listings'];
        $result = $this->applyDesiredPlan($ticket->fresh(), $desired);
        $this->logActivity($ticket, null, 'rebuild', 'Split listings rebuilt.', $result);

        return $result;
    }

    /**
     * Status + config for admin UI.
     *
     * @return array<string, mixed>
     */
    public function status(Xs2Ticket $ticket): array
    {
        $ticket->loadMissing(['listingSplits' => fn ($q) => $q->orderBy('split_order')]);
        $active = $ticket->listingSplits->where('status', 'active')->values();

        return [
            'master_listing_id' => $ticket->id,
            'split_enabled' => (bool) $ticket->split_enabled,
            'split_quantity' => $ticket->split_quantity,
            'price_increment_type' => $ticket->price_increment_type,
            'price_increment_value' => $ticket->price_increment_value !== null
                ? (float) $ticket->price_increment_value
                : null,
            'base_price' => $this->basePriceMajor($ticket),
            'stock' => (int) $ticket->stock,
            'split_sync_status' => $ticket->split_sync_status ?? 'idle',
            'split_sync_error' => $ticket->split_sync_error,
            'listings' => $active->map(fn (ListingSplit $split): array => [
                'id' => $split->id,
                'split_order' => $split->split_order,
                'quantity' => $split->quantity,
                'price' => (float) $split->price,
                'seller_price' => $ticket->xs2Event?->mapping
                    ? $this->sellerMajorPriceForPlan($ticket, $ticket->xs2Event->mapping, (float) $split->price)
                    : (float) $split->price,
                'seller_currency' => $this->sellerCurrencyForTicket($ticket),
                'seatsbroker_listing_id' => $split->seatsbroker_listing_id,
                'xs2_listing_id' => $split->xs2ListingId(),
                'status' => $split->status,
                'sync_status' => $split->sync_status,
                'last_synced_at' => $split->last_synced_at,
                'last_error' => $split->last_error,
            ])->all(),
        ];
    }

    /**
     * Persist config without publishing (modal draft / update settings).
     *
     * @param  array{split_enabled?: bool, split_quantity?: int, price_increment_type?: string, price_increment_value?: float|string}  $config
     */
    public function saveConfiguration(Xs2Ticket $ticket, array $config): Xs2Ticket
    {
        if (! empty($config['split_enabled'])) {
            $validation = $this->validateConfiguration($ticket, $config);
            if (! $validation['valid']) {
                throw ValidationException::withMessages(['split' => $validation['errors']]);
            }
        }

        $ticket->update([
            'split_enabled' => (bool) ($config['split_enabled'] ?? $ticket->split_enabled),
            'split_quantity' => $config['split_quantity'] ?? $ticket->split_quantity,
            'price_increment_type' => $config['price_increment_type'] ?? $ticket->price_increment_type,
            'price_increment_value' => $config['price_increment_value'] ?? $ticket->price_increment_value,
        ]);

        return $ticket->fresh();
    }

    public function markFailed(Xs2Ticket $ticket, string $message): void
    {
        $message = $this->normalizeActivityMessage($message);

        $ticket->update([
            'split_sync_status' => 'failed',
            'split_sync_error' => mb_substr($message, 0, 5000),
            'sync_status' => 'failed',
            'sync_error' => mb_substr($message, 0, 5000),
        ]);
        $this->logActivity($ticket, null, 'sync_fail', $message);
    }

    public function markFailedFromException(Xs2Ticket $ticket, \Throwable $exception): void
    {
        $this->markFailed($ticket, $this->formatFailureMessage($exception));
    }

    public function formatFailureMessage(\Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            $errors = collect($exception->errors())->flatten()->filter()->values()->all();
            if ($errors !== []) {
                return implode(' ', array_map(strval(...), $errors));
            }
        }

        return trim($exception->getMessage()) !== ''
            ? $exception->getMessage()
            : 'Split listing operation failed.';
    }

    /**
     * @param  list<array{split_order: int, quantity: int, price: float}>  $desired
     * @return array{created: int, updated: int, deleted: int}
     */
    private function applyDesiredPlan(Xs2Ticket $ticket, array $desired): array
    {
        $deleted = $this->deleteExtraListings($ticket, $desired)['deleted'];
        $created = $this->createMissingListings($ticket, $desired)['created'];
        $updated = $this->updateExistingListings($ticket, $desired)['updated'];

        $ticket->update([
            'split_sync_status' => 'completed',
            'split_sync_error' => null,
            'sync_status' => 'synced',
            'sync_error' => null,
        ]);

        if (Schema::hasTable('xs2_ticket_mapping_states')) {
            $state = $this->mappingStatuses->resolve($ticket);
            if ($this->mappingStatuses->isManualPublishable($state->mapping_status)) {
                $state->update(['mapping_status' => 'published', 'mapping_error' => null]);
            }
        }

        return compact('created', 'updated', 'deleted');
    }

    /** @param  array{split_order: int, quantity: int, price: float}  $plan */
    private function createSplitListing(Xs2Ticket $ticket, array $plan): ListingSplit
    {
        $reference = $this->splitReference($ticket, $plan['split_order']);

        $split = ListingSplit::query()->updateOrCreate(
            [
                'master_listing_id' => $ticket->id,
                'split_order' => $plan['split_order'],
            ],
            [
                'seller_reference' => $reference,
                'quantity' => $plan['quantity'],
                'price' => $plan['price'],
                'status' => 'active',
                'seatsbroker_listing_id' => null,
                'last_payload_hash' => null,
                'sync_status' => 'processing',
                'last_error' => null,
            ]
        );

        $payload = $this->buildPayload($ticket, $plan, $reference, $split);
        $hash = $this->payloadHash($payload);

        try {
            $result = $this->publisher->create($payload, $reference);
            $split->update([
                'seatsbroker_listing_id' => $result['listing_id'],
                'sync_status' => 'synced',
                'last_payload_hash' => $hash,
                'last_request' => $payload,
                'last_response' => $result['response'],
                'last_error' => null,
                'last_synced_at' => now(),
                'status' => 'active',
            ]);
            $this->logActivity($ticket, $split, 'create', 'Split listing created.', [
                'seatsbroker_listing_id' => $result['listing_id'],
                'quantity' => $plan['quantity'],
                'price' => $plan['price'],
            ]);
        } catch (\Throwable $e) {
            $split->update([
                'sync_status' => 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 5000),
                'last_request' => $payload,
            ]);
            $this->logActivity($ticket, $split, 'create_fail', $this->formatFailureMessage($e), [
                'exception' => get_class($e),
            ]);
            throw $e;
        }

        return $split->fresh();
    }

    /** @param  array{split_order: int, quantity: int, price: float}  $plan */
    private function updateSplitListing(Xs2Ticket $ticket, ListingSplit $split, array $plan): bool
    {
        $reference = $split->seller_reference ?: $this->splitReference($ticket, $plan['split_order']);
        $payload = $this->buildPayload($ticket, $plan, $reference, $split);
        $hash = $this->payloadHash($payload);

        if (! $split->seatsbroker_listing_id) {
            $this->createSplitListing($ticket, $plan);

            return true;
        }

        if ($split->last_payload_hash === $hash && $split->sync_status === 'synced') {
            $split->update([
                'quantity' => $plan['quantity'],
                'price' => $plan['price'],
            ]);

            return false;
        }

        $split->update([
            'quantity' => $plan['quantity'],
            'price' => $plan['price'],
            'sync_status' => 'processing',
            'seller_reference' => $reference,
        ]);

        try {
            $result = $this->publisher->update($split->seatsbroker_listing_id, $payload);
            $split->update([
                'sync_status' => 'synced',
                'last_payload_hash' => $hash,
                'last_request' => $payload,
                'last_response' => $result['response'],
                'last_error' => null,
                'last_synced_at' => now(),
            ]);
            $this->logActivity($ticket, $split, 'update', 'Split listing updated.', [
                'quantity' => $plan['quantity'],
                'price' => $plan['price'],
            ]);
        } catch (\Throwable $e) {
            $split->update([
                'sync_status' => 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 5000),
                'last_request' => $payload,
            ]);
            $this->logActivity($ticket, $split, 'update_fail', $this->formatFailureMessage($e), [
                'exception' => get_class($e),
            ]);
            throw $e;
        }

        return true;
    }

    private function deleteSplitListing(Xs2Ticket $ticket, ListingSplit $split): void
    {
        if ($split->seatsbroker_listing_id) {
            try {
                $deletePayload = [
                    'ticket_id' => $split->seatsbroker_listing_id,
                    'match_id' => $ticket->xs2Event?->mapping?->m_id,
                    'seller_id' => $this->sellerApi->sellerId(),
                ];
                $result = $this->publisher->delete($split->seatsbroker_listing_id, $deletePayload);
                $split->update([
                    'last_request' => $deletePayload,
                    'last_response' => $result['response'],
                    'last_error' => null,
                ]);
            } catch (\Throwable $e) {
                $split->update(['last_error' => mb_substr($e->getMessage(), 0, 5000)]);
                $this->logActivity($ticket, $split, 'delete_fail', $this->formatFailureMessage($e), [
                    'exception' => get_class($e),
                ]);
                Log::channel(config('services.seller_api.log_channel', 'stack'))->warning(
                    'Split listing delete failed; marking deleted locally.',
                    ['listing_split_id' => $split->id, 'error' => $e->getMessage()]
                );
            }
        }

        $split->update([
            'status' => 'deleted',
            'seatsbroker_listing_id' => null,
            'last_payload_hash' => null,
            'sync_status' => 'synced',
            'last_synced_at' => now(),
        ]);
        $this->logActivity($ticket, $split, 'delete', 'Split listing deleted.');
    }

    private function retireSingleListingIfPresent(Xs2Ticket $ticket): void
    {
        $mapping = $ticket->listingMapping;
        if (! $mapping?->seller_listing_id) {
            return;
        }

        // Clear the 1:1 listing so normal publish does not collide with splits.
        DeleteXs2SellerListing::dispatchSync($ticket->id);
        $this->logActivity($ticket, null, 'retire_single', 'Retired single listing before split publish.');
    }

    /**
     * @param  array{split_order: int, quantity: int, price: float}  $plan
     * @return array<string, mixed>
     */
    private function buildPayload(Xs2Ticket $ticket, array $plan, string $reference, ?ListingSplit $split = null): array
    {
        $with = ['xs2Event.mapping'];
        if (Schema::hasTable('match_info')) {
            $with[] = 'xs2Event.mapping.event';
        }
        if (Schema::hasTable('xs2_ticket_mapping_states')) {
            $with[] = 'mappingState.categoryMapping.details';
        }
        $ticket->loadMissing($with);
        $mapping = $ticket->xs2Event?->mapping;
        if (! $mapping || ! $mapping->m_id) {
            throw ValidationException::withMessages(['ticket' => ['Event mapping is required before publishing splits.']]);
        }

        $mappingState = null;
        if (Schema::hasTable('xs2_ticket_mapping_states')) {
            $mappingState = $this->mappingStatuses->resolve($ticket)->loadMissing('categoryMapping.details');
        }

        $transformOverrides = null;
        if (($this->publishOverrides['pairs_only'] ?? null) === true) {
            $transformOverrides = ['pairs_only' => true];
        }

        $this->publishValidator->validateForPublish($ticket, $mapping, $mappingState);

        $payload = $mappingState
            ? $this->transformer->transform($ticket, $mapping, $mappingState, $transformOverrides)
            : $this->transformer->transform($ticket, $mapping, null, $transformOverrides);

        $this->publishValidator->validatePayload($payload);

        $planned = max(0, (int) $plan['quantity']);
        $remaining = $split !== null
            ? $this->listingSales()->remainingQuantityForSplit($split, $planned)
            : $planned;

        $payload['seller_reference'] = $reference;
        $payload['quantity'] = $remaining;
        $payload['price'] = $this->sellerPriceFromMajor(
            $this->sellerMajorPriceForPlan($ticket, $mapping, (float) $plan['price'])
        );
        $payload['status'] = $remaining > 0 ? '1' : '0';

        return $payload;
    }

    private function sellerMajorPriceForPlan(Xs2Ticket $ticket, EventMapping $mapping, float $planPriceMajor): float
    {
        $ticketCurrency = strtoupper(trim((string) ($ticket->currency_code ?? '')));
        $eventCurrency = $this->currencyConversion()->eventCurrency($mapping);
        $converter = $this->currencyConversion();

        if ($ticketCurrency === '' || ! $converter->needsConversion($ticketCurrency, $eventCurrency)) {
            return $planPriceMajor;
        }

        return $converter->convertMajor(
            $planPriceMajor,
            $ticketCurrency,
            $converter->normalizeCurrency($eventCurrency) ?? $ticketCurrency,
        );
    }

    private function sellerCurrencyForTicket(Xs2Ticket $ticket): ?string
    {
        $mapping = $ticket->xs2Event?->mapping;
        if ($mapping === null) {
            return $this->currencyConversion()->normalizeCurrency($ticket->currency_code);
        }

        $ticketCurrency = $this->currencyConversion()->normalizeCurrency($ticket->currency_code);
        $eventCurrency = $this->currencyConversion()->eventCurrency($mapping);

        if ($this->currencyConversion()->needsConversion((string) $ticketCurrency, $eventCurrency)) {
            return $this->currencyConversion()->normalizeCurrency($eventCurrency);
        }

        return $ticketCurrency;
    }

    private function sellerPriceFromMajor(float $major): int|string
    {
        $divisor = max(1, (int) config('services.xs2.minor_unit_divisor', 100));
        $minor = (int) round($major * $divisor);

        if (config('services.seller_api.price_uses_minor_units')) {
            return $minor;
        }

        $whole = intdiv($minor, $divisor);
        $fraction = str_pad((string) ($minor % $divisor), strlen((string) ($divisor - 1)), '0', STR_PAD_LEFT);

        return $whole.'.'.$fraction;
    }

    private function splitReference(Xs2Ticket $ticket, int $order): string
    {
        $prefix = (string) config('services.seller_api.external_reference_prefix', 'XS2-');

        return $prefix.$ticket->external_ticket_id.'-S'.$order;
    }

    /**
     * True when qty/price match the plan and the last pushed payload hash is current.
     *
     * @param  array{split_order: int, quantity: int, price: float}  $plan
     */
    private function splitMatchesPlan(Xs2Ticket $ticket, ListingSplit $split, array $plan): bool
    {
        if ((int) $split->quantity !== (int) $plan['quantity']) {
            return false;
        }
        if (round((float) $split->price, 2) !== round((float) $plan['price'], 2)) {
            return false;
        }
        if (! $split->seatsbroker_listing_id || $split->sync_status !== 'synced') {
            return false;
        }

        $reference = $split->seller_reference ?: $this->splitReference($ticket, $plan['split_order']);
        $payload = $this->buildPayload($ticket, $plan, $reference, $split);
        $hash = $this->payloadHash($payload);

        return $split->last_payload_hash === $hash;
    }

    /** @param  array<string, mixed>  $payload */
    private function payloadHash(array $payload): string
    {
        $stable = $payload;
        ksort($stable);

        return hash('sha256', json_encode($stable, JSON_THROW_ON_ERROR));
    }

    private function basePriceMajor(Xs2Ticket $ticket): ?float
    {
        $minor = $ticket->net_rate ?? $ticket->face_value;
        if ($minor === null) {
            return null;
        }
        $divisor = max(1, (int) config('services.xs2.minor_unit_divisor', 100));

        return round(((int) $minor) / $divisor, 2);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, ListingSplit> */
    private function activeSplits(Xs2Ticket $ticket)
    {
        return ListingSplit::query()
            ->where('master_listing_id', $ticket->id)
            ->where('status', 'active')
            ->orderBy('split_order')
            ->get();
    }

    /** @param  array<string, mixed>|null  $metadata */
    private function logActivity(
        Xs2Ticket $ticket,
        ?ListingSplit $split,
        string $action,
        string $message,
        ?array $metadata = null,
    ): void {
        ListingSplitActivity::query()->create([
            'master_listing_id' => $ticket->id,
            'listing_split_id' => $split?->id,
            'action' => $action,
            'message' => $this->normalizeActivityMessage($message),
            'metadata' => $metadata,
        ]);
    }

    private function normalizeActivityMessage(string $message): string
    {
        $message = trim($message);

        if ($message === '') {
            return '';
        }

        // Keep activity rows compact even after widening the column to TEXT.
        return mb_substr($message, 0, 4000);
    }
}
