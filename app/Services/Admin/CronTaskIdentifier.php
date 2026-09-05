<?php

namespace App\Services\Admin;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;

class CronTaskIdentifier
{
    public static function forEvent(Event $event): ?string
    {
        if ($event instanceof CallbackEvent) {
            $name = (string) ($event->description ?? $event->getSummaryForDisplay());

            if (preg_match('/^xs2-events:([a-z0-9_-]+):(incremental|full)$/i', $name, $matches) === 1) {
                return 'xs2-events-sync';
            }

            return null;
        }

        $command = (string) ($event->command ?? '');
        $normalized = $event->normalizeCommand($command);

        if (str_contains($normalized, 'xs2:sync-events')
            || str_contains($command, 'xs2:sync-events')) {
            return 'xs2-events-sync';
        }

        if (str_contains($normalized, 'xs2:sync-inventory --mode=incremental')
            || str_contains($command, 'xs2:sync-inventory --mode=incremental')) {
            return 'xs2-inventory-incremental';
        }

        if (str_contains($normalized, 'xs2:sync-inventory --mode=full')
            || str_contains($command, 'xs2:sync-inventory --mode=full')) {
            return 'xs2-inventory-full';
        }

        if (str_contains($normalized, 'sanctum:prune-expired')
            || str_contains($command, 'sanctum:prune-expired')) {
            return 'sanctum-prune-expired';
        }

        if (str_contains($normalized, 'xs2:publish-new-sb-listings')
            || str_contains($command, 'xs2:publish-new-sb-listings')) {
            return 'xs2-sb-new-listing-publish';
        }

        if (str_contains($normalized, 'xs2:retry-failed-listing-publish')
            || str_contains($command, 'xs2:retry-failed-listing-publish')) {
            return 'xs2-sb-failed-listing-publish-retry';
        }

        if (str_contains($normalized, 'xs2:sync-sb-listing-inventory')
            || str_contains($command, 'xs2:sync-sb-listing-inventory')) {
            return 'xs2-sb-listing-inventory';
        }

        if (str_contains($normalized, 'seller-api:sync-bookings')
            || str_contains($command, 'seller-api:sync-bookings')) {
            return 'xs2-sb-order-sync';
        }

        if (str_contains($normalized, 'xs2:sync-order-guest-data')
            || str_contains($command, 'xs2:sync-order-guest-data')) {
            return 'xs2-sb-order-guest-data-sync';
        }

        if (str_contains($normalized, 'xs2:sync-split-listing-quantities')
            || str_contains($command, 'xs2:sync-split-listing-quantities')) {
            return 'xs2-split-listing-quantities';
        }

        return null;
    }
}
