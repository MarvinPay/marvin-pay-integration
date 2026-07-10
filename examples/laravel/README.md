# Marvin Pay — Laravel examples

Illustrative snippets for the [`marvinpay/laravel`](../../sdks/laravel/) package.
These files are **not** auto-loaded — copy the relevant parts into your app.

## Files

| File | What it shows |
|------|---------------|
| [`PaymentControllerExample.php`](PaymentControllerExample.php) | `MarvinPay::collect(...)` then `waitForCompletion(...)` |
| [`WebhookRoutesExample.php`](WebhookRoutesExample.php) | How to register the webhook route (reuse the bundled one, or your own) |

## Setup recap

```bash
composer require marvinpay/laravel
php artisan vendor:publish --tag=marvinpay-config
```

```dotenv
MARVIN_API_KEY=your_api_key
MARVIN_BASE_URL=https://api.marvincorporate.co/api
MARVIN_WEBHOOK_SECRET=          # your webhook secret (enables signed webhook deliveries)
```

## Collect + wait

See `PaymentControllerExample::pay()`. It validates input, calls
`MarvinPay::collect([...])` (which auto-sends `X-Idempotency-Key` =
`transaction_id`), then blocks on `MarvinPay::waitForCompletion($transactionId)`.
In production, prefer returning after the 202 and resolving via webhook/queued
poller instead of blocking the request.

## Webhooks

Register a route (see `WebhookRoutesExample.php`) behind the
`VerifyMarvinPayWebhook` middleware, or reuse the package's bundled route:

```php
require base_path('vendor/marvinpay/laravel/routes/marvinpay.php');
```

**Verify, then always confirm.** Marvin Pay signs webhook deliveries with an
HMAC-SHA256 signature in the `X-Webhook-Signature` header when your account has a
webhook secret configured. The middleware verifies it when a secret and signature
are present and rejects a genuine mismatch; when either is absent it logs and passes
through. Because deliveries are at-least-once, always confirm out-of-band via
`MarvinPay::getStatus($transactionId)` before fulfilling orders, and dedupe on
`transactionId + status`. The package's `WebhookController` already does this.

## Links

- Laravel package: [`../../sdks/laravel/`](../../sdks/laravel/)
- API contract (source of truth): [`../../CONTRACT.md`](../../CONTRACT.md)
- Narrative docs: [`../../docs/`](../../docs/)
