<?php

return [
    'store_id' => (int) env('PROVIDER_AUTH_STORE_ID', 13),
    'token_ttl_minutes' => max(
        1,
        (int) env('PROVIDER_AUTH_TOKEN_TTL_MINUTES', 120),
    ),
];
