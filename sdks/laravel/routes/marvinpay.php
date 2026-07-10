<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MarvinPay\Laravel\Http\Controllers\WebhookController;
use MarvinPay\Laravel\Http\Middleware\VerifyMarvinPayWebhook;

/*
|--------------------------------------------------------------------------
| Marvin Pay example webhook route
|--------------------------------------------------------------------------
| Register from your app, e.g. in a service provider's boot() or a route file:
|
|   require base_path('vendor/marvinpay/laravel/routes/marvinpay.php');
|
| Use a hard-to-guess path in production and allowlist Marvin Pay egress IPs.
| The controller confirms via getStatus() before acting (deliveries are
| at-least-once). Keep the route free of CSRF (it lives outside the `web` group
| by default when registered here).
*/
Route::post('/marvinpay/webhook', [WebhookController::class, 'handle'])
    ->middleware(VerifyMarvinPayWebhook::class)
    ->name('marvinpay.webhook');
