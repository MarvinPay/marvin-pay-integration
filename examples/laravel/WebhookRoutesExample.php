<?php

declare(strict_types=1);

/**
 * EXAMPLE routes snippet — copy the relevant lines into your app's route file
 * (e.g. routes/api.php or routes/web.php). This file is illustrative and is not
 * auto-loaded.
 *
 * Two options:
 *
 * 1) Reuse the package's bundled example route + controller + middleware:
 *
 *      require base_path('vendor/marvinpay/laravel/routes/marvinpay.php');
 *
 *    That registers: POST /marvinpay/webhook (name: marvinpay.webhook)
 *
 * 2) Or declare your own route pointing at your own controller, still using the
 *    package's verifier middleware:
 */

use Illuminate\Support\Facades\Route;
use MarvinPay\Laravel\Http\Middleware\VerifyMarvinPayWebhook;

// Use a hard-to-guess path in production and allowlist Marvin Pay egress IPs.
Route::post('/hooks/marvinpay/9f3c-secret-path', [\App\Http\Controllers\MarvinPayWebhookController::class, 'handle'])
    ->middleware(VerifyMarvinPayWebhook::class)
    ->name('marvinpay.webhook');

/*
 * The middleware verifies the X-Webhook-Signature when a secret and signature are
 * present and rejects a genuine mismatch; when either is absent it logs and passes
 * through. Because deliveries are at-least-once, your controller MUST confirm
 * out-of-band via MarvinPay::getStatus() before acting.
 */
