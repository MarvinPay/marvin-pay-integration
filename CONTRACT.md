# Marvin Pay — API Contract (source of truth)

> This file is the authoritative, code-grounded reference the SDKs and docs are
> generated from. Everything here was verified against the Marvin Pay backend
> source. Where a value must be confirmed by a human it is marked `⟨CONFIRM⟩`.
> **Do not invent fields.** If it is not here, it does not exist.

---

## 0. Conventions

- **Transport:** HTTPS, JSON request/response bodies (`Content-Type: application/json`).
- **JSON casing:** `snake_case` on the payment API DTOs (e.g. `country_code`,
  `mobile_number`, `transaction_id`, `fee_bearer`).
- **Amounts:** whole numbers. XAF and XOF have **no minor units** — never send
  decimals. Server type is `BigDecimal`; **min 100, max 500000** per transaction.
  In JSON send as a bare number (e.g. `5000`). SDKs should accept a decimal/integer
  and serialize without a fractional part.
- **Currency + country always travel together.** Every money request carries BOTH
  `currency` and `country_code`. Sending one without the other is invalid.
- **Idempotency:** money-moving POSTs accept `X-Idempotency-Key` (see §7).

### Base URL

- **Servlet context path is `/api`.** Every route below is served under `/api`,
  so the wire path for `/v1/payment/collect` is `POST /api/v1/payment/collect`.
- **Production base URL:** `https://api.marvincorporate.co/api`
- **Local/dev:** `http://localhost:9090/api`
- **Sandbox/test host for external merchants:** `⟨CONFIRM⟩` (candidate:
  `https://test.marvincorporate.co/api` — confirm before publishing). See §12.

SDKs default `baseUrl` to `https://api.marvincorporate.co/api` and allow override.

---

## 1. Authentication

There are **two separate schemes**, split by URL prefix.

### 1.1 Merchant Payment API — `X-API-KEY` (the integration surface)

- Applies to **`/v1/payment/**`**.
- Header: **`X-API-KEY: <your api key>`**. The key alone authenticates — there is
  no request signing/HMAC on payment calls.
- The key is issued on your `MerchantAccounts` record. How a merchant obtains it:
  `⟨CONFIRM⟩` (self-serve portal screen vs. ops-provisioned).
- **Production hardening:** in prod the server validates the request `Origin` /
  `Referer` / source IP against the account's whitelisted origins and the
  merchant's whitelisted IPs. Server-to-server calls should originate from a
  whitelisted IP. Rate limiting applies (see §11).
- `⇒` responses: `401` if the key is missing/unknown; `403` if the account is
  blocked/inactive or the origin/IP is not allowed.

> There is a **deprecated** `POST /v1/payment/authenticate` that exchanges
> `api_key` + `api_secret` for a 30-minute JWT. Do **not** build on it — use
> `X-API-KEY` directly. Documented for completeness only.

### 1.2 Merchant Portal / Admin — JWT bearer (NOT the primary integration path)

- Header: `Authorization: Bearer <jwt>`.
- Obtained through an OTP login flow on `/merchant-auth/*`
  (`signin` → `verify-otp`). This is portal-driven and interactive.
- Required by: bulk payout, and the **creation** endpoints for invoices,
  campaigns, and QR codes.
- SDKs expose an optional `bearerToken` so a merchant who already has a portal JWT
  can call these endpoints. SDKs do **not** implement the interactive OTP login.

---

## 2. Enums & reference values

### 2.1 Transaction status

- **API response field `transaction_status` (string):** `SUCCESSFUL | FAILED | PENDING`
- **Webhook payload field `status` (string):** `SUCCESS | FAILED | PENDING | CANCEL`
- Terminal states: success and failed. `PENDING` means keep polling / await webhook.

> ⚠️ Note the wording difference on purpose: REST status responses say
> `SUCCESSFUL`, webhooks say `SUCCESS`. SDKs should normalize both to a single
> enum (e.g. `SUCCEEDED`) for callers.

### 2.2 Fee bearer — `fee_bearer`

- Values: `MERCHANT | CUSTOMER`. **Omitted/null ⇒ `MERCHANT`.**
- `CUSTOMER` grosses up a collect / nets down a payout so the merchant
  receives/pays exactly `amount`.

