# Marvin Pay — Laravel package (`marvinpay/laravel`)

A self-contained Laravel 10/11 integration for the
[Marvin Pay](https://api.marvincorporate.co) mobile-money payment gateway. Uses
Laravel's `Http` client under the hood — it does **not** depend on the vanilla
`marvinpay/sdk` package.

> API reference: [`../../CONTRACT.md`](../../CONTRACT.md). Narrative docs:
> [`../../docs/`](../../docs/).

## Install

Install from a local path repository (adjust the relative path for your app):

```json
{
    "repositories": [
        { "type": "path", "url": "../marvin-pay-integration/sdks/laravel" }
    ],
    "require": {
        "marvinpay/laravel": "*"
    }
}
```

```bash
composer require marvinpay/laravel
```

The service provider and `MarvinPay` facade alias are auto-discovered
(`extra.laravel` in `composer.json`).

## Publish config

```bash
php artisan vendor:publish --tag=marvinpay-config
```

This writes `config/marvinpay.php`. Set these env keys:

```dotenv
MARVIN_API_KEY=your_api_key
MARVIN_BASE_URL=https://api.marvincorporate.co/api
MARVIN_WEBHOOK_SECRET=          # your webhook secret (enables signed webhook deliveries)
MARVIN_TIMEOUT=30
```

## Usage

```php
use MarvinPay\Laravel\Facades\MarvinPay;

$result = MarvinPay::collect([
    'country_code'   => 'CM',
    'currency'       => 'XAF',      // currency + country ALWAYS travel together
    'amount'         => 5000,        // whole number, 100–500000
    'mobile_number'  => '<your-test-msisdn>',
    'payment_method' => 'mtn_cm',
    'transaction_id' => 'order-1001',
]); // X-Idempotency-Key defaults to transaction_id

// Mobile money is async — a 200/202 does NOT mean money moved.
$final = MarvinPay::waitForCompletion($result['transaction_id']);
// $final['transaction_status'] => SUCCESSFUL | FAILED | PENDING (on timeout)
```

Other methods: `payout()`, `getStatus()`, `getFees()`, `getPaymentMethods()`,
`getLastResponseHeaders()` (read `x-idempotency-replay`). Non-2xx responses throw
`Illuminate\Http\Client\RequestException`; GETs retry once on 429/5xx.

You can also resolve the service without the facade:

```php
$marvin = app(\MarvinPay\Laravel\MarvinPay::class);
```

## Register the webhook route

The package ships an example route + controller + verifier middleware. Register
the route from your app (e.g. in `routes/web.php`, `routes/api.php`, or a
service provider `boot()`):

```php
require base_path('vendor/marvinpay/laravel/routes/marvinpay.php');
```

This exposes `POST /marvinpay/webhook` behind the `VerifyMarvinPayWebhook`
middleware. In production, use a hard-to-guess path and allowlist Marvin Pay
egress IPs. Prefer copying `WebhookController` into your app so you can
implement the fulfilment TODOs.

## Webhooks — verify, then always confirm

Marvin Pay signs webhook deliveries with an HMAC-SHA256 signature in the
`X-Webhook-Signature` header (format `sha256=<hex>`) when your account has a
webhook secret configured. Because deliveries are at-least-once, the signature
is never your only trust anchor.

- `VerifyMarvinPayWebhook` verifies the signature when a secret and signature are
  present, and rejects on a genuine mismatch. When either is absent it logs and
  passes through, so your controller stays in control of the confirm step.
- On **every** webhook, the example `WebhookController` confirms out-of-band via
  `MarvinPay::getStatus($transactionId)` before acting, and dedupes on
  `transactionId + status`. Keep this step regardless of signature status.

## Links

- API reference: [`../../CONTRACT.md`](../../CONTRACT.md)
- Narrative docs: [`../../docs/`](../../docs/)
- Laravel usage examples: [`../../examples/laravel/`](../../examples/laravel/)
- Vanilla PHP SDK: [`../php/`](../php/)
