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
| Local / dev | `http://localhost:9090/api` |
| Sandbox / test (external merchants) | `⟨CONFIRM⟩` (candidate: `https://test.marvincorporate.co/api`) |

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
  See [Idempotency](10-idempotency.md).

## How you get an API key

The API key is issued on your `MerchantAccounts` record and is passed as the
`X-API-KEY` header on all `/v1/payment/**` calls. How a merchant obtains the key
(self-serve portal screen vs. ops-provisioned) is **`⟨CONFIRM⟩`**.

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
    "mobile_number": "237670000001",
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
`SUCCESSFUL` or `FAILED`. See [Transaction Status](09-transaction-status.md).

## Map of the docs

| Doc | What it covers |
|-----|----------------|
| [01 Getting Started](01-getting-started.md) | This page — overview, base URL, quickstart |
| [02 Authentication](02-authentication.md) | `X-API-KEY`, IP/origin whitelisting, portal JWT/OTP |
| [03 Collect](03-collect.md) | Collect from a customer (payer → merchant) |
| [04 Payout](04-payout.md) | Single payout (merchant → recipient) |
| [05 Bulk Payout](05-bulk-payout.md) | Encrypted batch payouts (JWT + OTP) |
| [06 Invoices](06-invoices.md) | Invoice creation + public pay |
| [07 Campaigns](07-campaigns.md) | Crowdfunding campaigns + public contribute |
| [08 QR Codes](08-qr-codes.md) | QR generation + public resolve/pay/poll |
| [09 Transaction Status](09-transaction-status.md) | Status endpoint + poll schedule |
| [10 Idempotency](10-idempotency.md) | Idempotency keys, replay, best practices |
| [11 Fees & Fee Bearer](11-fees-and-fee-bearer.md) | Fee estimates, MERCHANT vs CUSTOMER |
| [12 Webhooks](12-webhooks.md) | Events, payloads, retries, signature status |
| [13 Errors & Rate Limits](13-errors-and-rate-limits.md) | Status codes, rate limits, retries |
| [14 Reference](14-reference.md) | All enums, currencies, payment methods, amounts |
| [15 Testing & Sandbox](15-testing-and-sandbox.md) | Magic phone numbers, sandbox gating |
