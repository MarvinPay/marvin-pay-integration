# @marvinpay/sdk (Node.js)

A lightweight, **zero-dependency** Node.js client for the [Marvin Pay](../../CONTRACT.md)
payment gateway — mobile-money collect/payout, status polling, and webhook
verification.

- **No runtime dependencies.** Uses the built-in global `fetch` and `node:crypto`.
- **Node 18+** (tested on 20).
- Field names match the wire contract exactly (`snake_case` on payment DTOs).

> The authoritative API reference is [`../../CONTRACT.md`](../../CONTRACT.md).
> Longer guides live in [`../../docs/`](../../docs/) (getting started,
> collect, payout, transaction status, idempotency, fees, webhooks, errors,
> testing).

---

## Install

This SDK is a **copy-in single-file client** — there is no published npm package
yet. Copy the two source files into your project:

```
sdks/node/marvin-pay.js        # the MarvinPayClient
sdks/node/webhook-verifier.js  # verifyWebhookSignature + parseWebhookEvent
sdks/node/index.d.ts           # optional TypeScript types
```

```js
const { MarvinPayClient, MarvinPayError } = require('./marvin-pay.js');
```

(If you install it as a local package, `require('@marvinpay/sdk')` also re-exports
the webhook helpers from the main entry point.)

---

## Quickstart — collect + wait for completion

```js
const { MarvinPayClient, MarvinPayError } = require('./marvin-pay.js');
const crypto = require('node:crypto');

const client = new MarvinPayClient({
  apiKey: process.env.MARVIN_API_KEY,
  baseUrl: process.env.MARVIN_BASE_URL, // optional; defaults to production
});

async function main() {
  // transaction_id is YOUR unique reference; it also becomes the idempotency key.
  const transaction_id = `order-${crypto.randomUUID()}`;

  const result = await client.collect({
    country_code: 'CM',          // currency + country ALWAYS travel together
    currency: 'XAF',
    amount: 5000,                // whole number, 100–500000 (no minor units)
    mobile_number: '<your-test-msisdn>',
    payment_method: 'mtn_cm',    // from getPaymentMethods('CM')
    transaction_id,
    beneficiary_name: 'Jane Payer',
    description: 'Order #123',
    fee_bearer: 'MERCHANT',      // or 'CUSTOMER' to gross up the payer
    // customer_email: 'jane@example.com', // optional → sends a receipt
  });

  console.log('accepted:', result.transaction_status); // usually PENDING

  // Mobile money is asynchronous — poll until terminal.
  // Schedule: wait 5s, exp. backoff capped at 60s, give up after 10 min.
  const final = await client.waitForCompletion(transaction_id);
  console.log('final:', MarvinPayClient.normalizeStatus(final.transaction_status));
}

main().catch((err) => {
  if (err instanceof MarvinPayError) {
    console.error(`MarvinPayError ${err.httpStatus} (${err.code ?? 'HTTP'}): ${err.message}`);
    console.error('body:', err.body);
  } else {
    console.error(err);
  }
  process.exit(1);
});
```

A `200`/`202` does **not** mean money moved — always inspect `transaction_status`
(and confirm via `getStatus` / `waitForCompletion`).

---

## Payout

`payout` takes the same `PaymentRequest` shape as `collect`; here
`mobile_number` / `beneficiary_name` identify the **recipient**.

```js
const result = await client.payout({
  country_code: 'CI',
  currency: 'XOF',
  amount: 25000,
  mobile_number: '2250700000000',
  payment_method: 'orange_ci',
  transaction_id: `payout-${crypto.randomUUID()}`,
  beneficiary_name: 'Kwame Recipient',
  fee_bearer: 'MERCHANT', // 'CUSTOMER' nets the fee out of the amount
});

const final = await client.waitForCompletion(result.transaction_id);
```

---

## Fees & payment methods

```js
const fees = await client.getFees({
  currency: 'XAF',
  amount: 5000,
  direction: 'COLLECT',   // COLLECT | PAYOUT
  feeBearer: 'CUSTOMER',  // optional
});

const methods = await client.getPaymentMethods('CM'); // e.g. ['mtn_cm','orange_cm']
```

Always fetch `getPaymentMethods(countryCode)` at runtime rather than hard-coding
provider names.

---

## Webhook handling — verify, then always confirm

Marvin Pay `POST`s a JSON event to your `webhookUrl` when a transaction resolves.

