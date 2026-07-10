# Marvin Pay — Python SDK

A lightweight, self-contained Python client for the **Marvin Pay** mobile-money
payment gateway (West & Central Africa, settling in **XAF** / **XOF**).

- **Collect** from customers (payer → merchant) and **pay out** to recipients.
- Confirm asynchronous mobile-money transactions via status polling
  (`wait_for_completion`).
- Public "hosted pay" helpers for invoices, campaigns and QR codes.
- Webhook verification helper (see the honesty caveat below).

This SDK is generated from the authoritative API contract. If a field, header,
endpoint or enum is not in the contract, it is not here — and it does not exist.

- Contract (source of truth): [`../../CONTRACT.md`](../../CONTRACT.md)
- Docs / guides: [`../../docs/`](../../docs/)

> **No card channel.** Every transaction rides a mobile-money provider (MTN,
> Orange, Moov, Airtel, Free Money, Expresso, T-Money, …).

---

## Requirements

- Python **>= 3.9**
- [`requests`](https://pypi.org/project/requests/) **>= 2.28** (the only runtime
  dependency)

## Install

Editable install from this directory:

```bash
pip install -e .
```

Or install just the dependency and copy the `marvin_pay/` package into your
project:

```bash
pip install -r requirements.txt
# then copy the marvin_pay/ folder next to your code
```

---

## Quickstart — collect + wait

```python
from marvin_pay import MarvinPayClient, MarvinPayError, normalize_status

client = MarvinPayClient(api_key="YOUR_API_KEY")  # prod base URL by default

payment_request = {
    "country_code": "CM",        # currency + country ALWAYS travel together
    "currency": "XAF",
    "amount": 5000,              # whole number only, range 100..500000
    "mobile_number": "237670000001",
    "payment_method": "mtn_cm",  # fetch valid values via get_payment_methods()
    "transaction_id": "order-1001",  # YOUR unique reference
    "description": "Order #1001",
    # "fee_bearer": "CUSTOMER",  # optional; MERCHANT (default) / CUSTOMER
}

try:
    # X-Idempotency-Key defaults to payment_request["transaction_id"].
    result = client.collect(payment_request)
    print("initiated:", result)  # a 200/202 does NOT mean money moved

    if normalize_status(result.get("transaction_status")) == "PENDING":
        # Poll: wait 5s, then exponential backoff to 60s, give up after 10 min.
        final = client.wait_for_completion("order-1001")
        print("final:", normalize_status(final["transaction_status"]))
except MarvinPayError as exc:
    print("error", exc.status_code, exc.message, exc.body)
```

`normalize_status` collapses REST spellings (`SUCCESSFUL`) and webhook spellings
(`SUCCESS`) into one enum: `SUCCEEDED` / `FAILED` / `PENDING` / `CANCELLED` /
`UNKNOWN`.

### Other calls

```python
client.payout(payment_request)                       # merchant -> recipient
client.get_status("order-1001")                       # authoritative status
client.get_fees("XAF", 5000, "COLLECT", fee_bearer="CUSTOMER")
client.get_payment_methods("CM")                      # -> ["mtn_cm", "orange_cm"]

# Public "hosted pay" flows (payer pays a reference; no API key needed server-side)
client.pay_invoice("INV-REF", {...})                  # PayInvoiceRequest
client.contribute_campaign("CMP-REF", {...})          # ContributeRequest
client.pay_qr("QR-REF", {...})                        # QRPaymentRequest
client.get_qr_status("MARVIN-...")                    # public poll (invoice/campaign/QR)
```

After a `collect` / `payout`, idempotency replay headers are available on
`client.last_response_headers` (e.g. `X-Idempotency-Replay`,
`X-Idempotency-Key-Auto`).

---

## Webhooks — read this (verify-then-confirm)

