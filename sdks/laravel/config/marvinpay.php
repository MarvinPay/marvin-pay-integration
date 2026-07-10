<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Merchant API key
    |--------------------------------------------------------------------------
    | Sent as `X-API-KEY` on all /v1/payment/** calls. Issued on your
    | MerchantAccounts record.
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
    | Portal JWT (optional)
    |--------------------------------------------------------------------------
    | Sent as `Authorization: Bearer` for JWT-gated endpoints. The SDK does not
    | perform the interactive OTP login.
    */
    'bearer_token' => env('MARVIN_BEARER_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Webhook signing secret
    |--------------------------------------------------------------------------
    | Your account's `webhookSecret`. NOTE: webhooks are effectively UNSIGNED
    | today (see the middleware and README) — always confirm via getStatus().
    */
    'webhook_secret' => env('MARVIN_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Request timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('MARVIN_TIMEOUT', 30),
];
