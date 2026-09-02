<?php

namespace App\Services\SplitListings;

use App\Jobs\PublishSplitListings;
use App\Models\Xs2Ticket;
use App\Services\SellerApi\SbNewListingPublishService;
use App\Services\Xs2\ListingPublishRuleService;

/**
 * Re-publishes split listings after XS2 stock returns from zero.
 *
 * Zero-stock unpublish clears split_enabled and deletes SB splits but keeps
 * split_quantity / increment fields (or publish rules) so we can rebuild the plan.
 */
class SplitListingRestockService
{
    public function __construct(
        private readonly SbNewListingPublishService $sbPublish,
        private readonly ListingPublishRuleService $publishRules,
        private readonly SplitListingService $splitListings,
    ) {}

    public function isRestockFromZero(int $previousStock, int $currentStock): bool
    {
        return $previousStock <= 0 && $currentStock > 0;
    }

    public function canRepublishAfterRestock(Xs2Ticket $ticket): bool
    {
        if ($ticket->split_enabled || (int) $ticket->stock <= 0) {
            return false;
        }

        if ($this->sbPublish->isPublishedOnSb($ticket)) {
            return false;
        }

        return $this->resolveSplitConfig($ticket) !== null;
    }

    /**
     * @return array{split_quantity: int, price_increment_type: string, price_increment_value: float|string, pairs_only?: bool}|null
     */
    public function resolveSplitConfig(Xs2Ticket $ticket): ?array
    {
        if ($this->hasStoredSplitConfiguration($ticket)) {
            return [
                'split_quantity' => (int) $ticket->split_quantity,
                'price_increment_type' => (string) $ticket->price_increment_type,
                'price_increment_value' => (float) $ticket->price_increment_value,
            ];
        }

        if (! $this->publishRules->rulesEnabled()) {
            return null;
        }

        $plan = $this->publishRules->buildPlan($ticket);
        if (($plan['mode'] ?? '') !== 'split') {
            return null;
        }

        return is_array($plan['split_config'] ?? null) ? $plan['split_config'] : null;
    }

    public function hasStoredSplitConfiguration(Xs2Ticket $ticket): bool
    {
        return (int) ($ticket->split_quantity ?? 0) > 0
            && filled($ticket->price_increment_type)
            && $ticket->price_increment_value !== null;
    }

    public function queueRepublish(Xs2Ticket $ticket): bool
    {
        $config = $this->resolveSplitConfig($ticket);
        if ($config === null) {
            return false;
        }

        $validation = $this->splitListings->validateConfiguration($ticket, $config);
        if (! $validation['valid']) {
            return false;
        }

        PublishSplitListings::dispatch($ticket->id, $config);

        return true;
    }
}
