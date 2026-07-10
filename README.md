# Marvin Pay — Merchant Integration Kit

Everything you need to accept and send **mobile-money** payments through **Marvin
Pay** across West & Central Africa (XAF / XOF). Written guide + drop-in SDKs for
**Node.js, PHP, Laravel, Java/Spring, and Python** + runnable examples + a Postman
collection.

> **Grounded in the real API.** Every endpoint, field, header, and enum in this
> kit is copied from the live Marvin Pay backend — see [`CONTRACT.md`](CONTRACT.md),
> the source of truth all the SDKs are built from.

---

## What's inside

| Folder | What |
|--------|------|
| [`docs/`](docs/) | The integration guide — auth, collect, payout, invoices, campaigns, QR, webhooks, idempotency, fees, errors, sandbox. Start here. |
| [`sdks/node/`](sdks/node/) | Node.js (18+) client, webhook verifier, TypeScript types. |
| [`sdks/php/`](sdks/php/) | Zero-dependency PHP 8.1+ client + webhook verifier. |
| [`sdks/laravel/`](sdks/laravel/) | Laravel 10/11 package: config, service, facade, webhook middleware/controller. |
| [`sdks/java/`](sdks/java/) | Java 17 client (JDK HttpClient + Jackson) + webhook verifier. |
| [`sdks/python/`](sdks/python/) | Python 3.9+ client (`requests`) + webhook verifier. |
| [`examples/`](examples/) | Runnable per-language examples: collect, payout, poll status, webhook server, hosted pay. |
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
  endpoint or a **webhook**. See [`docs/09-transaction-status.md`](docs/09-transaction-status.md).
- **Always send an idempotency key** on collect/payout so retries don't double-charge.
  See [`docs/10-idempotency.md`](docs/10-idempotency.md).
- **Currency and country always travel together.** `XAF` spans six countries with
  different rules — every request carries both `currency` and `country_code`.
- **Amounts are whole numbers**, 100–500000. XAF/XOF have no minor units.
- **`fee_bearer`** decides who pays the fee — `MERCHANT` (default) or `CUSTOMER`.
  See [`docs/11-fees-and-fee-bearer.md`](docs/11-fees-and-fee-bearer.md).

## ⚠️ Webhooks: current security status

Outbound webhooks are delivered **unsigned today** (`webhookSecret` is not yet
populated server-side, so the `X-Webhook-Signature` header is not sent). **Do not
trust a webhook on its own right now** — on every webhook, confirm out-of-band with
`GET /v1/payment/status/{transactionId}` before acting, and dedupe on
`transactionId` + `status`. The SDKs ship a webhook verifier for the intended
HMAC-SHA256 scheme so you're forward-compatible the moment signing is enabled.
Full detail in [`docs/12-webhooks.md`](docs/12-webhooks.md).

## To confirm before publishing

A few facts must be filled in by the Marvin Pay team (marked `⟨CONFIRM⟩`
throughout): how a merchant **obtains an API key**, the **sandbox/test host** for
external merchants, the exact **error envelope**, and the **webhook egress IPs** to
allowlist.

---

_This kit documents a proprietary API — see [`NOTICE.md`](NOTICE.md)._