### 2.3 Fee direction (for `GET /v1/payment/fees`)

`COLLECT | PAYOUT | TOPUP | WITHDRAWAL` (merchants use `COLLECT` / `PAYOUT`).

### 2.4 Currencies & countries

- **XAF** (CFA Franc BEAC): CM, CF, TD, CG, GQ, GA
- **XOF** (CFA Franc BCEAO): BJ, BF, CI, GW, ML, NE, SN, TG
- Live payment-routing countries (those with mobile-money providers wired):
  **CM, GA, CI, SN, BJ, TG, ML**.
- `country_code` is ISO-3166 alpha-2 (e.g. `CM`). `currency` is ISO-4217 (`XAF`/`XOF`).

### 2.5 Payment methods (`payment_method`) — provider names by country

The `payment_method` value is the **provider name string**. **Always fetch the
authoritative list at runtime** via `GET /v1/payment/payment-methods/{countryCode}`.
Known values at time of writing:

| Country | `payment_method` values |
|---------|-------------------------|
| CM      | `mtn_cm`, `orange_cm` |
| GA      | `airtel_ga`, `moov_ga` |
| CI      | `mtn_ci`, `orange_ci`, `moov_ci` |
| SN      | `orange_sn`, `free_money_sn`, `expresso_sn` |
| BJ      | `mtn_bj`, `moov_bj` |
| TG      | `t_money_tg` |
| ML      | `orange_ml`, `moov_ml` |

Mobile money only — **no card channel** is implemented.

---

## 3. Core payment endpoints (`X-API-KEY`)

### 3.1 `POST /v1/payment/collect` — collect from a customer (payer → merchant)

Headers: `X-API-KEY` (required), `X-Idempotency-Key` (optional),
`Content-Type: application/json`.

**Request body — `PaymentRequest`:**

| JSON field | Type | Required | Notes |
|------------|------|----------|-------|
| `country_code` | string (ISO-3166 α2) | ✅ | e.g. `CM` |
| `currency` | string (ISO-4217) | ✅ | `XAF` / `XOF`; must match country |
| `amount` | number | ✅ | whole number, 100–500000 |
| `mobile_number` | string | ✅ | payer's mobile-money number |
| `payment_method` | string | ✅ | provider name (see §2.5) |
| `transaction_id` | string | ✅ | **your** unique reference for this txn |
| `beneficiary_name` | string | ❌ | payer's name (for collect) |
| `description` | string | ❌ | free text |
| `customer_email` | string (email) | ❌ | if set, a receipt email is sent |
| `fee_bearer` | string | ❌ | `MERCHANT` (default) / `CUSTOMER` |
| `idempotency_key` | string | ❌ | alternative to the header (see §7) |

**Response — `PaymentResult`:**

| JSON field | Type | Notes |
|------------|------|-------|
| `transaction_id` | string | echoes your reference |
| `status` | number | HTTP-style code (e.g. 200/202) |
| `message` | string | human-readable |
| `partner_transaction_id` | string | gateway/operator reference |
| `transaction_status` | string | `SUCCESSFUL` / `FAILED` / `PENDING` |

Mobile-money collects are typically **asynchronous**: expect `PENDING`, then a
customer USSD/app prompt, then resolution via webhook or status polling (§6).

### 3.2 `POST /v1/payment/payout` — pay out (merchant → recipient)

Same headers, same `PaymentRequest` body, same `PaymentResult` response as
collect. Here `beneficiary_name` / `mobile_number` identify the **recipient**.
`fee_bearer=CUSTOMER` nets the fee out of the amount so the recipient/merchant
economics match `amount`.

### 3.3 `GET /v1/payment/status/{transactionId}` — check status

Headers: `X-API-KEY`. Path: your `transaction_id`.

**Response — `TransactionStatusResponse`:**

| JSON field | Type | Notes |
|------------|------|-------|
| `transaction_id` | string | |
| `status` | number | HTTP-style code |
| `message` | string | |
| `currency` | string | |
| `timestamp` | string/number | |
| `transaction_status` | string | `SUCCESSFUL` / `FAILED` / `PENDING` |

This is the **authoritative** way to confirm a transaction. Poll it (see §6) and
also use it to confirm webhooks.

### 3.4 `GET /v1/payment/fees` — fee estimate

