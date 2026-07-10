# Marvin Pay — Merchant Integration Kit

Everything you need to accept and send **mobile-money** payments through **Marvin
Pay** across West & Central Africa (XAF / XOF). Written guide + drop-in SDKs for
**Node.js, PHP, Laravel, Java/Spring, and Python** + runnable examples + a Postman
collection.

> **Grounded in the real API.** Every endpoint, field, header, and enum in this
> kit matches the Marvin Pay API — see [`CONTRACT.md`](CONTRACT.md), the API
> reference the SDKs follow.

---

## What's inside

| Folder | What |
|--------|------|
| [`docs/`](docs/) | The integration guide — auth, collect, payout, transaction status, idempotency, fees, webhooks, errors, testing. Start here. |
| [`sdks/node/`](sdks/node/) | Node.js (18+) client, webhook verifier, TypeScript types. |
| [`sdks/php/`](sdks/php/) | Zero-dependency PHP 8.1+ client + webhook verifier. |
| [`sdks/laravel/`](sdks/laravel/) | Laravel 10/11 package: config, service, facade, webhook middleware/controller. |
| [`sdks/java/`](sdks/java/) | Java 17 client (JDK HttpClient + Jackson) + webhook verifier. |
| [`sdks/python/`](sdks/python/) | Python 3.9+ client (`requests`) + webhook verifier. |
| [`examples/`](examples/) | Runnable per-language examples: collect, payout, poll status, webhook server. |
| [`postman/`](postman/) | Postman collection + environment for the merchant API. |
| [`CONTRACT.md`](CONTRACT.md) | The authoritative API reference. |

---

## Quickstart (60 seconds)

**Base URL:** `https://api.marvincorporate.co/api` — note the `/api` context path.
**Auth:** send your key as the `X-API-KEY` header on `/v1/payment/**`.

```bash
# 1. Ask for a fee estimate
curl -H "X-API-KEY: $MARVIN_API_KEY" \
  "https://api.marvincorporate.co/api/v1/payment/fees?currency=XAF&amount=5000&direction=COLLECT"

# 2. Collect XAF 5000 from an MTN Cameroon customer
curl -X POST "https://api.marvincorporate.co/api/v1/payment/collect" \
  -H "X-API-KEY: $MARVIN_API_KEY" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "country_code": "CM",
    "currency": "XAF",
    "amount": 5000,
    "mobile_number": "2376XXXXXXXX",
    "payment_method": "mtn_cm",
    "transaction_id": "MARVIN-0001",
    "description": "Order #123"
  }'

# 3. The response is usually PENDING — confirm the outcome:
curl -H "X-API-KEY: $MARVIN_API_KEY" \
  "https://api.marvincorporate.co/api/v1/payment/status/MARVIN-0001"
```

Or with an SDK (Node):

```js
const { MarvinPayClient } = require('@marvinpay/sdk'); // sdks/node/marvin-pay.js

const marvin = new MarvinPayClient({ apiKey: process.env.MARVIN_API_KEY });

const res = await marvin.collect({
  country_code: 'CM', currency: 'XAF', amount: 5000,
  mobile_number: '2376XXXXXXXX', payment_method: 'mtn_cm',
  transaction_id: 'MARVIN-0001', description: 'Order #123',
});

const final = await marvin.waitForCompletion(res.transaction_id); // polls to completion
console.log(final.transaction_status); // SUCCESSFUL | FAILED
```

---

## The core concepts (read these or lose money)

- **Mobile money is asynchronous.** A `200` on collect does **not** mean money
  moved — you'll usually get `PENDING`. Confirm the outcome via the **status**
  endpoint or a **webhook**. See [`docs/05-transaction-status.md`](docs/05-transaction-status.md).
- **Always send an idempotency key** on collect/payout so retries don't double-charge.
  See [`docs/06-idempotency.md`](docs/06-idempotency.md).
- **Currency and country always travel together.** `XAF` spans six countries with
  different rules — every request carries both `currency` and `country_code`.
- **Amounts are whole numbers**, 100–500000. XAF/XOF have no minor units.
- **`fee_bearer`** decides who pays the fee — `MERCHANT` (default) or `CUSTOMER`.
  See [`docs/07-fees-and-fee-bearer.md`](docs/07-fees-and-fee-bearer.md).

## Handling webhooks safely

Treat webhooks defensively:

1. **Verify the signature** — when a webhook secret is configured on your account,
   Marvin Pay sends `X-Webhook-Signature` (`sha256=<hex>`, HMAC-SHA256 over the raw
   body). Verify it with the `WebhookVerifier` shipped in each SDK.
2. **Confirm out-of-band** — on every webhook, confirm the outcome via
   `GET /v1/payment/status/{transactionId}` before acting. It is the authoritative
   source of truth and protects you regardless of transport.
3. **Dedupe** — deliveries are at-least-once; dedupe on `transactionId` + `status`.

Full detail in [`docs/08-webhooks.md`](docs/08-webhooks.md).

---

_This kit documents a proprietary API — see [`NOTICE.md`](NOTICE.md)._