> Marvin Pay signs webhook deliveries with an HMAC-SHA256 signature in the
> `X-Webhook-Signature` header (format `sha256=<hex>`) when your account has a
> webhook secret configured. Verify it against your webhook secret using
> `verifyWebhookSignature`. In addition — and because deliveries are
> at-least-once — always confirm the transaction independently via
> `GET /v1/payment/status/{transactionId}` before acting on it, and dedupe on
> `transactionId` + `status`. For every event:
>
> 1. **Confirm out-of-band** with `client.getStatus(transactionId)` before acting
>    (fulfilling orders, crediting users, etc.). This is the authoritative check.
> 2. **Dedupe on `transactionId` + `status`** — the same event may be delivered
>    more than once.
> 3. Restrict the endpoint (hard-to-guess path, IP allowlist).
> 4. Respond `2xx` fast; do the real work asynchronously.
>
> The `verifyWebhookSignature` helper computes the HMAC-SHA256 over the raw
> request body and returns `false` when the secret or signature is missing. Keep
> the `getStatus` confirmation step regardless of the signature result.

```js
const express = require('express');
const { MarvinPayClient } = require('./marvin-pay.js');
const { verifyWebhookSignature, parseWebhookEvent } = require('./webhook-verifier.js');

const app = express();
const client = new MarvinPayClient({ apiKey: process.env.MARVIN_API_KEY });
const seen = new Set(); // demo only — use a durable store in production

// Capture the RAW body — required for a correct HMAC signature check.
app.use('/webhooks/marvin', express.raw({ type: '*/*' }));

app.post('/webhooks/marvin', async (req, res) => {
  const raw = req.body; // Buffer

  // Returns false when the secret or signature is missing; confirm via getStatus regardless.
  const signed = verifyWebhookSignature(
    raw,
    req.get('X-Webhook-Signature'),
    process.env.MARVIN_WEBHOOK_SECRET || '',
  );

  let event;
  try {
    event = parseWebhookEvent(raw);
  } catch {
    return res.status(400).send('bad json');
  }

  // Ack immediately so Marvin Pay doesn't retry.
  res.status(200).send('ok');

  // Dedupe on transactionId + status.
  const key = `${event.transactionId}:${event.status}`;
  if (seen.has(key)) return;
  seen.add(key);

  // ALWAYS confirm out-of-band before acting.
  try {
    const status = await client.getStatus(event.transactionId);
    if (MarvinPayClient.normalizeStatus(status.transaction_status) === 'SUCCEEDED') {
      // ... fulfill the order here ...
    }
  } catch (err) {
    console.error('confirm failed:', err.message);
  }
});
```

A runnable version is in [`../../examples/node/webhook-server.js`](../../examples/node/webhook-server.js).

---

## Configuration

| Option        | Type     | Default                                  | Notes |
|---------------|----------|------------------------------------------|-------|
| `apiKey`      | `string` | —                                        | Sent as `X-API-KEY` on the payment API. |
| `baseUrl`     | `string` | `https://api.marvincorporate.co/api`     | Must include the `/api` context path. Dev: `http://localhost:9090/api`. |
| `timeoutMs`   | `number` | `30000`                                  | Per-request timeout (AbortController). |

**Behavior notes**

- `collect` / `payout` always send `X-Idempotency-Key`, defaulting to
  `transaction_id`. On a replay the result carries non-enumerable
  `idempotencyReplayed` / `idempotencyKeyAuto` flags.
- `amount` is serialized as a **whole number** (rounded).
- Non-2xx responses throw a `MarvinPayError` with `httpStatus`, `message`, `body`
  (and a `code` like `TIMEOUT` / `NETWORK` for transport errors).
- GETs are retried **once** on `429`/`5xx` (respecting `Retry-After`).
  Money-moving POSTs are **never** auto-retried.
- `MarvinPayClient.normalizeStatus(s)` maps `SUCCESSFUL`/`SUCCESS`→`SUCCEEDED`,
  `FAILED`→`FAILED`, `PENDING`→`PENDING`, `CANCEL`→`CANCELLED`.

---

## API surface

| Method | Endpoint |
|--------|----------|
| `collect(req, { idempotencyKey })` | `POST /v1/payment/collect` |
| `payout(req, { idempotencyKey })` | `POST /v1/payment/payout` |
| `getStatus(txId)` | `GET /v1/payment/status/{txId}` |
| `getFees({ currency, amount, direction, feeBearer })` | `GET /v1/payment/fees` |
| `getPaymentMethods(countryCode)` | `GET /v1/payment/payment-methods/{countryCode}` |
| `waitForCompletion(txId, opts)` | polls `getStatus` |

## License

UNLICENSED — internal / partner use. See [`../../NOTICE.md`](../../NOTICE.md).
