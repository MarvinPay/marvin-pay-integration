# Marvin Pay — API Reference

> A concise, complete reference to the Marvin Pay payment API — **collect** and
> **payout** — including request/response shapes, headers, enums, and webhooks.
> Pair it with the step-by-step guide in [`docs/`](docs/) and the ready-to-use
> clients in [`sdks/`](sdks/).

---

## 0. Conventions

- **Transport:** HTTPS, JSON request/response bodies (`Content-Type: application/json`).
- **JSON casing:** `snake_case` on the payment API DTOs (e.g. `country_code`,
  `mobile_number`, `transaction_id`, `fee_bearer`).
- **Amounts:** whole numbers. XAF and XOF have **no minor units** — never send
  decimals. **Min 100, max 500000** per transaction. In JSON send as a bare number
  (e.g. `5000`).
- **Currency + country always travel together.** Every request carries BOTH
  `currency` and `country_code`. Sending one without the other is invalid.
- **Idempotency:** the money-moving POSTs accept `X-Idempotency-Key` (see §5).

### Base URL

- **Servlet context path is `/api`.** Every route below is served under `/api`,
  so the wire path for `/v1/payment/collect` is `POST /api/v1/payment/collect`.
- **Production base URL:** `https://api.marvincorporate.co/api`
- **Testing:** run against the test environment provided by Marvin Pay (see §8).

SDKs default `baseUrl` to `https://api.marvincorporate.co/api` and allow override.

---

## 1. Authentication

The payment API uses a single scheme: an **API key** in the `X-API-KEY` header on
every `/v1/payment/**` call.

- Header: **`X-API-KEY: <your api key>`**.
- Obtain your API key from the merchant portal or your Marvin Pay account manager.
- **Production hardening:** the server validates the request `Origin` / `Referer` /
  source IP against your account's whitelisted origins and IPs. Server-to-server
  calls should originate from a whitelisted IP. Rate limiting applies (see §9).
- `⇒` responses: `401` if the key is missing/unknown; `403` if the account is
  blocked/inactive or the origin/IP is not allowed.

Keep the key server-side only; never expose it in browser or mobile client code.

---

## 2. Enums & reference values

### 2.1 Transaction status

- **API response field `transaction_status` (string):** `SUCCESSFUL | FAILED | PENDING`
- **Webhook payload field `status` (string):** `SUCCESS | FAILED | PENDING | CANCEL`
- Terminal states: success and failed. `PENDING` means keep polling / await webhook.

> Note the wording difference: REST status responses say `SUCCESSFUL`, webhooks say
> `SUCCESS`. SDKs normalize both to a single enum (e.g. `SUCCEEDED`) for callers.

### 2.2 Fee bearer — `fee_bearer`

- Values: `MERCHANT | CUSTOMER`. **Omitted/null ⇒ `MERCHANT`.**
- `CUSTOMER` grosses up a collect / nets down a payout so the merchant
  receives/pays exactly `amount`.

### 2.3 Fee direction (for `GET /v1/payment/fees`)

`COLLECT | PAYOUT` (merchant-facing). The API also defines `TOPUP | WITHDRAWAL`
for internal settlement, which merchants do not use.

### 2.4 Currencies & countries

- **XAF** (CFA Franc BEAC): CM, CF, TD, CG, GQ, GA
- **XOF** (CFA Franc BCEAO): BJ, BF, CI, GW, ML, NE, SN, TG
- Live payment-routing countries (those with mobile-money providers wired):
  **CM, GA, CI, SN, BJ, TG, ML**.
- `country_code` is ISO-3166 alpha-2 (e.g. `CM`). `currency` is ISO-4217 (`XAF`/`XOF`).

### 2.5 Payment methods (`payment_method`) — provider names by country

The `payment_method` value is the **provider name** string. **Always fetch the
authoritative list at runtime** via `GET /v1/payment/payment-methods/{countryCode}`.
Known values:

| Country | `payment_method` values |
|---------|-------------------------|
| CM      | `mtn_cm`, `orange_cm` |
| GA      | `airtel_ga`, `moov_ga` |
| CI      | `mtn_ci`, `orange_ci`, `moov_ci` |
| SN      | `orange_sn`, `free_money_sn`, `expresso_sn` |
| BJ      | `mtn_bj`, `moov_bj` |
| TG      | `t_money_tg` |
| ML      | `orange_ml`, `moov_ml` |

Mobile money only.

---

## 3. Payment endpoints (`X-API-KEY`)

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
| `idempotency_key` | string | ❌ | alternative to the header (see §5) |

**Response — `PaymentResult`:**

| JSON field | Type | Notes |
|------------|------|-------|
| `transaction_id` | string | echoes your reference |
| `status` | number | HTTP-style code (e.g. 200/202) |
| `message` | string | human-readable |
| `partner_transaction_id` | string | gateway/operator reference |
| `transaction_status` | string | `SUCCESSFUL` / `FAILED` / `PENDING` |

Mobile-money collects are typically **asynchronous**: expect `PENDING`, then a
customer USSD/app prompt, then resolution via webhook or status polling (§4).

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

