# Collect (payer → merchant)

`POST {BASE}/v1/payment/collect` charges a customer's mobile-money account and
credits your merchant balance.

- **Auth:** `X-API-KEY` (see [Authentication](02-authentication.md)).
- **Headers:** `X-API-KEY` (required), `X-Idempotency-Key` (optional, recommended
  — see [Idempotency](10-idempotency.md)), `Content-Type: application/json`.

## Request body — `PaymentRequest`

| JSON field | Type | Required | Notes |
|------------|------|----------|-------|
| `country_code` | string (ISO-3166 α2) | ✅ | e.g. `CM` |
| `currency` | string (ISO-4217) | ✅ | `XAF` / `XOF`; must match the country |
| `amount` | number | ✅ | whole number, 100–500000 |
| `mobile_number` | string | ✅ | payer's mobile-money number |
| `payment_method` | string | ✅ | provider name (see [Reference](14-reference.md)) |
| `transaction_id` | string | ✅ | **your** unique reference for this transaction |
| `beneficiary_name` | string | ❌ | payer's name (for collect) |
| `description` | string | ❌ | free text |
| `customer_email` | string (email) | ❌ | if set, a receipt email is sent |
| `fee_bearer` | string | ❌ | `MERCHANT` (default) / `CUSTOMER` — see [Fees](11-fees-and-fee-bearer.md) |
| `idempotency_key` | string | ❌ | alternative to the header (see [Idempotency](10-idempotency.md)) |

**Currency and country must travel together and be consistent** (e.g. `CM` with
`XAF`). See the [Reference](14-reference.md) for the currency/country map and the
`payment_method` values per country.

## Response — `PaymentResult`

| JSON field | Type | Notes |
|------------|------|-------|
| `transaction_id` | string | echoes your reference |
| `status` | number | HTTP-style code (e.g. 200/202) |
| `message` | string | human-readable |
| `partner_transaction_id` | string | gateway/operator reference |
| `transaction_status` | string | `SUCCESSFUL` / `FAILED` / `PENDING` |

## Collects are asynchronous

Mobile-money collects are **typically asynchronous**. Expect an initial
`transaction_status` of `PENDING`, followed by a customer USSD/app prompt on their
handset, then resolution. The `status` number being 200/202 means the request was
**accepted** — it does **not** mean money has moved.

## Example

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
    "beneficiary_name": "Ada Ada",
    "description": "Order #1001",
    "customer_email": "ada@example.com"
  }'
```

```json
{
  "transaction_id": "order-1001",
  "status": 202,
  "message": "Payment initiated",
  "partner_transaction_id": "OP-987",
  "transaction_status": "PENDING"
}
```

## Confirm via status or webhook

Because the result is asynchronous, always confirm the final outcome:

1. **Poll** `GET {BASE}/v1/payment/status/{transaction_id}` on the recommended
   schedule until you get a terminal state. See
   [Transaction Status](09-transaction-status.md).
2. Optionally receive a **webhook** push — but treat webhooks as a hint and
   **still confirm via status** before acting. See [Webhooks](12-webhooks.md).

Related: [Payout](04-payout.md) uses the same DTOs in the opposite direction.
