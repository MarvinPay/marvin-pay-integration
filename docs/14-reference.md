# Reference

All enums and fixed values in one place. These are the authoritative values from
the API contract — do not send values not listed here.

## Transaction status

Two fields with **deliberately different wording** — normalize both to one enum in
your code.

| Where | Field | Values |
|-------|-------|--------|
| REST responses ([status](09-transaction-status.md), [collect](03-collect.md), [payout](04-payout.md)) | `transaction_status` | `SUCCESSFUL` \| `FAILED` \| `PENDING` |
| Webhook payload ([webhooks](12-webhooks.md)) | `status` | `SUCCESS` \| `FAILED` \| `PENDING` \| `CANCEL` |

- **Terminal states:** success and failed.
- `PENDING` means keep polling / await the webhook.
- REST says **`SUCCESSFUL`**; webhooks say **`SUCCESS`**. This is intentional —
  treat them as the same successful outcome.

## Fee bearer — `fee_bearer`

| Value | Meaning |
|-------|---------|
| `MERCHANT` | **default** (also when omitted/null); merchant absorbs the fee |
| `CUSTOMER` | grosses up a collect / nets down a payout so the merchant receives/pays exactly `amount` |

See [Fees & Fee Bearer](11-fees-and-fee-bearer.md).

## Fee direction (for `GET /v1/payment/fees`)

| Value | Used by |
|-------|---------|
| `COLLECT` | merchants |
| `PAYOUT` | merchants |
| `TOPUP` | (internal) |
| `WITHDRAWAL` | (internal) |

Merchants use `COLLECT` / `PAYOUT`.

## Currencies & countries

Currency and country **always travel together**, and the currency must match the
country.

| Currency | Name | Countries (ISO-3166 α2) |
|----------|------|-------------------------|
| `XAF` | CFA Franc BEAC | CM, CF, TD, CG, GQ, GA |
| `XOF` | CFA Franc BCEAO | BJ, BF, CI, GW, ML, NE, SN, TG |

- `country_code` is ISO-3166 alpha-2 (e.g. `CM`); `currency` is ISO-4217 (`XAF` / `XOF`).
- **Live payment-routing countries** (those with mobile-money providers actually
  wired): **CM, GA, CI, SN, BJ, TG, ML**.

## Payment methods (`payment_method`) by country

The `payment_method` value is the **provider name string**. **Always fetch the
authoritative live list at runtime** via
`GET {BASE}/v1/payment/payment-methods/{countryCode}` (or the public
`GET {BASE}/v1/merchant/qrcode/payment-methods/{countryCode}`). Known values at
time of writing:

| Country | `payment_method` values |
|---------|-------------------------|
| CM | `mtn_cm`, `orange_cm` |
| GA | `airtel_ga`, `moov_ga` |
| CI | `mtn_ci`, `orange_ci`, `moov_ci` |
| SN | `orange_sn`, `free_money_sn`, `expresso_sn` |
| BJ | `mtn_bj`, `moov_bj` |
| TG | `t_money_tg` |
| ML | `orange_ml`, `moov_ml` |

**Mobile money only — there is no card channel.**

## Amount rules

- **Whole numbers only.** XAF and XOF have **no minor units** — never send decimals.
- **Min 100, max 500000** per transaction.
- Send as a bare JSON number (e.g. `5000`), not a string and not a decimal.
- Server type is `BigDecimal`.

## Quick reference — programmatic surface (`X-API-KEY`)

Add `/api` on the wire (already included in `{BASE}`).

| Method | Path | Body → Response |
|--------|------|-----------------|
| POST | `/v1/payment/collect` | `PaymentRequest` → `PaymentResult` |
| POST | `/v1/payment/payout` | `PaymentRequest` → `PaymentResult` |
| GET | `/v1/payment/status/{transactionId}` | → `TransactionStatusResponse` |
| GET | `/v1/payment/fees?currency&amount&direction&fee_bearer` | → `FeeEstimateResponse` |
| GET | `/v1/payment/payment-methods/{countryCode}` | → `string[]` |
