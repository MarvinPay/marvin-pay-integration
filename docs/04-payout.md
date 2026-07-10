# Payout (merchant → recipient)

`POST {BASE}/v1/payment/payout` sends money from your merchant balance to a
recipient's mobile-money account.

- **Auth:** `X-API-KEY` (see [Authentication](02-authentication.md)).
- **Headers:** `X-API-KEY` (required), `X-Idempotency-Key` (optional, recommended
  — see [Idempotency](06-idempotency.md)), `Content-Type: application/json`.

Payout uses the **same `PaymentRequest` body and the same `PaymentResult`
response** as [collect](03-collect.md). The difference is direction: here
`beneficiary_name` and `mobile_number` identify the **recipient** of the funds.

## Request body — `PaymentRequest`

| JSON field | Type | Required | Notes |
|------------|------|----------|-------|
| `country_code` | string (ISO-3166 α2) | ✅ | e.g. `CM` |
| `currency` | string (ISO-4217) | ✅ | `XAF` / `XOF`; must match the country |
| `amount` | number | ✅ | whole number, 100–500000 |
| `mobile_number` | string | ✅ | **recipient's** mobile-money number |
| `payment_method` | string | ✅ | provider name (see [Reference](10-reference.md)) |
| `transaction_id` | string | ✅ | **your** unique reference for this transaction |
| `beneficiary_name` | string | ❌ | **recipient's** name |
| `description` | string | ❌ | free text |
| `customer_email` | string (email) | ❌ | if set, a receipt email is sent |
| `fee_bearer` | string | ❌ | `MERCHANT` (default) / `CUSTOMER` |
| `idempotency_key` | string | ❌ | alternative to the header |

## Response — `PaymentResult`

| JSON field | Type | Notes |
|------------|------|-------|
| `transaction_id` | string | echoes your reference |
| `status` | number | HTTP-style code (e.g. 200/202) |
| `message` | string | human-readable |
| `partner_transaction_id` | string | gateway/operator reference |
| `transaction_status` | string | `SUCCESSFUL` / `FAILED` / `PENDING` |

## `fee_bearer=CUSTOMER` netting

On a payout, `fee_bearer=CUSTOMER` **nets the fee out of the amount** so that the
recipient/merchant economics match `amount`. With the default `MERCHANT`, the fee
is charged on top and the merchant absorbs it. See
[Fees & Fee Bearer](07-fees-and-fee-bearer.md) for a worked numeric example.

## Example

```bash
curl -X POST "https://api.marvincorporate.co/api/v1/payment/payout" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "X-Idempotency-Key: payout-5502-attempt-1" \
  -H "Content-Type: application/json" \
  -d '{
    "country_code": "CM",
    "currency": "XAF",
    "amount": 10000,
    "mobile_number": "2376XXXXXXXX",
    "payment_method": "mtn_cm",
    "transaction_id": "payout-5502",
    "beneficiary_name": "Kofi Mensah",
    "description": "Supplier settlement",
    "fee_bearer": "CUSTOMER"
  }'
```

```json
{
  "transaction_id": "payout-5502",
  "status": 202,
  "message": "Payout initiated",
  "partner_transaction_id": "OP-654",
  "transaction_status": "PENDING"
}
```

## Confirm the outcome

As with collect, payouts are asynchronous. Confirm via
`GET {BASE}/v1/payment/status/{transaction_id}` (see
[Transaction Status](05-transaction-status.md)) and/or
[Webhooks](08-webhooks.md).