This is the **authoritative** way to confirm a transaction. Poll it (see §4) and
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

## 4. Confirming a transaction: polling + webhooks

Mobile-money is asynchronous. Two ways to learn the outcome:

1. **Webhook** (push) — see §6.
2. **Status polling** (pull) — `GET /v1/payment/status/{transactionId}`.

**Recommended poll schedule** (implemented by the SDK `waitForCompletion` helper):
start after **5s**, then exponential backoff capped at **60s**, **give up after
10 minutes** and report still-pending. Stop as soon as `transaction_status` is
`SUCCESSFUL` or `FAILED`.

---

## 5. Idempotency

- Header: **`X-Idempotency-Key: <key>`** on `POST /v1/payment/collect` and
  `POST /v1/payment/payout`.
- Resolution order server-side: request header → body `idempotency_key` → an
  auto-generated deterministic key based on your API key + `transaction_id`.
- On replay within the retention window (~24h) the **original** `PaymentResult` is
  returned, with response headers `X-Idempotency-Replay: true` (and
  `X-Idempotency-Key-Auto` when the key was auto-generated).

SDK behavior: always send `X-Idempotency-Key`. If the caller doesn't supply one,
default it to the `transaction_id` (stable + unique per attempt). Surface the
replay headers to the caller.

---

## 6. Webhooks (outbound, Marvin Pay → your server)

### 6.1 Registration

The callback URL is `webhookUrl` on the merchant account (must be HTTPS),
configured via the portal / account update.

### 6.2 Delivery mechanics

- Method: `POST` your `webhookUrl`, JSON body. **Success = any 2xx.**
- Retry: a scheduler retries non-2xx every ~30s with backoff `30s × 2^attempt`
  (≈ 30s, 1m, 2m, 4m, 8m). After **5 attempts** the delivery is marked `DEAD`.
- Delivery is **at-least-once** — you may receive the same event more than once;
  **dedupe on `transactionId` + `status`.**

### 6.3 Headers

- `Content-Type: application/json`
- `X-Webhook-Attempt` — retry attempt number
- `X-Marvin-Timestamp` — epoch milliseconds
- `X-Webhook-Nonce` — UUID (replay guard)
- `X-Webhook-Delivery-Id` — unique per delivery
- `X-Webhook-Signature` — HMAC-SHA256 signature, format `sha256=<hex>`, sent when a
  webhook secret is configured on your account (see §6.5).

### 6.4 Payload

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
- Direction-aware amount split: **collect** payloads carry
  `amountChargedToCustomer` + `amountCreditedToMerchant`; **payout** payloads
  carry `amountDebitedFromMerchant` + `amountReceivedByRecipient`.

### 6.5 Securing your webhook endpoint

1. **Verify the signature** — when a webhook secret is configured on your account,
   Marvin Pay sends `X-Webhook-Signature` (`sha256=<hex>`), an HMAC-SHA256 over the
   raw request body. Verify it with your webhook secret using the `WebhookVerifier`
   shipped in each SDK.
2. **Confirm out-of-band** — on every webhook, confirm the outcome with
   `GET /v1/payment/status/{transactionId}` before acting on it (fulfilling orders,
   crediting users, etc.). This is the authoritative source of truth.
3. **Dedupe** — deliveries are at-least-once; dedupe on `transactionId` + `status`.
4. **Lock down the endpoint** — use HTTPS and a hard-to-guess URL path. Contact
   Marvin Pay support for the current webhook source IP ranges to allowlist.

---

## 7. Errors

- `200` / `202` — accepted. For async collects/payouts, inspect
  `transaction_status` (often `PENDING`) — a 200 does **not** mean money moved.
- `400` — validation error (missing/invalid field, amount out of 100–500000,
  currency/country mismatch).
- `401` — missing/unknown `X-API-KEY`.
- `403` — account blocked/inactive, or origin/IP not whitelisted (prod).
- `429` — rate limited (see §9).
- Error responses return the appropriate HTTP status code and a JSON body
  containing a human-readable `message`. SDKs surface the HTTP status, the
  `message`, and the raw body.

---

## 8. Testing

Sandbox/test access, test credentials, and test phone numbers are provided by
Marvin Pay on request — contact your Marvin Pay account manager. Run integrations
against the test environment they provide before going live.

---

## 9. Rate limits

The payment endpoints are rate-limited per API key. Over the limit ⇒ `429`. SDKs
treat `429` as retryable with backoff (respecting `Retry-After` if present).

---

## 10. Quick reference

| Method | Path (add `/api` on the wire) | Body → Response |
|--------|-------------------------------|-----------------|
| POST | `/v1/payment/collect` | `PaymentRequest` → `PaymentResult` |
| POST | `/v1/payment/payout` | `PaymentRequest` → `PaymentResult` |
| GET | `/v1/payment/status/{transactionId}` | → `TransactionStatusResponse` |
| GET | `/v1/payment/fees?currency&amount&direction&fee_bearer` | → `FeeEstimateResponse` |
| GET | `/v1/payment/payment-methods/{countryCode}` | → `string[]` |
