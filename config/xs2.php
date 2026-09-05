<?php

$rateLimitPerMinute = max(1, (int) env(
    'XS2_RATE_LIMIT_PER_MINUTE',
    env('XS2_REQUESTS_PER_MINUTE', env('XS2EVENT_REQUESTS_PER_MINUTE', 30)),
));

$lowLoadMode = (bool) env('APP_LOW_LOAD_MODE', false);

return [
    'enabled' => (bool) env('XS2_ENABLED', true),
    'base_url' => env('XS2_BASE_URL'),
    'api_key' => env('XS2_API_KEY'),
    'api_key_header' => env('XS2_API_KEY_HEADER', 'X-Api-Key'),

    'events_endpoint' => env('XS2_EVENTS_ENDPOINT', '/v1/events'),
    'event_detail_endpoint' => env('XS2_EVENT_DETAIL_ENDPOINT', '/v1/events/{event_id}'),
    'venues_endpoint' => env('XS2_VENUES_ENDPOINT', '/v1/venues'),
    'venue_detail_endpoint' => env('XS2_VENUE_DETAIL_ENDPOINT', '/v1/venues/{venue_id}'),
    'categories_endpoint' => env('XS2_CATEGORIES_ENDPOINT', '/v1/categories'),
    'category_detail_endpoint' => env('XS2_CATEGORY_DETAIL_ENDPOINT', '/v1/categories/{category_id}'),
    'tickets_endpoint' => env('XS2_TICKETS_ENDPOINT', '/v1/tickets'),
    'ticket_detail_endpoint' => env('XS2_TICKET_DETAIL_ENDPOINT', '/v1/tickets/{ticket_id}'),
    'ticket_guestdata_endpoint' => env('XS2_TICKET_GUESTDATA_ENDPOINT', '/v1/tickets/{ticket_id}/guestdata'),
    'bookingorder_guestdata_endpoint' => env('XS2_BOOKINGORDER_GUESTDATA_ENDPOINT', '/v1/bookingorders/{bookingorder_id}/guestdata'),
    'bookingorder_detail_endpoint' => env('XS2_BOOKINGORDER_DETAIL_ENDPOINT', '/v1/bookingorders/{bookingorder_id}'),
    'booking_detail_endpoint' => env('XS2_BOOKING_DETAIL_ENDPOINT', '/v1/bookings/{booking_id}'),
    'eticket_download_endpoint' => env('XS2_ETICKET_DOWNLOAD_ENDPOINT', '/v1/etickets/download/{bookingorder_id}/{orderitem_id}/url/{url}'),
    'team_detail_endpoint' => env('XS2_TEAM_DETAIL_ENDPOINT', '/v1/teams/{team_id}'),
    'reservations_endpoint' => env('XS2_RESERVATIONS_ENDPOINT', '/v1/reservations'),
    'bookings_endpoint' => env('XS2_BOOKINGS_ENDPOINT', '/v1/bookings'),
    'bookingorders_endpoint' => env('XS2_BOOKINGORDERS_ENDPOINT', '/v1/bookingorders'),
    // Expected list endpoint when XS2 exposes supplier orders/bookings (not in current catalog OpenAPI).
    'orders_endpoint' => env('XS2_ORDERS_ENDPOINT', '/v1/orders'),

    'timeout' => (int) env('XS2_REQUEST_TIMEOUT', 30),
    'connect_timeout' => (int) env('XS2_CONNECT_TIMEOUT', 10),
    'retry_times' => (int) env('XS2_RETRY_TIMES', 4),
    'retry_delay_ms' => (int) env('XS2_RETRY_DELAY_MS', 1500),
    'page_size' => (int) env('XS2_PAGE_SIZE', 100),
    'max_pages' => (int) env('XS2_MAX_PAGES', 500),
    'rate_limit_per_minute' => $rateLimitPerMinute,
    // Pace calls evenly across the shared cache instead of consuming all
    // allowed requests at the beginning of a minute.
    'rate_limit_pacing' => (bool) env('XS2_RATE_LIMIT_PACING', true),
    // Inventory sync normally makes at least a venue/category request and a
    // ticket request, so leave two paced request slots between job starts.
    'inventory_dispatch_interval_seconds' => (int) env(
        'XS2_INVENTORY_DISPATCH_INTERVAL_SECONDS',
        max(1, (int) ceil(120 / $rateLimitPerMinute)),
    ),
    // No stagger between jobs when catching up large backlogs (--bulk).
    'bulk_import_dispatch_interval_seconds' => (int) env('XS2_BULK_IMPORT_DISPATCH_INTERVAL_SECONDS', 0),
    'sync_overlap_minutes' => (int) env('XS2_SYNC_OVERLAP_MINUTES', 5),
    'queue' => env('XS2_QUEUE', 'xs2-sync'),
    // Guest-data fetches share the XS2 rate limit but should not block inventory
    // sync jobs on xs2-sync during large catch-up imports.
    'guest_queue' => env('XS2_GUEST_QUEUE', 'xs2-guest'),
    // Admin "Run now" / Start All crons — listened to before default so manual runs
    // are not starved behind xs2-sync backlogs on the shared default queue.
    'admin_cron_queue' => env('ADMIN_CRON_QUEUE', 'admin-cron'),
    // Mapping reconciliation should not wait behind inventory sync. Workers
    // should listen to this queue before xs2-sync, e.g.
    // queue:work --queue=xs2-mapping,xs2-sync,seller-api,default
    'mapping_queue' => env('XS2_MAPPING_QUEUE', 'xs2-mapping'),
    // Dedicated worker counts for scripts/run-queue-workers.sh. XS2 API calls
    // share one global rate limiter (Cache + RateLimiter) across every xs2-sync
    // worker, so extra xs2-sync workers reduce idle time when jobs release on
    // rate-limit contention without exceeding the configured RPM ceiling.
    'queue_workers' => [
        'xs2_sync' => max(1, (int) env('XS2_SYNC_WORKERS', $lowLoadMode ? 1 : 2)),
        'xs2_guest' => max(1, (int) env('XS2_GUEST_WORKERS', $lowLoadMode ? 1 : 1)),
        'xs2_mapping' => max(1, (int) env('XS2_MAPPING_WORKERS', 1)),
        'seller_api' => max(1, (int) env('SELLER_API_WORKERS', $lowLoadMode ? 1 : 1)),
        'default' => max(1, (int) env('DEFAULT_QUEUE_WORKERS', 1)),
    ],
    'low_load_mode' => $lowLoadMode,
    'queue_worker_options' => [
        'tries' => max(1, (int) env('QUEUE_WORKER_TRIES', 5)),
        'timeout' => max(60, (int) env('QUEUE_WORKER_TIMEOUT', 300)),
        'sleep' => max(1, (int) env('QUEUE_WORKER_SLEEP', 3)),
    ],

    'queue_backpressure' => [
        'max_pending_jobs' => max(10, (int) env('QUEUE_MAX_PENDING_JOBS', 150)),
        'max_dispatch_per_run' => max(1, (int) env('QUEUE_MAX_DISPATCH_PER_RUN', 30)),
        // seller-api is always excluded from global counts; publish crons use per-queue checks.
        'exclude_from_global' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('QUEUE_BACKPRESSURE_EXCLUDE_FROM_GLOBAL', '')),
        ))),
    ],
    'log_channel' => env('XS2_LOG_CHANNEL', 'stack'),

    'mapping' => [
        'event_auto_map_threshold' => (float) env('XS2_EVENT_AUTO_MAP_THRESHOLD', 100),
        'event_pending_threshold' => (float) env('XS2_EVENT_PENDING_THRESHOLD', 65),
        'stadium_auto_map_threshold' => (float) env('XS2_STADIUM_AUTO_MAP_THRESHOLD', 95),
        'stadium_pending_threshold' => (float) env('XS2_STADIUM_PENDING_THRESHOLD', 80),
        'category_auto_map_threshold' => (float) env('XS2_CATEGORY_AUTO_MAP_THRESHOLD', 95),
        'category_pending_threshold' => (float) env('XS2_CATEGORY_PENDING_THRESHOLD', 80),
        'category_hospitality_keyword_score' => (float) env('XS2_CATEGORY_HOSPITALITY_KEYWORD_SCORE', 20),
        'city_auto_map_threshold' => (float) env('XS2_CITY_AUTO_MAP_THRESHOLD', 95),
        'coordinate_match_radius_km' => (float) env('XS2_COORDINATE_MATCH_RADIUS_KM', 2),
        'require_stadium_detail' => (bool) env('XS2_REQUIRE_STADIUM_DETAIL_MAPPING', true),
        // These are deliberately fixed off: local master data is read-only.
        'allow_auto_create_city' => false,
        'allow_auto_create_stadium' => false,
        'allow_auto_create_stadium_detail' => false,
    ],

    'pricing' => [
        'default_markup_percentage' => (string) env('XS2_DEFAULT_MARKUP_PERCENTAGE', 10),
        'currency_minor_unit_divisor' => (int) env('XS2_CURRENCY_MINOR_UNIT_DIVISOR', 100),
    ],

    'sync' => [
        'full_interval_minutes' => (int) env(
            'XS2_FULL_SYNC_INTERVAL_MINUTES',
            $lowLoadMode ? 360 : 180,
        ),
        'incremental_interval_minutes' => (int) env(
            'XS2_INCREMENTAL_SYNC_INTERVAL_MINUTES',
            $lowLoadMode ? 30 : 30,
        ),
        'event_lock_minutes' => (int) env('XS2_EVENT_SYNC_LOCK_MINUTES', $lowLoadMode ? 30 : 10),
        'events_interval_minutes' => (int) env(
            'XS2_EVENTS_SYNC_INTERVAL_MINUTES',
            $lowLoadMode ? 120 : 60,
        ),
    ],

    'events_sync' => [
        // When false (default), xs2:sync-events is manual-only via Cron Jobs → Run now.
        'schedule_enabled' => (bool) env('XS2_EVENTS_SYNC_SCHEDULED', false),
    ],

    'inventory' => [
        // Tickets with stock in (1..low_stock_max] count as "Low stock" on the unpublished listings UI.
        'low_stock_max' => max(1, (int) env('XS2_LOW_STOCK_MAX', 10)),
    ],

    'split_listings' => [
        // When > 0 and master stock is in (0..unpublish_stock_max], split listings are
        // deleted on Seats Broker (hard DELETE). Default 0 = no low-stock unpublish.
        'unpublish_stock_max' => max(0, (int) env('XS2_SPLIT_LISTING_UNPUBLISH_STOCK_MAX', 0)),
    ],

    'sb_listing_inventory' => [
        // Scheduled xs2:sync-sb-listing-inventory (Seats Broker master + split qty reconcile).
        'enabled' => (bool) env('XS2_SB_LISTING_INVENTORY_SYNC_ENABLED', true),
        'sync_interval_minutes' => max(1, min(60, (int) env(
            'XS2_SB_LISTING_INVENTORY_SYNC_INTERVAL_MINUTES',
            $lowLoadMode ? 30 : 30,
        ))),
        'dispatch_interval_seconds' => max(1, (int) env('XS2_SB_LISTING_INVENTORY_DISPATCH_INTERVAL_SECONDS', 2)),
    ],

    'sb_new_listing_publish' => [
        // Scheduled xs2:publish-new-sb-listings (first-time publish for mapped events).
        'enabled' => (bool) env('XS2_SB_NEW_LISTING_PUBLISH_ENABLED', true),
        'sync_interval_minutes' => max(1, min(60, (int) env(
            'XS2_SB_NEW_LISTING_PUBLISH_INTERVAL_MINUTES',
            1,
        ))),
        'dispatch_interval_seconds' => max(1, (int) env('XS2_SB_NEW_LISTING_PUBLISH_DISPATCH_INTERVAL_SECONDS', 2)),
    ],

    'sb_failed_listing_publish_retry' => [
        // Scheduled xs2:retry-failed-listing-publish (opt-in via admin cron toggle).
        'enabled' => (bool) env('XS2_SB_FAILED_LISTING_PUBLISH_RETRY_ENABLED', false),
        'sync_interval_minutes' => max(1, min(60, (int) env(
            'XS2_SB_FAILED_LISTING_PUBLISH_RETRY_INTERVAL_MINUTES',
            30,
        ))),
        'dispatch_interval_seconds' => max(1, (int) env('XS2_SB_FAILED_LISTING_PUBLISH_RETRY_DISPATCH_INTERVAL_SECONDS', 2)),
    ],

    /*
    |--------------------------------------------------------------------------
    | XS2 sandbox API (testapi.xs2event.com)
    |--------------------------------------------------------------------------
    |
    | Uses dedicated sandbox credentials. Configure via Admin → API Config
    | (integration_settings) or XS2_SANDBOX_API_URL / XS2_SANDBOX_API_KEY in .env.
    | Used by the admin sandbox test flow and SB→XS2 order creation when
    | XS2_ORDERS_ACTIVE_ENVIRONMENT is sandbox (default when unset).
    |
    */
    'sandbox' => [
        'api_url' => env('XS2_SANDBOX_API_URL', 'https://testapi.xs2event.com'),
        'api_key' => env('XS2_SANDBOX_API_KEY'),
        'api_key_header' => env('XS2_SANDBOX_API_KEY_HEADER', 'X-Api-Key'),
        'test_event_id' => env('XS2_SANDBOX_TEST_EVENT_ID'),
        'bookings_endpoint' => env('XS2_SANDBOX_BOOKINGS_ENDPOINT', '/v1/bookings'),
        'booking_detail_endpoint' => env('XS2_SANDBOX_BOOKING_DETAIL_ENDPOINT', '/v1/bookings/{booking_id}'),
        'bookingorders_endpoint' => env('XS2_SANDBOX_BOOKINGORDERS_ENDPOINT', '/v1/bookingorders'),
        'bookingorder_detail_endpoint' => env('XS2_SANDBOX_BOOKINGORDER_DETAIL_ENDPOINT', '/v1/bookingorders/{bookingorder_id}'),
        'timeout' => (int) env('XS2_SANDBOX_REQUEST_TIMEOUT', env('XS2_REQUEST_TIMEOUT', 30)),
        'connect_timeout' => (int) env('XS2_SANDBOX_CONNECT_TIMEOUT', env('XS2_CONNECT_TIMEOUT', 10)),
        'retry_times' => (int) env('XS2_SANDBOX_RETRY_TIMES', 2),
        'retry_delay_ms' => (int) env('XS2_SANDBOX_RETRY_DELAY_MS', 1000),
        'max_event_attempts' => (int) env('XS2_SANDBOX_MAX_EVENT_ATTEMPTS', 15),
        'max_order_quantity' => (int) env('XS2_SANDBOX_MAX_ORDER_QUANTITY', 20),
        // When true, SB booking sync queues reservation+booking on testapi.xs2event.com
        // for orders whose listing maps to a sandbox-imported XS2 ticket.
        'auto_create_orders_from_sb' => (bool) env('XS2_SANDBOX_AUTO_CREATE_ORDERS_FROM_SB', true),
        'order_queue' => env('XS2_SANDBOX_ORDER_QUEUE', env('XS2_QUEUE', 'xs2-sync')),
    ],

    'sb_bookings_sync' => [
        // Scheduled seller-api:sync-bookings — pulls SB orders and queues XS2 sandbox bookings.
        'enabled' => (bool) env('SB_BOOKINGS_SYNC_ENABLED', true),
        'sync_interval_minutes' => max(1, min(60, (int) env(
            'SB_BOOKINGS_SYNC_INTERVAL_MINUTES',
            $lowLoadMode ? 30 : 2,
        ))),
    ],

    'sb_order_guest_data_sync' => [
        // Scheduled xs2:sync-order-guest-data — fetches SB attendee_details once per order.
        'enabled' => (bool) env('XS2_SB_ORDER_GUEST_DATA_SYNC_ENABLED', true),
        'sync_interval_minutes' => max(1, min(60, (int) env(
            'XS2_SB_ORDER_GUEST_DATA_SYNC_INTERVAL_MINUTES',
            $lowLoadMode ? 30 : 30,
        ))),
        'batch_limit' => max(1, (int) env('XS2_SB_ORDER_GUEST_DATA_SYNC_BATCH_LIMIT', 50)),
        'queue' => env('XS2_SB_ORDER_GUEST_DATA_QUEUE', env('XS2_GUEST_QUEUE', 'xs2-guest')),
    ],
];
