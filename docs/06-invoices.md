# Invoices

An invoice is a payable **reference** you send to a customer (typically via an
email payment link). The merchant **creates** the invoice with a portal JWT; the
customer **pays** it through public, unauthenticated endpoints.

- **Create:** JWT bearer (see [Authentication](02-authentication.md)).
- **View / quote / pay:** public — no API key, no JWT.

## Create an invoice (JWT)

`POST {BASE}/v1/merchant/invoices`

**Body — `CreateInvoiceRequest`:**

| JSON field | Type | Notes |
|------------|------|-------|
| `merchantAccountId` | string | the account to credit |
| `customerName` | string | |
| `customerEmail` | string | where the payment link is emailed |
| `customerPhone` | string | |
| `description` | string | |
| `amount` | number | min 100 |
| `currency` | string | `XAF` / `XOF` |
| `expiresAt` | string | optional expiry |
| `feeBearer` | string | `MERCHANT` (default) / `CUSTOMER` |

Returns an `Invoice`.

> Note: the creation DTOs use `camelCase` (e.g. `merchantAccountId`,
> `feeBearer`). The public pay DTO uses `snake_case` — see below.

```bash
curl -X POST "https://api.marvincorporate.co/api/v1/merchant/invoices" \
  -H "Authorization: Bearer YOUR_PORTAL_JWT" \
  -H "Content-Type: application/json" \
  -d '{
    "merchantAccountId": "acct_123",
    "customerName": "Ada Ada",
    "customerEmail": "ada@example.com",
    "customerPhone": "237670000001",
    "description": "Invoice #INV-42",
    "amount": 15000,
    "currency": "XAF",
    "feeBearer": "MERCHANT"
  }'
```

## View an invoice (public)

`GET {BASE}/v1/invoices/{reference}` → **`PublicInvoiceView`**:

| Field | Notes |
|-------|-------|
| `reference` | the invoice reference |
| `merchantName` | |
| `customerName` | |
| `description` | |
| `amount` | |
| `currency` | |
| `status` | |
| `expiresAt` | |
| `paidAt` | |

## Quote the fee (public)

`GET {BASE}/v1/invoices/{reference}/quote` → **`FeeQuote`** (the fee-bearer split).
See [Fees & Fee Bearer](11-fees-and-fee-bearer.md).

## Pay an invoice (public)

`POST {BASE}/v1/invoices/{reference}/pay` → **`PaymentResult`**.

**Body — `PayInvoiceRequest`:**

| JSON field | Type | Required | Notes |
|------------|------|----------|-------|
| `country_code` | string (ISO-3166 α2) | ✅ | |
| `currency` | string (ISO-4217) | ✅ | must match the country |
| `mobile_number` | string | ✅ | payer's mobile-money number |
| `payment_method` | string | ✅ | provider name (see [Reference](14-reference.md)) |
| `beneficiary_name` | string | ✅ | payer's name |
| `customer_email` | string (email) | ❌ | receipt email |

The amount comes from the invoice itself, so it is not part of the pay body.

```bash
curl -X POST "https://api.marvincorporate.co/api/v1/invoices/INV-42/pay" \
  -H "Content-Type: application/json" \
  -d '{
    "country_code": "CM",
    "currency": "XAF",
    "mobile_number": "237670000001",
    "payment_method": "mtn_cm",
    "beneficiary_name": "Ada Ada",
    "customer_email": "ada@example.com"
  }'
```

The response is a `PaymentResult` (same shape as [collect](03-collect.md)); the
payment resolves asynchronously. Confirm the outcome via the public poll
`GET {BASE}/v1/merchant/qrcode/status/{transactionId}` (used by the hosted pay
pages) — see [QR Codes](08-qr-codes.md) — or via
[Transaction Status](09-transaction-status.md).
