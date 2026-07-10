# Testing & Sandbox

Marvin Pay's "sandbox" is **not a separate host** — it is a set of **magic phone
numbers** that short-circuit to deterministic outcomes, gated to the backend
`dev` profile.

## Magic phone numbers

Under the backend `dev` profile, these `mobile_number` values produce
instant/delayed `SUCCESS` / `FAILED` outcomes instead of hitting a real operator:

| Range | Behavior |
|-------|----------|
| `237600000001`–`237600000004` | instant/delayed `SUCCESS` / `FAILED` |
| `237670000001`–`237670000004` | instant/delayed `SUCCESS` / `FAILED` |

Use these to exercise your success and failure handling without moving real money.

## Only hosted-pay flows honor the short-circuit

**Important:** only the **hosted pay** flows honor the magic-number short-circuit:

- [Invoices](06-invoices.md) — `POST /v1/invoices/{reference}/pay`
- [Campaigns](07-campaigns.md) — `POST /v1/campaigns/{reference}/contribute`
- [QR codes](08-qr-codes.md) — `POST /v1/merchant/qrcode/pay/{qrReference}`

Direct API collects/payouts (transactions whose references are prefixed
`MARVIN-…`) **always hit the real gateway**, even with a magic number. Plan your
tests around the hosted-pay flows if you want deterministic sandbox behavior.

## Dev-profile gating & sandbox host

- The short-circuit is gated to the backend **`dev` profile** — it is not active on
  a normal production deployment.
- **Which externally reachable host runs with sandbox behavior for merchants is
  `⟨CONFIRM⟩`** (candidate base URL: `https://test.marvincorporate.co/api` — confirm
  before relying on it). See [Getting Started](01-getting-started.md#base-url-and-the-api-context-path).

## Example (hosted invoice pay with a magic number)

```bash
curl -X POST "https://test.marvincorporate.co/api/v1/invoices/INV-TEST/pay" \
  -H "Content-Type: application/json" \
  -d '{
    "country_code": "CM",
    "currency": "XAF",
    "mobile_number": "237600000001",
    "payment_method": "mtn_cm",
    "beneficiary_name": "Test Payer"
  }'
```

Then confirm the deterministic outcome via the public poll
`GET {BASE}/v1/merchant/qrcode/status/{transactionId}` (see [QR Codes](08-qr-codes.md)).