Query params: `currency`, `amount`, `direction` (`COLLECT`/`PAYOUT`),
`fee_bearer` (optional).

**Response — `FeeEstimateResponse`** (known fields):
`baseAmount`, `feeBearer`, `direction`, `amountChargedToCustomer`,
`amountCreditedToMerchant`, `amountDebitedFromMerchant`, `amountReceivedByRecipient`
(plus the fee amount). Treat the live response as authoritative for any extra
fields.

### 3.5 `GET /v1/payment/payment-methods/{countryCode}` — list providers

Headers: `X-API-KEY`. Returns a JSON array of provider-name strings valid for the
country (the values you pass as `payment_method`).

---

## 4. Public "hosted pay" flows

These let a customer pay a **reference** (invoice/campaign/QR) without an API key.
The merchant creates the invoice/campaign/QR (JWT, §5); the customer pays the
public endpoint. Useful if you build your own pay page instead of using the hosted
one.

### 4.1 Invoices

- `GET /v1/invoices/{reference}` → **`PublicInvoiceView`**: `reference`,
  `merchantName`, `customerName`, `description`, `amount`, `currency`, `status`,
  `expiresAt`, `paidAt`.
- `GET /v1/invoices/{reference}/quote` → **`FeeQuote`** (fee-bearer split).
- `POST /v1/invoices/{reference}/pay` → **`PaymentResult`**. Body
  **`PayInvoiceRequest`**: `country_code`, `currency`, `mobile_number`,
  `payment_method`, `beneficiary_name` (required — payer name), `customer_email`
  (optional).

### 4.2 Campaigns (crowdfunding)

- `GET /v1/campaigns/{reference}` → **`PublicCampaignView`**: `reference`,
  `merchantName`, `title`, `description`, `coverImageUrl`, `targetAmount`,
  `raisedAmount`, `contributionCount`, `currency`, `status`, `deadline`,
  `minContribution`, `publicUrl`, `qrImageUrl`, `disableReason`.
- `GET /v1/campaigns/{reference}/quote?amount=` → **`FeeQuote`**.
- `POST /v1/campaigns/{reference}/contribute` → **`PaymentResult`**. Body
  **`ContributeRequest`**: `country_code`, `currency`, `amount` (min 100),
  `mobile_number`, `payment_method`, `beneficiary_name`, `customer_email`.

### 4.3 QR codes

- `GET /v1/merchant/qrcode/payment-methods/{countryCode}` → provider list (public).
- `GET /v1/merchant/qrcode/resolve/{qrReference}` → **`QRCodeResponse`**: `id`,
  `merchantId`, `merchantAccountId`, `qrReference`, `label`, `currency`,
  `fixedAmount`, `feeBearer`, `imageUrl`, `status`, `createdAt`.
- `GET /v1/merchant/qrcode/quote/{qrReference}?amount=` → **`FeeQuote`**.
- `POST /v1/merchant/qrcode/pay/{qrReference}` → **`PaymentResult`**. Body
  **`QRPaymentRequest`**: `country_code`, `currency`, `amount` (ignored if the QR
  has a `fixedAmount`), `mobile_number`, `payment_method`, `beneficiary_name`
  (required), `customer_email`. The merchant API key is resolved server-side from
  the QR reference — the client never sends it.
- `GET /v1/merchant/qrcode/status/{transactionId}` → **public poll** (also used by
  the invoice/campaign pay pages). Returns a plain map: `transactionId`, `status`,
  `amount`, `currency`, `paymentMethod`, `mobileNumber`, `timestamp`. No
  merchant/fee data exposed. Poll ~every 5s.

---

## 5. Portal (JWT) flows

### 5.1 Bulk payout — `POST /v1/merchant/bulk-payout`

- **Auth: JWT bearer** (not `X-API-KEY`). Authorities: `MERCHANT_ADMIN` or
  `INITIATE_PAYOUT`.
- Body — **`BulkPayoutRequest`**:
  - `merchant_account_id` (required)
  - `encrypted_data` (required) — AES-encrypted, Base64-encoded JSON **array** of
    payout items, encrypted with the shared `app.encryption.key`.
  - `otp` (required)
  - `batch_reference` (required) — idempotency key for the batch.
