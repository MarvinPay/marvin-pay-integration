# Authentication

Marvin Pay has **two separate authentication schemes**, split by URL prefix.
Pick the one that matches the endpoint you're calling.

| Scheme | Header | Applies to |
|--------|--------|-----------|
| API key | `X-API-KEY` | `/v1/payment/**` (the programmatic integration surface) |
| Portal JWT | `Authorization: Bearer <jwt>` | Bulk payout + **creation** of invoices, campaigns, QR codes |

Public "hosted pay" endpoints (paying an invoice/campaign/QR reference) require
**no** authentication — see [Invoices](06-invoices.md),
[Campaigns](07-campaigns.md), and [QR Codes](08-qr-codes.md).

## 1. Merchant Payment API — `X-API-KEY`

This is the integration surface for programmatic collect/payout/status/fees.

- **Header:** `X-API-KEY: <your api key>`.
- The key **alone** authenticates. There is **no request signing/HMAC** on
  payment calls.
- The key is issued on your `MerchantAccounts` record. How a merchant obtains it
  (self-serve portal screen vs. ops-provisioned) is **`⟨CONFIRM⟩`**.

```bash
curl -X GET "https://api.marvincorporate.co/api/v1/payment/payment-methods/CM" \
  -H "X-API-KEY: YOUR_API_KEY"
```

### Production hardening: origin / IP whitelisting

In production the server validates each request against:

- the request `Origin` / `Referer` against the account's **whitelisted origins**, and
- the source **IP** against the merchant's **whitelisted IPs**.

Server-to-server calls should originate from a **whitelisted IP**. Rate limiting
also applies — see [Errors & Rate Limits](13-errors-and-rate-limits.md).

### Auth responses

| Status | Meaning |
|--------|---------|
| `401` | API key missing or unknown |
| `403` | Account blocked/inactive, or origin/IP not whitelisted (prod) |

### Deprecated: `POST /v1/payment/authenticate`

There is a **deprecated** endpoint that exchanges `api_key` + `api_secret` for a
30-minute JWT. **Do not build on it** — use `X-API-KEY` directly on every payment
call. It is documented here only for completeness; it is discouraged.

## 2. Merchant Portal / Admin — JWT bearer

This is **not** the primary integration path. It is required only for:

- **Bulk payout** ([05](05-bulk-payout.md)), and
- the **creation** endpoints for invoices, campaigns, and QR codes ([06](06-invoices.md),
  [07](07-campaigns.md), [08](08-qr-codes.md)).

- **Header:** `Authorization: Bearer <jwt>`.
- The token is obtained through an interactive **OTP login flow** on
  `/merchant-auth/*` (`signin` → `verify-otp`). This is portal-driven and
  interactive — it is not designed for headless server integration.
- If you already have a portal JWT, you can call these endpoints directly with it.
  The interactive OTP login itself is out of scope for these integration docs.

```bash
curl -X POST "https://api.marvincorporate.co/api/v1/merchant/bulk-payout" \
  -H "Authorization: Bearer YOUR_PORTAL_JWT" \
  -H "Content-Type: application/json" \
  -d '{ ... }'
```

Bulk payout additionally requires the `MERCHANT_ADMIN` or `INITIATE_PAYOUT`
authority on the token. See [Bulk Payout](05-bulk-payout.md).
