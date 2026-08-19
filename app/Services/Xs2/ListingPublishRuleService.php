<?php

namespace App\Services\Xs2;

use App\Models\Xs2Ticket;
use App\Services\SplitListings\SplitListingService;

class ListingPublishRuleService
{
    public function __construct(
        private readonly ListingPublishRuleSettingService $settings,
        private readonly SplitListingService $splitListings,
    ) {}

    public function rulesEnabled(): bool
    {
        return (bool) $this->settings->get()['enabled'];
    }

    /** @return array<string, mixed>|null */
    public function matchRule(int $stock): ?array
    {
        $settings = $this->settings->get();
        if (! $settings['enabled']) {
            return null;
        }

        foreach ($settings['rules'] as $rule) {
            if (! ($rule['enabled'] ?? true)) {
                continue;
            }
            if ($this->conditionsMatch($stock, $rule['conditions'] ?? [])) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Build a publish plan for a ticket based on configured rules.
     *
     * @return array<string, mixed>|null
     */
    public function buildPlan(Xs2Ticket $ticket): ?array
    {
        $stock = max(0, (int) $ticket->stock);
        if ($stock <= 0) {
            return null;
        }

        $rule = $this->matchRule($stock);
        if ($rule === null) {
            return null;
        }

        $settings = $this->settings->get();
        $action = $rule['action'];
        $mode = (string) ($action['mode'] ?? 'single');

        if ($mode === 'split') {
            $splitSize = max(1, (int) ($action['split_size'] ?? 2));
            $quantities = $this->splitListings->calculateSplitQuantities($stock, $splitSize);
            $basePrice = $this->basePriceMajor($ticket);
            $prices = $basePrice !== null
                ? $this->splitListings->calculatePrices(
                    $quantities,
                    $basePrice,
                    (string) $settings['default_price_increment_type'],
                    (float) $settings['default_price_increment_value'],
                )
                : array_map(
                    fn (int $quantity, int $index): array => [
                        'split_order' => $index + 1,
                        'quantity' => $quantity,
                        'price' => 0.0,
                    ],
                    $quantities,
                    array_keys($quantities),
                );

            return [
                'rule_id' => $rule['id'],
                'rule_label' => $rule['label'],
                'mode' => 'split',
                'pairs_only' => (bool) ($action['pairs_only'] ?? false),
                'stock' => $stock,
                'listings' => $prices,
                'split_config' => [
                    'split_quantity' => $splitSize,
                    'price_increment_type' => (string) $settings['default_price_increment_type'],
                    'price_increment_value' => (float) $settings['default_price_increment_value'],
                    'pairs_only' => (bool) ($action['pairs_only'] ?? false),
                ],
            ];
        }

        $listingQty = max(1, (int) ($action['listing_quantity'] ?? 2));
        if (($action['listing_quantity_cap_to_stock'] ?? true) === true) {
            $listingQty = min($listingQty, $stock);
        }

        return [
            'rule_id' => $rule['id'],
            'rule_label' => $rule['label'],
            'mode' => 'single',
            'pairs_only' => (bool) ($action['pairs_only'] ?? false),
            'stock' => $stock,
            'quantity' => $listingQty,
            'listings' => [
                [
                    'split_order' => 1,
                    'quantity' => $listingQty,
                    'pairs_only' => (bool) ($action['pairs_only'] ?? false),
                ],
            ],
        ];
    }

    /**
     * Preview how rules apply for a given stock level (used by admin UI).
     *
     * @return array<string, mixed>
     */
    public function previewForStock(int $stock): array
    {
        $rule = $this->matchRule($stock);
        if ($rule === null) {
            return [
                'stock' => $stock,
                'matched' => false,
                'message' => 'No matching rule for this stock level.',
            ];
        }

        $ticket = new Xs2Ticket(['stock' => $stock, 'net_rate' => 10000]);
        $plan = $this->buildPlan($ticket);
        if ($plan === null) {
            return [
                'stock' => $stock,
                'matched' => false,
                'message' => 'Could not build a publish plan.',
            ];
        }

        return [
            'stock' => $stock,
            'matched' => true,
            'rule_id' => $plan['rule_id'],
            'rule_label' => $plan['rule_label'],
            'mode' => $plan['mode'],
            'pairs_only' => $plan['pairs_only'],
            'listings_count' => count($plan['listings']),
            'listings' => $plan['listings'],
            'summary' => $this->summarisePlan($plan),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function examplePreviews(): array
    {
        return [
            $this->previewForStock(4),
            $this->previewForStock(8),
        ];
    }

    /** @return array<string, mixed> */
    public function settingsPayload(): array
    {
        $settings = $this->settings->get();
        $settings['is_overridden'] = $this->settings->isOverridden();
        $settings['examples'] = $this->examplePreviews();

        return $settings;
    }

    /**
     * Apply pairs_only flag from a publish plan onto a ticket for transformation.
     */
    public function ticketWithPlanFlags(Xs2Ticket $ticket, array $plan): Xs2Ticket
    {
        if (! ($plan['pairs_only'] ?? false)) {
            return $ticket;
        }

        $clone = clone $ticket;
        $flags = array_values(array_unique(array_merge($ticket->flags ?? [], [Xs2Ticket::FLAG_PAIRS_ONLY])));

        $clone->flags = $flags;

        return $clone;
    }

    /** @param  list<array<string, mixed>>  $conditions */
    private function conditionsMatch(int $stock, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $field = (string) ($condition['field'] ?? 'stock');
            if ($field !== 'stock') {
                continue;
            }

            $operator = (string) ($condition['operator'] ?? 'gte');
            $matches = match ($operator) {
                'between' => $stock >= (int) ($condition['min'] ?? 0)
                    && $stock <= (int) ($condition['max'] ?? PHP_INT_MAX),
                'lte' => $stock <= (int) ($condition['value'] ?? 0),
                'lt' => $stock < (int) ($condition['value'] ?? 0),
                'gte' => $stock >= (int) ($condition['value'] ?? 0),
                'gt' => $stock > (int) ($condition['value'] ?? 0),
                'eq' => $stock === (int) ($condition['value'] ?? 0),
                default => false,
            };

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $plan */
    private function summarisePlan(array $plan): string
    {
        $count = count($plan['listings']);
        $pairs = ($plan['pairs_only'] ?? false) ? ', pairs only' : '';

        if (($plan['mode'] ?? '') === 'split') {
            $splitSize = (int) ($plan['split_config']['split_quantity'] ?? 2);

            return "Publish {$count} split listing(s), each qty ≤ {$splitSize}{$pairs}.";
        }

        $qty = (int) ($plan['quantity'] ?? 0);

        return "Publish 1 listing with qty {$qty}{$pairs}.";
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
}