- Each decrypted item — **`BulkPayoutItemDto`**: `line_ref`, `country_code`,
  `currency`, `amount` (100–500000), `mobile_number`, `beneficiary_name`,
  `payment_method`, `description`, `fee_bearer`.
- Response — **`BulkPayoutResponse`** (HTTP **202**): `batch_id`,
  `batch_reference`, `status` (`QUEUED`/`PARTIAL`/`REJECTED`), `total_items`,
  `queued_count`, `failed_count`, `items[]` (`BulkPayoutItemResult`), `stats`,
  `submitted_at`, `message`.
- List/detail: `GET /v1/merchant/bulk-payout` (paginated),
  `GET /v1/merchant/bulk-payout/{batchId}`.

> Because bulk payout needs client-side AES + an OTP, SDKs document it and show the
> encryption recipe, but do not bundle interactive OTP handling.

### 5.2 Creation endpoints (JWT) — documented, optional in SDK via `bearerToken`

- `POST /v1/merchant/invoices` — **`CreateInvoiceRequest`**: `merchantAccountId`,
  `customerName`, `customerEmail`, `customerPhone`, `description`, `amount`
  (min 100), `currency`, `expiresAt` (optional), `feeBearer` → `Invoice`.
- `POST /v1/merchant/campaigns` — **`CreateCampaignRequest`**: `merchantAccountId`,
  `title`, `description`, `coverImageUrl`, `targetAmount` (min 100), `currency`,
  `deadline`, `minContribution`, `feeBearer` → `PaymentCampaign`.
- `POST /v1/merchant/qrcode/generate` — **`QRCodeRequest`**: `merchantAccountId`,
  `label`, `fixedAmount` (optional), `currency`, `feeBearer` → `QRCodeResponse`.

---

## 6. Confirming a transaction: polling + webhooks

Mobile-money is asynchronous. Two ways to learn the outcome:

1. **Webhook** (push) — see §8. Treat as a **hint**; always confirm via status.
2. **Status polling** (pull) — `GET /v1/payment/status/{transactionId}`.

**Recommended poll schedule** (implemented by the SDK `waitForCompletion` helper):
start after **5s**, then exponential backoff capped at **60s**, **give up after
10 minutes** and report still-pending. Stop as soon as `transaction_status` is
`SUCCESSFUL` or `FAILED`.

---

## 7. Idempotency

- Header: **`X-Idempotency-Key: <key>`** on `POST /v1/payment/collect` and
  `POST /v1/payment/payout`.
- Resolution order server-side: request header → body `idempotency_key` → an
  auto-generated deterministic key `auto:{apiKey}:{transactionId}`.
- On replay within the retention window (~24h) the **original** `PaymentResult` is
  returned, with response headers `X-Idempotency-Replay: true` (and
  `X-Idempotency-Key-Auto` when the key was auto-generated).
- Bulk payout uses `batch_reference` as its idempotency key.

SDK behavior: always send `X-Idempotency-Key`. If the caller doesn't supply one,
default it to the `transaction_id` (stable + unique per attempt). Surface the
replay headers to the caller.

---

## 8. Webhooks (outbound, Marvin Pay → your server)

### 8.1 Registration

The callback URL is `webhookUrl` on the merchant account (must be HTTPS),
configured via the portal / account update. The signing secret is `webhookSecret`.

### 8.2 Delivery mechanics

- Method: `POST` your `webhookUrl`, JSON body. **Success = any 2xx.**
- Retry: a scheduler retries non-2xx every ~30s with backoff `30s × 2^attempt`
  (≈ 30s, 1m, 2m, 4m, 8m). After **5 attempts** the delivery is marked `DEAD`.
- Delivery is idempotent per `transactionId` — you may receive the same event more
  than once; **dedupe on `transactionId` + `status`.**

### 8.3 Headers

- `Content-Type: application/json`
- `X-Webhook-Attempt` — retry attempt number
- `X-Marvin-Timestamp` — epoch milliseconds
- `X-Webhook-Nonce` — UUID (replay guard)
- `X-Webhook-Delivery-Id` — unique per delivery
- `X-Webhook-Signature` — **only sent when `webhookSecret` is set** — format
  `sha256=<hex>` (HMAC-SHA256). **See §8.5 for current status.**

### 8.4 Payload