**Outbound webhooks are effectively UNSIGNED today.** `webhookSecret` is not
populated by any backend code path, so `X-Webhook-Signature` is not sent, and the
HMAC base string is not yet aligned with the verifier. `verify_signature` is
therefore **inert** in production right now (no signature header → returns
`False`).

Because of that, the trust anchor is **not** the signature — it is a
verify-then-**confirm** flow:

1. Optionally `verify_signature(raw_body, x_webhook_signature, secret)` — safe to
   wire in now; it becomes effective automatically once backend signing lands.
2. **Always confirm out-of-band** with `client.get_status(transaction_id)` before
   acting on the event (fulfilling orders, crediting users, …).
3. **Dedupe on `transactionId` + `status`** — the same event may be delivered
   more than once.
4. Return any `2xx` quickly; Marvin Pay retries non-2xx (~5 attempts) then marks
   the delivery `DEAD`.

```python
from marvin_pay import verify_signature, parse_event

raw = request.get_data()  # exact raw bytes — do NOT re-serialize parsed JSON
verify_signature(raw, request.headers.get("X-Webhook-Signature"), secret)  # inert today
event = parse_event(raw)
status = client.get_status(event["transactionId"])  # the real trust anchor
```

See [`../../examples/python/webhook_flask.py`](../../examples/python/webhook_flask.py)
for a complete example.

---

## Configuration

`MarvinPayClient(api_key, base_url=..., bearer_token=None, timeout=30)`

| Parameter | Default | Notes |
|-----------|---------|-------|
| `api_key` | *(required)* | Sent as `X-API-KEY` on `/v1/payment/**`. May be empty for purely public hosted-pay flows. |
| `base_url` | `https://api.marvincorporate.co/api` | Include the `/api` context path. Local dev: `http://localhost:9090/api`. |
| `bearer_token` | `None` | Optional portal JWT, sent as `Authorization: Bearer`. Needed only for portal / creation / bulk endpoints. The SDK does not run the interactive OTP login. |
| `timeout` | `30` | Per-request timeout in seconds. |

**Behavior baked in from the contract:**

- **Amounts** are whole numbers (XAF/XOF have no minor units), range
  **100..500000**. The SDK coerces `amount` to an integer and raises
  `ValueError` on a fractional value.
- **Currency + country travel together** on every money request.
- **Idempotency:** `collect` / `payout` always send `X-Idempotency-Key`,
  defaulting to the `transaction_id`.
- **Retries:** GETs retry once on `429` / `5xx` (honouring `Retry-After`). POSTs
  are not auto-retried — reuse the same idempotency key to retry safely yourself.
- **Errors:** non-2xx responses raise `MarvinPayError` carrying `status_code`,
  `message`, and the raw `body`.

## Reference values (see the contract for the full list)

- **Statuses** — REST `transaction_status`: `SUCCESSFUL | FAILED | PENDING`;
  webhook `status`: `SUCCESS | FAILED | PENDING | CANCEL`.
- **Fee bearer** — `MERCHANT` (default) | `CUSTOMER`.
- **Fee direction** — `COLLECT | PAYOUT` (merchants); `TOPUP | WITHDRAWAL` exist
  server-side.
- **Currencies / countries** — XAF: CM, CF, TD, CG, GQ, GA · XOF: BJ, BF, CI, GW,
  ML, NE, SN, TG. Live routing: CM, GA, CI, SN, BJ, TG, ML.
- **Payment methods** — provider-name strings; always fetch via
  `get_payment_methods(country_code)`.

---

## Examples

Runnable scripts live in [`../../examples/python/`](../../examples/python/):

| File | What it shows |
|------|---------------|
| `collect.py` | Collect, then `wait_for_completion`. |
| `payout.py` | Single payout. |
| `poll_status.py` | Check / poll a transaction's status. |
| `webhook_flask.py` | Receive a webhook (verify → confirm → dedupe → 200). Flask is a dev-only example dependency. |
| `pay_invoice.py` | Public `pay_invoice` flow. |
| `.env.example` | Environment variables the examples read. |
