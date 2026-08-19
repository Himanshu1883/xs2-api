<?php

namespace App\Services\Xs2;

use App\Exceptions\Integrations\ListingTransformationException;
use App\Models\EventMapping;
use App\Models\Xs2CategoryMapping;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\SellerApi\ListingSalesService;
use App\Services\SellerApi\SellerApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Xs2SellerListingTransformer
{
    public function __construct(
        private readonly SellerApiClient $sellerApi,
        private readonly ?ListingSalesService $listingSales = null,
    ) {}

    private function listingSales(): ListingSalesService
    {
        return $this->listingSales ?? app(ListingSalesService::class);
    }

    /**
     * @param  array{quantity?: int, pairs_only?: bool}|null  $overrides
     */
    public function transform(
        Xs2Ticket $ticket,
        EventMapping $mapping,
        ?Xs2TicketMappingState $mappingState = null,
        ?array $overrides = null,
    ): array {
        if (! $mapping->m_id) {
            throw new ListingTransformationException('Mapped local event ID is missing.');
        }
        if ($mappingState && ! in_array($mappingState->mapping_status, Xs2TicketMappingStatusService::MANUAL_PUBLISH_STATUSES, true)) {
            throw new ListingTransformationException('XS2 ticket is not ready to publish because required mappings are incomplete.');
        }

        $ticketForTransform = $this->ticketWithOverrides($ticket, $overrides);

        $matchId = (int) $mapping->m_id;
        $catalog = $this->catalog($matchId);
        $remaining = array_key_exists('quantity', $overrides ?? [])
            ? max(0, (int) $overrides['quantity'])
            : $this->listingSales()->remainingQuantityForTicket($ticket);
        $active = $ticket->ticket_status === 'available'
            && $remaining > 0
            && (! $ticket->ticket_valid_until || $ticket->ticket_valid_until->isFuture())
            && ($mapping->xs2Event?->isSellable() ?? false);
        $listingPrice = (int) ($ticket->package_price ?? $ticket->net_rate ?? $ticket->face_value ?? 0);
        $faceValue = (int) ($ticket->face_value ?? $ticket->net_rate ?? 0);

        return [
            // This stable supplier-facing reference is also used as the
            // idempotency key for listing creation retries.
            'seller_reference' => $this->reference($ticket, $matchId, $mappingState),
            'match_id' => $matchId,
            'ticket_type' => $this->lookupTicketTypeId(
                data_get($catalog, 'ticket_type', []),
                $this->resolveSellerTicketType($ticketForTransform)
            ),
            'quantity' => $active ? $remaining : 0,
            'ticket_category' => $this->lookupCategoryId(
                data_get($catalog, 'category', []),
                $this->required($ticket->category_name, 'XS2 ticket category'),
                $mappingState,
            ),
            'ticket_block' => $this->ticketBlock($ticket, $mappingState),
            'ticket_row' => (string) data_get($ticket->options, 'ticket_row', ''),
            'home_town' => 0,
            'price_type' => $this->required($ticket->currency_code, 'XS2 ticket currency'),
            'price' => $this->sellerAmount($listingPrice),
            'ticket_details' => $this->ticketDetails($ticket),
            'split_type' => $this->lookupId(
                data_get($catalog, 'split_type', []),
                'split_name',
                $this->sellerSplitType($ticketForTransform)
            ),
            'facevalue' => $this->sellerAmount($faceValue),
            'seller_id' => $this->sellerApi->sellerId(),
            'status' => $active ? '1' : '0',
        ];
    }

    /** @return array<string, mixed> */
    private function catalog(int $matchId): array
    {
        $catalog = Cache::remember(
            "seller-api:ticket-dropdown:{$matchId}",
            now()->addHour(),
            fn (): array => $this->sellerApi->ticketDropdown($matchId)
        );

        $result = data_get($catalog, 'result');
        if (! is_array($result)) {
            throw new ListingTransformationException('Seller API ticket dropdown response is missing result data.');
        }

        $ticketTypes = data_get($result, 'ticket_type');
        if (! is_array($ticketTypes) || $ticketTypes === []) {
            Cache::forget("seller-api:ticket-dropdown:{$matchId}");

            throw new ListingTransformationException(
                'Seller API ticket dropdown returned no ticket_type options for match '.$matchId.'.'
            );
        }

        $categories = data_get($result, 'category');
        if (! is_array($categories) || $categories === []) {
            Cache::forget("seller-api:ticket-dropdown:{$matchId}");

            throw new ListingTransformationException(
                'Seller API ticket dropdown returned no category options for match '.$matchId.'.'
            );
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $items */
    private function lookupCategoryId(array $items, string $rawName, ?Xs2TicketMappingState $mappingState): int
    {
        $categoryMapping = $mappingState?->categoryMapping;
        if ($categoryMapping !== null) {
            foreach ($this->categorySeatIds($categoryMapping) as $seatId) {
                $id = $this->findIdByNumericId($items, $seatId);
                if ($id !== null) {
                    return $id;
                }
            }
        }

        $tried = [];
        foreach ($this->categoryCandidates($rawName, $mappingState) as $candidate) {
            $tried[] = $candidate;
            $id = $this->findId($items, 'category_name', $candidate);
            if ($id !== null) {
                return $id;
            }
        }

        $available = collect($items)
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): string => trim((string) data_get($item, 'category_name', '')))
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');

        throw new ListingTransformationException(
            'Could not resolve XS2 category "'.$rawName.'" to a Seats Broker ticket_category ID.'
            .($available !== '' ? ' Available SB categories: '.$available.'.' : '')
            .($tried !== [] ? ' Tried: '.implode(', ', $tried).'.' : '')
        );
    }

    /** @return list<int> */
    private function categorySeatIds(Xs2CategoryMapping $categoryMapping): array
    {
        $seatIds = [];
        if ($categoryMapping->stadium_seat_id !== null) {
            $seatIds[] = (int) $categoryMapping->stadium_seat_id;
        }

        if ($categoryMapping->relationLoaded('details')) {
            foreach ($categoryMapping->details as $detail) {
                if ($detail->stadium_seat_id !== null) {
                    $seatIds[] = (int) $detail->stadium_seat_id;
                }
            }
        }

        foreach ($categoryMapping->candidate_scores ?? [] as $candidate) {
            $seatId = data_get($candidate, 'stadium_seat_id');
            if (is_numeric($seatId)) {
                $seatIds[] = (int) $seatId;
            }
        }

        return array_values(array_unique($seatIds));
    }

    /**
     * @return list<string>
     */
    private function categoryCandidates(string $rawName, ?Xs2TicketMappingState $mappingState): array
    {
        $names = [$rawName];

        $categoryMapping = $mappingState?->categoryMapping;
        if ($categoryMapping !== null) {
            if ($categoryMapping->relationLoaded('details')) {
                foreach ($categoryMapping->details as $detail) {
                    $names[] = (string) ($detail->stadium_seat_name ?: $detail->name ?: '');
                }
            }

            foreach ($categoryMapping->candidate_scores ?? [] as $candidate) {
                $names[] = (string) data_get($candidate, 'stadium_seat_name', '');
            }
        }

        $seen = [];
        $result = [];
        foreach ($names as $name) {
            if ($name === '') {
                continue;
            }
            $key = $this->normalise($name);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $name;
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $items */
    private function lookupId(array $items, string $nameKey, string $expected): int
    {
        $id = $this->findId($items, $nameKey, $expected);

        if ($id === null) {
            throw new ListingTransformationException("Seller API ticket dropdown has no {$nameKey} value for {$this->normalise($expected)}.");
        }

        return $id;
    }

    /** @param list<array<string, mixed>> $items */
    private function findId(array $items, string $nameKey, string $expected): ?int
    {
        $expected = $this->normalise($expected);
        $item = collect($items)->first(
            fn ($item): bool => is_array($item) && $this->normalise((string) data_get($item, $nameKey, '')) === $expected
        );
        $id = data_get($item, 'id');

        return (is_numeric($id) && (int) $id >= 1) ? (int) $id : null;
    }

    /** @return array{id: int, name: string} */
    private function resolveSellerTicketType(Xs2Ticket $ticket): array
    {
        $rawType = (string) ($ticket->ticket_type ?? '');
        $xs2Type = Str::lower($rawType);
        $xs2Key = $this->normalise($rawType);
        $xs2Types = config('seller-api.ticket_types.xs2', []);
        $mapped = data_get($xs2Types, $xs2Type) ?? ($xs2Key !== '' ? data_get($xs2Types, $xs2Key) : null);

        if (is_array($mapped) && isset($mapped['id'], $mapped['name'])) {
            return ['id' => (int) $mapped['id'], 'name' => (string) $mapped['name']];
        }

        if ($xs2Type !== '') {
            \Log::channel(config('services.xs2.log_channel'))->warning('Unknown XS2 ticket type; using safe Seller API fallback.', [
                'provider' => 'xs2event',
                'external_ticket_id' => $ticket->external_ticket_id,
                'ticket_type' => $ticket->ticket_type,
            ]);
        }

        $default = config('seller-api.ticket_types.default', ['id' => 2, 'name' => 'E-Tickets']);

        return [
            'id' => (int) data_get($default, 'id', 2),
            'name' => (string) data_get($default, 'name', 'E-Tickets'),
        ];
    }

    /** @param list<array<string, mixed>> $items @param array{id: int, name: string} $mapping */
    private function lookupTicketTypeId(array $items, array $mapping): int
    {
        $id = $this->findIdByNumericId($items, $mapping['id']);
        if ($id !== null) {
            return $id;
        }

        foreach ($this->ticketTypeNameCandidates($mapping) as $candidate) {
            $id = $this->findId($items, 'ticket_type_name', $candidate);
            if ($id !== null) {
                return $id;
            }
        }

        $available = collect($items)
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): string => trim((string) data_get($item, 'ticket_type_name', '')))
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');

        throw new ListingTransformationException(
            'Seller API ticket dropdown has no ticket_type_name value for '.$this->normalise($mapping['name'])
            .($available !== '' ? '. Available ticket types: '.$available : '.')
        );
    }

    /**
     * @param  array{id: int, name: string}  $mapping
     * @return list<string>
     */
    private function ticketTypeNameCandidates(array $mapping): array
    {
        $names = [(string) $mapping['name']];
        if ((int) $mapping['id'] === 2) {
            $names[] = 'E-Ticket';
            $names[] = 'Etickets';
        }

        foreach (config('seller-api.ticket_types.xs2', []) as $entry) {
            if (! is_array($entry) || (int) data_get($entry, 'id') !== (int) $mapping['id']) {
                continue;
            }
            $names[] = (string) data_get($entry, 'name', '');
        }

        $seen = [];
        $result = [];
        foreach ($names as $name) {
            if ($name === '') {
                continue;
            }
            $key = $this->normalise($name);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $name;
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $items */
    private function findIdByNumericId(array $items, int $expectedId): ?int
    {
        $item = collect($items)->first(function ($item) use ($expectedId): bool {
            if (! is_array($item)) {
                return false;
            }
            foreach (['id', 'ticket_type_id'] as $key) {
                if (is_numeric(data_get($item, $key)) && (int) data_get($item, $key) === $expectedId) {
                    return true;
                }
            }

            return false;
        });
        $id = data_get($item, 'id', data_get($item, 'ticket_type_id'));

        return (is_numeric($id) && (int) $id >= 1) ? (int) $id : null;
    }

    /** @param  array{quantity?: int, pairs_only?: bool}|null  $overrides */
    private function ticketWithOverrides(Xs2Ticket $ticket, ?array $overrides): Xs2Ticket
    {
        if ($overrides === null || ! array_key_exists('pairs_only', $overrides)) {
            return $ticket;
        }

        $clone = clone $ticket;
        $flags = $ticket->flags ?? [];
        if (($overrides['pairs_only'] ?? false) === true) {
            if (! in_array(Xs2Ticket::FLAG_PAIRS_ONLY, $flags, true)) {
                $flags[] = Xs2Ticket::FLAG_PAIRS_ONLY;
            }
        } else {
            $flags = array_values(array_filter(
                $flags,
                fn (string $flag): bool => $flag !== Xs2Ticket::FLAG_PAIRS_ONLY,
            ));
        }
        $clone->flags = $flags;

        return $clone;
    }

    private function sellerSplitType(Xs2Ticket $ticket): string
    {
        if (in_array('pairs_only', $ticket->flags ?? [], true)) {
            return 'In Pairs';
        }

        if (in_array('no_max_minus_1', $ticket->flags ?? [], true)) {
            return 'Avoid Leaving Odd';
        }

        return 'No Preferences';
    }

    private function sellerAmount(int $amount): int|string
    {
        if (config('services.seller_api.price_uses_minor_units')) {
            return $amount;
        }

        $divisor = max(1, (int) config('services.xs2.minor_unit_divisor'));
        $whole = intdiv($amount, $divisor);
        $fraction = str_pad((string) ($amount % $divisor), strlen((string) ($divisor - 1)), '0', STR_PAD_LEFT);

        return $whole.'.'.$fraction;
    }

    private function reference(Xs2Ticket $ticket, int $matchId, ?Xs2TicketMappingState $state): string
    {
        $prefix = (string) config('services.seller_api.external_reference_prefix');

        return $prefix.$ticket->external_ticket_id.($state ? '' : '-event-'.$matchId);
    }

    private function ticketBlock(Xs2Ticket $ticket, ?Xs2TicketMappingState $state): string
    {
        $candidates = $state?->categoryMapping?->candidate_scores ?? [];
        $block = data_get($candidates, '0.block');

        return is_scalar($block) && $block !== ''
            ? (string) $block
            : (string) data_get($ticket->options, 'ticket_block', '');
    }

    private function ticketDetails(Xs2Ticket $ticket): string
    {
        $restrictions = array_intersect($ticket->flags ?? [], [
            'no_awayteam_nationality_allowed',
            'no_awayteam_province_allowed',
        ]);
        if ($restrictions === []) {
            return '';
        }

        return implode('; ', array_map(
            fn (string $flag): string => str_replace('_', ' ', $flag),
            array_values($restrictions),
        ));
    }

    private function required(?string $value, string $description): string
    {
        if (blank($value)) {
            throw new ListingTransformationException("{$description} is missing.");
        }

        return $value;
    }

    private function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($value))) ?: '';
    }
}
