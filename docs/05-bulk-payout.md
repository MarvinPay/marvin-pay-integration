# Bulk Payout

`POST {BASE}/v1/merchant/bulk-payout` submits **many payouts in one batch**.
Unlike the single [payout](04-payout.md), it uses portal **JWT** auth, an
**AES-encrypted** item list, and an **OTP**.

## Authentication

- **Auth:** `Authorization: Bearer <jwt>` — a portal JWT, **not** `X-API-KEY`.
  See [Authentication](02-authentication.md).
- **Required authority:** `MERCHANT_ADMIN` or `INITIATE_PAYOUT`.

## Request body — `BulkPayoutRequest`

| JSON field | Type | Required | Notes |
|------------|------|----------|-------|
| `merchant_account_id` | string | ✅ | the account funding the batch |
| `encrypted_data` | string | ✅ | AES-encrypted, Base64-encoded JSON **array** of payout items |
| `otp` | string | ✅ | one-time password |
| `batch_reference` | string | ✅ | idempotency key for the batch (see [Idempotency](10-idempotency.md)) |

### Each decrypted item — `BulkPayoutItemDto`

`encrypted_data` decrypts to a JSON **array** where every element is:

| JSON field | Type | Notes |
|------------|------|-------|
| `line_ref` | string | your per-line reference |
| `country_code` | string (ISO-3166 α2) | e.g. `CM` |
| `currency` | string (ISO-4217) | `XAF` / `XOF`; must match the country |
| `amount` | number | whole number, 100–500000 |
| `mobile_number` | string | recipient's mobile-money number |
| `beneficiary_name` | string | recipient's name |
| `payment_method` | string | provider name (see [Reference](14-reference.md)) |
| `description` | string | free text |
| `fee_bearer` | string | `MERCHANT` (default) / `CUSTOMER` |

## The AES encryption recipe (conceptual)

The payout items never travel as plaintext. The recipe:

1. Build the JSON **array** of `BulkPayoutItemDto` objects.
2. **AES-encrypt** that JSON string using the shared secret `app.encryption.key`
   (the encryption key provisioned for your account).
3. **Base64-encode** the ciphertext.
4. Put the Base64 string in the `encrypted_data` field.

Because bulk payout needs client-side AES plus an interactive OTP, it is
documented here but not something a headless SDK bundles end-to-end. Obtain the
exact cipher parameters (mode, IV handling, key length) and the OTP delivery
channel from your Marvin Pay onboarding contact.

## Response — `BulkPayoutResponse` (HTTP 202)

| JSON field | Type | Notes |
|------------|------|-------|
| `batch_id` | string | server-assigned batch id |
| `batch_reference` | string | echoes your idempotency reference |
| `status` | string | `QUEUED` / `PARTIAL` / `REJECTED` |
| `total_items` | number | items submitted |
| `queued_count` | number | items accepted for processing |
| `failed_count` | number | items rejected up front |
| `items[]` | array | per-item results (`BulkPayoutItemResult`) |
| `stats` | object | batch statistics |
| `submitted_at` | string/number | submission timestamp |
| `message` | string | human-readable summary |

A **202** means the batch was **accepted for processing** — individual items still
resolve asynchronously. Confirm each item's outcome via its own status/webhook.

## Example

```bash
curl -X POST "https://api.marvincorporate.co/api/v1/merchant/bulk-payout" \
  -H "Authorization: Bearer YOUR_PORTAL_JWT" \
  -H "Content-Type: application/json" \
  -d '{
    "merchant_account_id": "acct_123",
    "encrypted_data": "BASE64_AES_CIPHERTEXT",
    "otp": "482913",
    "batch_reference": "payroll-2026-07-10"
  }'
```

```json
{
  "batch_id": "batch_789",
  "batch_reference": "payroll-2026-07-10",
  "status": "QUEUED",
  "total_items": 3,
  "queued_count": 3,
  "failed_count": 0,
  "items": [],
  "stats": {},
  "submitted_at": 1752143400000,
  "message": "Batch accepted"
}
```

## List and detail

| Method | Path | Notes |
|--------|------|-------|
| GET | `{BASE}/v1/merchant/bulk-payout` | paginated list of your batches |
| GET | `{BASE}/v1/merchant/bulk-payout/{batchId}` | one batch's detail |

```bash
curl -X GET "https://api.marvincorporate.co/api/v1/merchant/bulk-payout/batch_789" \
  -H "Authorization: Bearer YOUR_PORTAL_JWT"
```

## Idempotency

Bulk payout uses `batch_reference` as its idempotency key — resubmitting the same
`batch_reference` will not create a duplicate batch. See
[Idempotency](10-idempotency.md).
