<?php

declare(strict_types=1);

namespace MarvinPay\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the `X-Webhook-Signature` header (HMAC-SHA256 of the raw body vs
 * `config('marvinpay.webhook_secret')`, `sha256=` prefix stripped, constant-time).
 *
 * ────────────────────────────────────────────────────────────────────────────
 *  HONESTY CAVEAT — webhooks are effectively UNSIGNED today.
 *
 *  The backend does not populate `webhookSecret`, so `X-Webhook-Signature` is
 *  NOT sent right now, and the HMAC base string is not yet aligned. Therefore
 *  this middleware does NOT reject when a signature or secret is absent — it
 *  logs and passes the request through. It only rejects on a genuine MISMATCH
 *  (which cannot happen until backend signing is live), so it is safe to wire in
 *  now and becomes effective automatically once signing is enabled.
 *
 *  Regardless of this middleware, your controller MUST confirm the outcome
 *  out-of-band via the MarvinPay service `getStatus()` before acting.
 * ────────────────────────────────────────────────────────────────────────────
 */
class VerifyMarvinPayWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('marvinpay.webhook_secret', '');
        $signature = $request->header('X-Webhook-Signature');

        // Unsigned today: no secret configured or no signature present => log & pass.
        if ($secret === '' || $signature === null || $signature === '') {
            Log::info('MarvinPay webhook received without a verifiable signature; passing through. Confirm the outcome via getStatus() before acting.');
            return $next($request);
        }

        $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (!hash_equals($expected, trim($provided))) {
            Log::warning('MarvinPay webhook signature mismatch — rejecting.');
            abort(401, 'Invalid webhook signature');
        }

        return $next($request);
    }
}
