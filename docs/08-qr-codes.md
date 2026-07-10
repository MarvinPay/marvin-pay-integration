# QR Codes

A QR code encodes a payable **reference**. A customer scans it, is shown the
merchant/amount, and pays — all through public endpoints. The merchant
**generates** the QR with a portal JWT; the merchant API key is resolved
server-side from the QR reference, so the customer never sends it.

- **Generate:** JWT bearer (see [Authentication](02-authentication.md)).
- **Resolve / quote / pay / status:** public — no API key, no JWT.

## Generate a QR code (JWT)

`POST {BASE}/v1/merchant/qrcode/generate`

**Body — `QRCodeRequest`:**

| JSON field | Type | Notes |
|------------|------|-------|
| `merchantAccountId` | string | the account to credit |
| `label` | string | display label |
| `fixedAmount` | number | optional; if set, the pay amount is locked |
| `currency` | string | `XAF` / `XOF` |
| `feeBearer` | string | `MERCHANT` (default) / `CUSTOMER` |

Returns a `QRCodeResponse` (see the resolve fields below).

```bash
curl -X POST "https://api.marvincorporate.co/api/v1/merchant/qrcode/generate" \
  -H "Authorization: Bearer YOUR_PORTAL_JWT" \
  -H "Content-Type: application/json" \
  -d '{
    "merchantAccountId": "acct_123",
    "label": "Front counter",
    "fixedAmount": 2500,
    "currency": "XAF",
    "feeBearer": "MERCHANT"
  }'
```

## List payment methods for a country (public)

`GET {BASE}/v1/merchant/qrcode/payment-methods/{countryCode}` → provider list.
This is the public counterpart to the API-key
`GET /v1/payment/payment-methods/{countryCode}`.

## Resolve a QR (public)

`GET {BASE}/v1/merchant/qrcode/resolve/{qrReference}` → **`QRCodeResponse`**:

| Field | Notes |
|-------|-------|
| `id` | |
| `merchantId` | |
| `merchantAccountId` | |
| `qrReference` | |
| `label` | |
| `currency` | |
| `fixedAmount` | null/absent for open-amount QRs |
| `feeBearer` | |
| `imageUrl` | |
| `status` | |
| `createdAt` | |

## Quote the fee (public)

`GET {BASE}/v1/merchant/qrcode/quote/{qrReference}?amount=` → **`FeeQuote`**.
See [Fees & Fee Bearer](11-fees-and-fee-bearer.md).

## Pay a QR (public)

`POST {BASE}/v1/merchant/qrcode/pay/{qrReference}` → **`PaymentResult`**.

**Body — `QRPaymentRequest`:**

| JSON field | Type | Required | Notes |
|------------|------|----------|-------|
| `country_code` | string (ISO-3166 α2) | ✅ | |
| `currency` | string (ISO-4217) | ✅ | must match the country |
| `amount` | number | ✅ | **ignored if the QR has a `fixedAmount`** |
| `mobile_number` | string | ✅ | payer's mobile-money number |
| `payment_method` | string | ✅ | provider name (see [Reference](14-reference.md)) |
| `beneficiary_name` | string | ✅ | payer's name |
| `customer_email` | string (email) | ❌ | receipt email |

```bash
curl -X POST "https://api.marvincorporate.co/api/v1/merchant/qrcode/pay/QR-abc" \
  -H "Content-Type: application/json" \
  -d '{
    "country_code": "CM",
    "currency": "XAF",
    "amount": 2500,
    "mobile_number": "237670000001",
    "payment_method": "mtn_cm",
    "beneficiary_name": "Ada Ada",
    "customer_email": "ada@example.com"
  }'
```

## Poll for the outcome (public)

`GET {BASE}/v1/merchant/qrcode/status/{transactionId}` is the **public poll**. It
is also used by the invoice and campaign pay pages. It returns a plain map — no
merchant or fee data is exposed:

| Field | Notes |
|-------|-------|
| `transactionId` | |
| `status` | |
| `amount` | |
| `currency` | |
| `paymentMethod` | |
| `mobileNumber` | |
| `timestamp` | |

**Poll roughly every 5 seconds** after you receive a `PENDING` result, until you
get a terminal state.

```bash
curl -X GET "https://api.marvincorporate.co/api/v1/merchant/qrcode/status/MARVIN-abc123"
```

For server-side reconciliation with your API key, prefer the authoritative
[Transaction Status](09-transaction-status.md) endpoint.