```json
{
  "event": "transaction.success",
  "transactionId": "MARVIN-abc123",
  "operatorTransactionId": "OP-987",
  "status": "SUCCESS",
  "amount": 5000,
  "currency": "XAF",
  "baseAmount": 5000,
  "feeBearer": "MERCHANT",
  "amountChargedToCustomer": 5000,
  "amountCreditedToMerchant": 4900,
  "timestamp": 1752143400000,
  "paymentMethod": "mtn_cm",
  "country": "CM",
  "description": "Order #123"
}
```

- `event`: `transaction.success | transaction.failed | transaction.pending | transaction.cancel`
- `status`: `SUCCESS | FAILED | PENDING | CANCEL`
- Direction-aware amount split: **credit/collect** payloads carry
  `amountChargedToCustomer` + `amountCreditedToMerchant`; **debit/payout** payloads
  carry `amountDebitedFromMerchant` + `amountReceivedByRecipient`.

### 8.5 ⚠️ Current signature status — READ THIS

As of this writing, outbound webhooks are effectively **UNSIGNED**:

- `webhookSecret` is not populated by any code path, so `X-Webhook-Signature` is
  **not sent** today.
- The HMAC scheme (base string + timestamp field name) is not yet aligned with the
  SDK verifier, so signature verification is **not usable in production yet**.

**Guidance for merchants (and what the SDK docs say):**

1. Do **not** rely on the signature as your only trust anchor right now.
2. On every webhook, **confirm out-of-band** with
   `GET /v1/payment/status/{transactionId}` before acting on it (fulfilling orders,
   crediting users, etc.).
3. Restrict your webhook endpoint (allowlist Marvin Pay egress IPs `⟨CONFIRM⟩`,
   use a hard-to-guess URL path).
4. The SDKs ship a `WebhookVerifier` implementing the **intended** scheme (HMAC-
   SHA256 over the raw request body, compare against `X-Webhook-Signature` minus
   the `sha256=` prefix, constant-time). It is safe to wire in now — it becomes
   effective automatically once backend signing is enabled. Until then, keep the
   status-confirmation step regardless.

---

## 9. Errors

- `200` / `202` — accepted. For async collects/payouts, inspect
  `transaction_status` (often `PENDING`) — a 200 does **not** mean money moved.
- `400` — validation error (missing/invalid field, amount out of 100–500000,
  currency/country mismatch).
- `401` — missing/unknown `X-API-KEY`.
- `403` — account blocked/inactive, or origin/IP not whitelisted (prod).
- `429` — rate limited (see §11).
- Error body shape: `⟨CONFIRM⟩` exact envelope — observe live; may be a Spring
  error object (`timestamp`/`status`/`error`/`message`/`path`) or a `PaymentResult`
  with a non-2xx `status` + `message`. SDKs should surface HTTP status +
  `message` + the raw body.

---

## 10. Sandbox / testing

- "Sandbox" is **magic phone numbers**, not a separate host, and is gated to the
  backend `dev` profile:
  - `237600000001`–`237600000004` and `237670000001`–`237670000004` produce
    instant/delayed `SUCCESS`/`FAILED`.
  - Only the **hosted pay** flows (invoice/campaign/QR) honor the short-circuit;
    direct API collects (references prefixed `MARVIN-…`) always hit the real
    gateway.
- Which externally reachable host runs with sandbox behavior for merchants:
  `⟨CONFIRM⟩`.

---

## 11. Rate limits

- The collect endpoint is locally rate-limited to ~**100 requests/min per API
  key** (plus a global `RateLimitService`). Over the limit ⇒ `429`. SDKs should
  treat `429` as retryable with backoff (respect `Retry-After` if present).

---

## 12. Quick reference — the programmatic surface (`X-API-KEY`)

| Method | Path (add `/api` on the wire) | Body → Response |
|--------|-------------------------------|-----------------|
| POST | `/v1/payment/collect` | `PaymentRequest` → `PaymentResult` |
| POST | `/v1/payment/payout` | `PaymentRequest` → `PaymentResult` |
| GET | `/v1/payment/status/{transactionId}` | → `TransactionStatusResponse` |
| GET | `/v1/payment/fees?currency&amount&direction&fee_bearer` | → `FeeEstimateResponse` |
| GET | `/v1/payment/payment-methods/{countryCode}` | → `string[]` |
