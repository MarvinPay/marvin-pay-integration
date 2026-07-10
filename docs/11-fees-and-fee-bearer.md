# Fees & Fee Bearer

Every collect and payout carries a fee. The **`fee_bearer`** field decides who
absorbs it: the **merchant** (default) or the **customer**. Quote the fee up front
so you can show the right numbers before the transaction runs.

## Estimate a fee — `GET /v1/payment/fees`

`GET {BASE}/v1/payment/fees` returns a fee estimate.

- **Auth:** `X-API-KEY`.
- **Query params:**

| Param | Required | Notes |
|-------|----------|-------|
| `currency` | ✅ | `XAF` / `XOF` |
| `amount` | ✅ | whole number, 100–500000 |
| `direction` | ✅ | `COLLECT` or `PAYOUT` |
| `fee_bearer` | ❌ | `MERCHANT` (default) / `CUSTOMER` |

```bash
curl -X GET "https://api.marvincorporate.co/api/v1/payment/fees?currency=XAF&amount=5000&direction=COLLECT&fee_bearer=MERCHANT" \
  -H "X-API-KEY: YOUR_API_KEY"
```

## Response — `FeeEstimateResponse`

Known fields (treat the live response as authoritative for any extras):

| Field | Notes |
|-------|-------|
| `baseAmount` | the `amount` you quoted |
| `feeBearer` | `MERCHANT` / `CUSTOMER` |
| `direction` | `COLLECT` / `PAYOUT` |
| `amountChargedToCustomer` | what the payer/customer pays (collect side) |
| `amountCreditedToMerchant` | what the merchant receives (collect side) |
| `amountDebitedFromMerchant` | what the merchant pays (payout side) |
| `amountReceivedByRecipient` | what the recipient receives (payout side) |
| *(fee amount)* | the fee itself |

The public hosted-pay flows expose the same split as a **`FeeQuote`** via their
`/quote` endpoints — see [Invoices](06-invoices.md), [Campaigns](07-campaigns.md),
[QR Codes](08-qr-codes.md).

## MERCHANT vs CUSTOMER semantics

- **`fee_bearer` omitted or null ⇒ `MERCHANT`.**
- **`MERCHANT`** — the merchant absorbs the fee. On a collect, the customer pays
  exactly `amount` and the merchant is credited `amount − fee`. On a payout, the
  recipient receives exactly `amount` and the merchant is debited `amount + fee`.
- **`CUSTOMER`** — the fee is shifted to the customer. It **grosses up a collect**
  and **nets down a payout** so that the **merchant receives / pays exactly
  `amount`**.

## Worked example

Assume a transaction of **`amount = 5000 XAF`** whose fee resolves to **100 XAF**
(always take the real number from the quote — fees are not a fixed percentage).

### COLLECT

| `fee_bearer` | Customer pays | Merchant credited |
|--------------|---------------|-------------------|
| `MERCHANT` (default) | 5000 | 4900 (`amount − fee`) |
| `CUSTOMER` | 5100 (`amount + fee`) | 5000 (exactly `amount`) |

With `MERCHANT`, the customer pays the round `5000` and you eat the `100`. With
`CUSTOMER`, the charge is grossed up to `5100` so you net the full `5000`.

### PAYOUT

| `fee_bearer` | Merchant debited | Recipient receives |
|--------------|------------------|--------------------|
| `MERCHANT` (default) | 5100 (`amount + fee`) | 5000 (exactly `amount`) |
| `CUSTOMER` | 5000 (exactly `amount`) | 4900 (`amount − fee`) |

With `MERCHANT`, the recipient gets the full `5000` and you pay the `100` on top.
With `CUSTOMER`, you pay exactly `5000` and the fee is netted out of what the
recipient receives.

## How this appears on webhooks

Webhook payloads carry the direction-aware split too: collect/credit events
include `amountChargedToCustomer` + `amountCreditedToMerchant`; payout/debit
events include `amountDebitedFromMerchant` + `amountReceivedByRecipient`, plus
`feeBearer`. See [Webhooks](12-webhooks.md).
