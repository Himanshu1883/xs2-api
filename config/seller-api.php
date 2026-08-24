<?php

return [
    'enabled' => (bool) env('SELLER_API_ENABLED', true),
    // External catalog (GET /api/events + Bearer). Host only — no trailing /api.
    'base_url' => env('SELLER_API_BASE_URL'),
    'catalog_sandbox_base_url' => env('SELLER_API_CATALOG_SANDBOX_BASE_URL', 'https://sandbox-externalapi.seatsbrokers.com'),
    'catalog_production_base_url' => env('SELLER_API_CATALOG_PRODUCTION_BASE_URL', 'https://externalapi.seatsbrokers.com'),
    // Multipart seller listing API (ticket/create, ticket_dropdown, …). Defaults to sellerapi when catalog uses externalapi.
    'listing_base_url' => env('SELLER_API_LISTING_BASE_URL', 'https://sandbox-sellerapi.seatsbrokers.com'),
    'api_key' => env('SELLER_API_KEY'),
    // Optional Sanctum seller key for listing calls (apiKey header). Falls back to SELLER_API_KEY when unset.
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
    // The current Seatsbrokers contract already uses this optional lookup.
    'ticket_dropdown_endpoint' => env('SELLER_API_TICKET_DROPDOWN_ENDPOINT', '/api/ticket_dropdown'),
    'booking_endpoint' => env('SELLER_API_BOOKING_ENDPOINT', '/api/booking'),
    'booking_max_pages' => (int) env('SELLER_API_BOOKING_MAX_PAGES', 50),

    // External catalog (GET + Bearer). Events export is filesystem-only; venues sync persists to legacy tables.
    'events_endpoint' => env('SELLER_API_EVENTS_ENDPOINT', '/api/events'),
    'venues_endpoint' => env('SELLER_API_VENUES_ENDPOINT', '/api/venues'),
    'tournaments_endpoint' => env('SELLER_API_TOURNAMENTS_ENDPOINT', '/api/tournaments'),
    'catalog_per_page' => (int) env('SELLER_API_CATALOG_PER_PAGE', 100),
    'catalog_search_limit' => (int) env('SELLER_API_CATALOG_SEARCH_LIMIT', 10),
    'catalog_lang' => env('SELLER_API_CATALOG_LANG', 'en'),
    'catalog_venue_lookup_max_pages' => (int) env('SELLER_API_CATALOG_VENUE_LOOKUP_MAX_PAGES', 15),
    'import_time_limit' => (int) env('SELLER_API_IMPORT_TIME_LIMIT', 120),

    'timeout' => (int) env('SELLER_API_REQUEST_TIMEOUT', 30),
    'connect_timeout' => (int) env('SELLER_API_CONNECT_TIMEOUT', 10),
    'retry_times' => (int) env('SELLER_API_RETRY_TIMES', 3),
    'retry_delay_ms' => (int) env('SELLER_API_RETRY_DELAY_MS', 1000),
    'queue' => env('SELLER_API_QUEUE', 'seller-api'),
    'log_channel' => env('SELLER_API_LOG_CHANNEL', 'stack'),
    'external_reference_prefix' => env('SELLER_API_EXTERNAL_REFERENCE_PREFIX', 'XS2-'),
    // Seatsbrokers External Seller API v2 documents price/facevalue as decimal major units (e.g. 200 EUR).
    'price_uses_minor_units' => (bool) env('SELLER_API_PRICE_USES_MINOR_UNITS', false),

    /*
    | XS2 type_ticket values → Seatsbrokers Seller API ticket_dropdown ticket_type
    | rows (stable ids 1–6). Names must match ticket_type_name from the API.
    */
    'ticket_types' => [
        'default' => ['id' => 2, 'name' => 'E-Tickets'],
        'xs2' => [
            'eticket' => ['id' => 2, 'name' => 'E-Tickets'],
            'etickets' => ['id' => 2, 'name' => 'E-Tickets'],
            'e-tickets' => ['id' => 2, 'name' => 'E-Tickets'],
            'e_tickets' => ['id' => 2, 'name' => 'E-Tickets'],
            'eticket_with_pickup' => ['id' => 2, 'name' => 'E-Tickets'],
            'appticket' => ['id' => 4, 'name' => 'Mobile'],
            'mobile' => ['id' => 4, 'name' => 'Mobile'],
            'paper-ticket' => ['id' => 3, 'name' => 'Paper Tickets'],
            'paper' => ['id' => 3, 'name' => 'Paper Tickets'],
            'collection-stadium' => ['id' => 5, 'name' => 'Local Delivery'],
            'local-delivery' => ['id' => 5, 'name' => 'Local Delivery'],
            'season-card' => ['id' => 1, 'name' => 'Season Card'],
            'seasoncard' => ['id' => 1, 'name' => 'Season Card'],
            'external-transfer' => ['id' => 6, 'name' => 'External Transfer'],
            'externaltransfer' => ['id' => 6, 'name' => 'External Transfer'],
        ],
    ],

    /*
    | Fallback split_type IDs used when the SB ticket dropdown is unavailable
    | (no categories set up for the match yet). These match the stable SB
    | split_type enum so listings can push with the XS2 category name directly.
    */
    'split_types' => [
        'default' => ['id' => 1, 'name' => 'No Preferences'],
        'no preferences' => ['id' => 1, 'name' => 'No Preferences'],
        'in pairs' => ['id' => 2, 'name' => 'In Pairs'],
        'avoid leaving odd' => ['id' => 3, 'name' => 'Avoid Leaving Odd'],
    ],
    'split_types_default_id' => (int) env('SELLER_API_DEFAULT_SPLIT_TYPE_ID', 1),
];
