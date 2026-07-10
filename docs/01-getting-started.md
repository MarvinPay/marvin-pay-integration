# Getting Started with Marvin Pay

Marvin Pay is a **mobile-money payment gateway** for West and Central Africa.
It lets you **collect** money from customers (payer → merchant) and **pay out**
to recipients (merchant → recipient) over the region's mobile-money networks,
settling in **XAF** (CFA Franc BEAC) and **XOF** (CFA Franc BCEAO).

There is **no card channel** — every transaction rides a mobile-money provider
(MTN, Orange, Moov, Airtel, Free Money, Expresso, T-Money, …).

## Base URL and the `/api` context path

The servlet context path is **`/api`**. Every route in these docs is served
under `/api`, so the wire path for `/v1/payment/collect` is
`POST https://api.marvincorporate.co/api/v1/payment/collect`.

| Environment | Base URL |
|-------------|----------|
| Production  | `https://api.marvincorporate.co/api` |
| Testing     | The test environment provided by Marvin Pay (see [Testing](11-testing-and-sandbox.md)) |

Throughout the docs, **`{BASE}`** means the base URL for your environment
(including the trailing `/api`).

## Conventions

- **Transport:** HTTPS with JSON bodies. Send `Content-Type: application/json`.
- **JSON casing:** the payment API uses **`snake_case`** (e.g. `country_code`,
  `mobile_number`, `transaction_id`, `fee_bearer`). Use the field names exactly
  as written in these docs.
- **Amounts:** whole numbers only. XAF and XOF have **no minor units** — never
  send decimals. Range is **min 100, max 500000** per transaction. Send as a bare
  JSON number (e.g. `5000`).
- **Currency + country travel together.** Every money request carries BOTH
  `currency` and `country_code`. Sending one without the other is invalid.
- **Idempotency:** money-moving POSTs accept an `X-Idempotency-Key` header.
  See [Idempotency](06-idempotency.md).

## How you get an API key

Your API key is passed as the `X-API-KEY` header on all `/v1/payment/**` calls.
Obtain it from the merchant portal or your Marvin Pay account manager.

See [Authentication](02-authentication.md) for the full auth model.

## 5-minute quickstart: your first collect

The snippet below charges `5000 XAF` from a Cameroon MTN mobile-money number.
Replace `YOUR_API_KEY`, the phone number, and `transaction_id` (your own unique
reference for the transaction).

```bash
curl -X POST "https://api.marvincorporate.co/api/v1/payment/collect" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "X-Idempotency-Key: order-1001-attempt-1" \
  -H "Content-Type: application/json" \
  -d '{
    "country_code": "CM",
    "currency": "XAF",
    "amount": 5000,
    "mobile_number": "2376XXXXXXXX",
    "payment_method": "mtn_cm",
    "transaction_id": "order-1001",
    "description": "Order #1001"
  }'
```

A typical response is **asynchronous** — expect `transaction_status: "PENDING"`
while the customer approves the charge on their handset:

```json
{
  "transaction_id": "order-1001",
  "status": 202,
  "message": "Payment initiated",
  "partner_transaction_id": "OP-...",
  "transaction_status": "PENDING"
}
```

**A 200/202 does not mean money moved.** Confirm the outcome by polling
`GET {BASE}/v1/payment/status/order-1001` until `transaction_status` is
`SUCCESSFUL` or `FAILED`. See [Transaction Status](05-transaction-status.md).

## Map of the docs

| Doc | What it covers |
|-----|----------------|
| [01 Getting Started](01-getting-started.md) | This page — overview, base URL, quickstart |
| [02 Authentication](02-authentication.md) | `X-API-KEY`, IP/origin whitelisting |
| [03 Collect](03-collect.md) | Collect from a customer (payer → merchant) |
| [04 Payout](04-payout.md) | Payout (merchant → recipient) |
| [05 Transaction Status](05-transaction-status.md) | Status endpoint + poll schedule |
| [06 Idempotency](06-idempotency.md) | Idempotency keys, replay, best practices |
| [07 Fees & Fee Bearer](07-fees-and-fee-bearer.md) | Fee estimates, MERCHANT vs CUSTOMER |
| [08 Webhooks](08-webhooks.md) | Events, payloads, retries, signature verification |
| [09 Errors & Rate Limits](09-errors-and-rate-limits.md) | Status codes, rate limits, retries |
| [10 Reference](10-reference.md) | All enums, currencies, payment methods, amounts |
| [11 Testing & Sandbox](11-testing-and-sandbox.md) | Testing against the Marvin Pay test environment |
