<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'xs2' => [
        'enabled' => (bool) env('XS2_ENABLED', true),
        'base_url' => env('XS2_BASE_URL') ?: env('XS2EVENT_BASE_URL'),
        'api_key' => env('XS2_API_KEY') ?: env('XS2EVENT_API_KEY'),
        'api_key_header' => env('XS2_API_KEY_HEADER', 'X-Api-Key'),
        'events_endpoint' => env('XS2_EVENTS_ENDPOINT', '/v1/events'),
        'event_detail_endpoint' => env('XS2_EVENT_DETAIL_ENDPOINT', '/v1/events/{event_id}'),
        'venues_endpoint' => env('XS2_VENUES_ENDPOINT', '/v1/venues'),
        'venue_detail_endpoint' => env('XS2_VENUE_DETAIL_ENDPOINT', '/v1/venues/{venue_id}'),
        'tickets_endpoint' => env('XS2_TICKETS_ENDPOINT', '/v1/tickets'),
        'ticket_detail_endpoint' => env('XS2_TICKET_DETAIL_ENDPOINT', '/v1/tickets/{ticket_id}'),
        'ticket_guestdata_endpoint' => env('XS2_TICKET_GUESTDATA_ENDPOINT', '/v1/tickets/{ticket_id}/guestdata'),
        'team_detail_endpoint' => env('XS2_TEAM_DETAIL_ENDPOINT', '/v1/teams/{team_id}'),
        'categories_endpoint' => env('XS2_CATEGORIES_ENDPOINT', '/v1/categories'),
        'category_detail_endpoint' => env('XS2_CATEGORY_DETAIL_ENDPOINT', '/v1/categories/{category_id}'),
        'reservations_endpoint' => env('XS2_RESERVATIONS_ENDPOINT', '/v1/reservations'),
        'orders_endpoint' => env('XS2_ORDERS_ENDPOINT', '/v1/orders'),
        'timeout' => (int) env('XS2_REQUEST_TIMEOUT', 30), 'connect_timeout' => (int) env('XS2_CONNECT_TIMEOUT', 10),
        'retry_times' => (int) env('XS2_RETRY_TIMES', 4), 'retry_delay_ms' => (int) env('XS2_RETRY_DELAY_MS', 1500),
        'page_size' => (int) env('XS2_PAGE_SIZE', 100), 'max_pages' => (int) env('XS2_MAX_PAGES', 500), 'rate_limit_per_minute' => (int) env('XS2_RATE_LIMIT_PER_MINUTE', env('XS2_REQUESTS_PER_MINUTE', env('XS2EVENT_REQUESTS_PER_MINUTE', 30))), 'rate_limit_pacing' => (bool) env('XS2_RATE_LIMIT_PACING', true),
        'sync_overlap_minutes' => (int) env('XS2_SYNC_OVERLAP_MINUTES', 5), 'full_sync_interval_minutes' => (int) env('XS2_FULL_SYNC_INTERVAL_MINUTES', 180), 'incremental_sync_interval_minutes' => (int) env('XS2_INCREMENTAL_SYNC_INTERVAL_MINUTES', 15),
        'event_sync_lock_minutes' => (int) env('XS2_EVENT_SYNC_LOCK_MINUTES', 10), 'markup_percentage' => (string) env('XS2_DEFAULT_MARKUP_PERCENTAGE', 10), 'minor_unit_divisor' => (int) env('XS2_CURRENCY_MINOR_UNIT_DIVISOR', 100), 'queue' => env('XS2_QUEUE', 'xs2-sync'), 'log_channel' => env('XS2_LOG_CHANNEL', 'stack'),

        /*
        * This is an application-side safety limit,
        * not necessarily the actual XS2 account limit.
        */
        // Deprecated compatibility alias. New code reads rate_limit_per_minute.
        'requests_per_minute' => (int) env(
            'XS2_RATE_LIMIT_PER_MINUTE',
            env('XS2_REQUESTS_PER_MINUTE', env('XS2EVENT_REQUESTS_PER_MINUTE', 30))
        ),

        'sports' => array_filter(
            array_map('trim', explode(',', env('XS2_SPORTS') ?: env('XS2EVENT_SPORTS', 'soccer')))
        ),
    ],
    'seller_api' => [
        'enabled' => (bool) env('SELLER_API_ENABLED', true),
        'base_url' => env('SELLER_API_BASE_URL'),
        'listing_base_url' => env('SELLER_API_LISTING_BASE_URL', 'https://sandbox-sellerapi.seatsbrokers.com'),
        'api_key' => env('SELLER_API_KEY'),
        'listing_api_key' => env('SELLER_API_LISTING_API_KEY'),
        'api_key_header' => env('SELLER_API_KEY_HEADER', 'apiKey'),
        'idempotency_key_header' => env('SELLER_API_IDEMPOTENCY_KEY_HEADER', 'Idempotency-Key'),
        'seller_id' => env('SELLER_API_SELLER_ID'),
        'create_listing_endpoint' => env('SELLER_API_CREATE_LISTING_ENDPOINT', '/api/ticket/create'),
        'update_listing_endpoint' => env('SELLER_API_UPDATE_LISTING_ENDPOINT', '/api/ticket/edit'),
        'disable_listing_endpoint' => env('SELLER_API_DISABLE_LISTING_ENDPOINT', '/api/ticket/update_status'),
        'delete_listing_endpoint' => env('SELLER_API_DELETE_LISTING_ENDPOINT', '/api/ticket/delete'),
        'get_listing_endpoint' => env('SELLER_API_GET_LISTING_ENDPOINT', '/api/ticket'),
        'find_listing_endpoint' => env('SELLER_API_FIND_LISTING_ENDPOINT'),
        'ticket_dropdown_endpoint' => env('SELLER_API_TICKET_DROPDOWN_ENDPOINT', '/api/ticket_dropdown'),
        'booking_endpoint' => env('SELLER_API_BOOKING_ENDPOINT', '/api/booking'),
        'events_endpoint' => env('SELLER_API_EVENTS_ENDPOINT', '/api/events'),
        'venues_endpoint' => env('SELLER_API_VENUES_ENDPOINT', '/api/venues'),
        'tournaments_endpoint' => env('SELLER_API_TOURNAMENTS_ENDPOINT', '/api/tournaments'),
        'catalog_per_page' => (int) env('SELLER_API_CATALOG_PER_PAGE', 100),
        'timeout' => (int) env('SELLER_API_REQUEST_TIMEOUT', 30),
        'connect_timeout' => (int) env('SELLER_API_CONNECT_TIMEOUT', 10),
        'retry_times' => (int) env('SELLER_API_RETRY_TIMES', 3),
        'retry_delay_ms' => (int) env('SELLER_API_RETRY_DELAY_MS', 1000),
        'queue' => env('SELLER_API_QUEUE', 'seller-api'),
        'price_uses_minor_units' => (bool) env('SELLER_API_PRICE_USES_MINOR_UNITS', false),
        'external_reference_prefix' => env('SELLER_API_EXTERNAL_REFERENCE_PREFIX', 'XS2-'),
        'log_channel' => env('SELLER_API_LOG_CHANNEL', 'stack'),
    ],

];
