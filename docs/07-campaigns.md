# Campaigns (crowdfunding)

A campaign is a public, shareable **fundraising reference**. Many customers
**contribute** toward a `targetAmount`. The merchant **creates** the campaign with
a portal JWT; contributors pay through public, unauthenticated endpoints.

- **Create:** JWT bearer (see [Authentication](02-authentication.md)).
- **View / quote / contribute:** public — no API key, no JWT.

## Create a campaign (JWT)

`POST {BASE}/v1/merchant/campaigns`

**Body — `CreateCampaignRequest`:**

| JSON field | Type | Notes |
|------------|------|-------|
| `merchantAccountId` | string | the account to credit |
| `title` | string | |
| `description` | string | |
| `coverImageUrl` | string | |
| `targetAmount` | number | min 100 |
| `currency` | string | `XAF` / `XOF` |
| `deadline` | string | |
| `minContribution` | number | |
| `feeBearer` | string | `MERCHANT` (default) / `CUSTOMER` |

Returns a `PaymentCampaign`.

```bash
curl -X POST "https://api.marvincorporate.co/api/v1/merchant/campaigns" \
  -H "Authorization: Bearer YOUR_PORTAL_JWT" \
  -H "Content-Type: application/json" \
  -d '{
    "merchantAccountId": "acct_123",
    "title": "New school roof",
    "description": "Help us re-roof the classroom block",
    "coverImageUrl": "https://cdn.example.com/roof.jpg",
    "targetAmount": 2000000,
    "currency": "XAF",
    "deadline": "2026-09-30",
    "minContribution": 500,
    "feeBearer": "MERCHANT"
  }'
```

## View a campaign (public)

`GET {BASE}/v1/campaigns/{reference}` → **`PublicCampaignView`**:

| Field | Notes |
|-------|-------|
| `reference` | |
| `merchantName` | |
| `title` | |
| `description` | |
| `coverImageUrl` | |
| `targetAmount` | |
| `raisedAmount` | |
| `contributionCount` | |
| `currency` | |
| `status` | |
| `deadline` | |
| `minContribution` | |
| `publicUrl` | |
| `qrImageUrl` | |
| `disableReason` | set if the campaign was disabled |

## Quote the fee (public)

`GET {BASE}/v1/campaigns/{reference}/quote?amount=` → **`FeeQuote`**.
Pass the contribution `amount`. See [Fees & Fee Bearer](11-fees-and-fee-bearer.md).

## Contribute (public)

`POST {BASE}/v1/campaigns/{reference}/contribute` → **`PaymentResult`**.

**Body — `ContributeRequest`:**

| JSON field | Type | Required | Notes |
|------------|------|----------|-------|
| `country_code` | string (ISO-3166 α2) | ✅ | |
| `currency` | string (ISO-4217) | ✅ | must match the country |
| `amount` | number | ✅ | min 100 |
| `mobile_number` | string | ✅ | contributor's mobile-money number |
| `payment_method` | string | ✅ | provider name (see [Reference](14-reference.md)) |
| `beneficiary_name` | string | ✅ | contributor's name |
| `customer_email` | string (email) | ❌ | receipt email |

```bash
curl -X POST "https://api.marvincorporate.co/api/v1/campaigns/CMP-7/contribute" \
  -H "Content-Type: application/json" \
  -d '{
    "country_code": "CM",
    "currency": "XAF",
    "amount": 5000,
    "mobile_number": "237670000001",
    "payment_method": "mtn_cm",
    "beneficiary_name": "Ada Ada",
    "customer_email": "ada@example.com"
  }'
```

The response is a `PaymentResult` (same shape as [collect](03-collect.md)) and the
contribution resolves asynchronously. Confirm via the public poll
`GET {BASE}/v1/merchant/qrcode/status/{transactionId}` (see [QR Codes](08-qr-codes.md))
or via [Transaction Status](09-transaction-status.md).
