<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Merchant API key
    |--------------------------------------------------------------------------
    | Sent as `X-API-KEY` on all /v1/payment/** calls. Obtain it from the
    | merchant portal or your Marvin Pay account manager.
    */
    'api_key' => env('MARVIN_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Base URL (includes the /api servlet context path)
    |--------------------------------------------------------------------------
    | Production: https://api.marvincorporate.co/api
    | Local/dev:  http://localhost:9090/api
    */
    'base_url' => env('MARVIN_BASE_URL', 'https://api.marvincorporate.co/api'),

    /*
    |--------------------------------------------------------------------------
    | Webhook signing secret
    |--------------------------------------------------------------------------
    | Your account's webhook secret. Configure it to enable signed webhook
    | deliveries; always confirm via getStatus() regardless.
    */
    'webhook_secret' => env('MARVIN_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Request timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('MARVIN_TIMEOUT', 30),
];
