# Marvin Pay — PHP SDK (`marvinpay/sdk`)

A lightweight, **zero-dependency** PHP client for the
[Marvin Pay](https://api.marvincorporate.co) mobile-money payment gateway.
Uses only cURL + `hash_hmac` from the standard library. PHP >= 8.1, PSR-4.

> Source of truth: [`../../CONTRACT.md`](../../CONTRACT.md). Narrative docs:
> [`../../docs/`](../../docs/). This SDK never invents fields — everything maps
> to the contract.

Marvin Pay is **mobile money only** (MTN, Orange, Moov, Airtel, Free Money,
Expresso, T-Money) settling in **XAF** and **XOF**. There is no card channel.

## Install

Published packages are not assumed here; install from a local path.

```json
{
    "repositories": [
        { "type": "path", "url": "../marvin-pay-integration/sdks/php" }
    ],
    "require": {
        "marvinpay/sdk": "*"
    }
}
```

```bash
composer require marvinpay/sdk
```

Or, without Composer, require the three source files directly:

```php
require __DIR__ . '/sdks/php/src/MarvinPayException.php';
require __DIR__ . '/sdks/php/src/WebhookVerifier.php';
require __DIR__ . '/sdks/php/src/MarvinPayClient.php';
```

## Quickstart

```php
use MarvinPay\MarvinPayClient;
use MarvinPay\MarvinPayException;

$client = new MarvinPayClient(getenv('MARVIN_API_KEY'), [
    'base_url' => getenv('MARVIN_BASE_URL') ?: 'https://api.marvincorporate.co/api',
    // 'bearer_token' => '...',   // optional portal JWT for JWT-gated endpoints
    // 'timeout'      => 30,
]);

try {
    // Collect 5000 XAF from a Cameroon MTN number.
    // X-Idempotency-Key defaults to transaction_id.
    $result = $client->collect([
        'country_code'   => 'CM',
        'currency'       => 'XAF',      // currency + country ALWAYS travel together
        'amount'         => 5000,        // whole number, 100–500000
        'mobile_number'  => '237670000001',
        'payment_method' => 'mtn_cm',    // fetch valid values via getPaymentMethods()
        'transaction_id' => 'order-1001',
        'description'    => 'Order #1001',
        // 'fee_bearer'  => 'CUSTOMER',  // default MERCHANT
        // 'customer_email' => 'buyer@example.com',
    ]);

    // Mobile money is asynchronous — a 200/202 does NOT mean money moved.
    // Wait for a terminal state (5s → backoff to 60s → give up at 600s).
    $final = $client->waitForCompletion($result['transaction_id']);
    echo $final['transaction_status']; // SUCCESSFUL | FAILED | PENDING (on timeout)
} catch (MarvinPayException $e) {
    fprintf(STDERR, "%s (HTTP %s)\n", $e->getMessage(), $e->getHttpStatus());
    // $e->getBody() holds the decoded/raw response body
}
```

### Other calls

```php
$client->payout([...]);                          // merchant -> recipient
$client->getStatus('order-1001');                // authoritative status
$client->getFees(['currency' => 'XAF', 'amount' => 5000, 'direction' => 'COLLECT']);
$client->getPaymentMethods('CM');                // => ['mtn_cm', 'orange_cm']

// Public hosted-pay flows (no API key required server-side)
$client->payInvoice('INV-123', [
    'country_code' => 'CM', 'currency' => 'XAF', 'mobile_number' => '237670000001',
    'payment_method' => 'mtn_cm', 'beneficiary_name' => 'Jane Doe',
]);
$client->contributeCampaign('CMP-9', [
    'country_code' => 'CM', 'currency' => 'XAF', 'amount' => 1000,
    'mobile_number' => '237670000001', 'payment_method' => 'mtn_cm',
    'beneficiary_name' => 'Jane Doe',
]);
$client->payQr('QR-7', [
    'country_code' => 'CM', 'currency' => 'XAF', 'amount' => 2000,
    'mobile_number' => '237670000001', 'payment_method' => 'mtn_cm',
    'beneficiary_name' => 'Jane Doe',
]);
$client->getQrStatus('MARVIN-abc123');           // public poll for hosted-pay txns
```

### Idempotency

`collect()` / `payout()` always send `X-Idempotency-Key` (defaulting to
`transaction_id`). After a call, read replay headers:

```php
$headers = $client->getLastResponseHeaders();
$replayed = ($headers['x-idempotency-replay'] ?? null) === 'true';
```

## Webhooks — honesty caveat (READ THIS)

Outbound webhooks are effectively **UNSIGNED today**: the backend does not set
`webhookSecret`, so `X-Webhook-Signature` is not sent, and the HMAC base string
is not yet aligned with the verifier.

- Do **not** rely on the signature as your only trust anchor right now.
- On **every** webhook, confirm out-of-band with
  `getStatus($transactionId)` before acting (fulfilling orders, crediting users).
- Dedupe on `transactionId` + `status` (deliveries can repeat).

`WebhookVerifier::verify()` implements the **intended** scheme (HMAC-SHA256 over
the raw body, `sha256=` prefix stripped, constant-time). It returns `false`
today (no signature/secret) and becomes effective automatically once backend
signing is enabled — safe to wire in now.

```php
use MarvinPay\WebhookVerifier;

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? null;

$verified = WebhookVerifier::verify($raw, $sig, getenv('MARVIN_WEBHOOK_SECRET') ?: '');
// Whether or not $verified is true, CONFIRM via getStatus() before acting.
```

See runnable receivers in [`../../examples/php/webhook.php`](../../examples/php/webhook.php).

## Links

- API contract (source of truth): [`../../CONTRACT.md`](../../CONTRACT.md)
- Narrative docs: [`../../docs/`](../../docs/)
- Runnable examples: [`../../examples/php/`](../../examples/php/)
- Laravel package: [`../laravel/`](../laravel/)
