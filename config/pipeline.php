<?php

$lowLoadMode = (bool) env('APP_LOW_LOAD_MODE', false);

return [
    'strict' => (bool) env('PIPELINE_STRICT', true),

    'listing_gen_queue' => env('XS2_LISTING_GEN_QUEUE', 'xs2-listing-gen'),
    'reconcile_queue' => env('XS2_RECONCILE_QUEUE', 'xs2-reconcile'),

    'max_events_per_run' => max(1, (int) env('PIPELINE_MAX_EVENTS_PER_RUN', 6000)),
    'dispatch_window_minutes' => max(1, (int) env('PIPELINE_DISPATCH_WINDOW_MINUTES', 30)),
    'reconcile_delay_seconds' => max(0, (int) env('PIPELINE_RECONCILE_DELAY_SECONDS', 120)),

    'sla_hours_before_event' => max(1, (int) env('PIPELINE_SLA_HOURS_BEFORE_EVENT', 48)),
    'stall_minutes' => max(1, (int) env('PIPELINE_STALL_MINUTES', 15)),
    'retention_days' => max(1, (int) env('PIPELINE_RETENTION_DAYS', 90)),

    'log_channel' => env('PIPELINE_CORRELATION_LOG_CHANNEL', env('XS2_LOG_CHANNEL', 'stack')),

    'queue_workers' => [
        'xs2_listing_gen' => max(0, (int) env('XS2_LISTING_GEN_WORKERS', $lowLoadMode ? 1 : 1)),
        'xs2_reconcile' => max(0, (int) env('XS2_RECONCILE_WORKERS', $lowLoadMode ? 1 : 1)),
    ],

    'legacy' => [
        // Legacy SB crons remain available during pipeline transition (default ON).
        'sb_listing_inventory_enabled' => (bool) env('PIPELINE_LEGACY_SB_LISTING_INVENTORY', true),
        'sb_new_listing_publish_enabled' => (bool) env('PIPELINE_LEGACY_SB_NEW_LISTING_PUBLISH', true),
    ],
];
