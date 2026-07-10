# Testing & Sandbox

Marvin Pay provides a **test environment** so you can exercise your integration —
including success and failure handling — without moving real money.

## Getting sandbox access

Sandbox/test access, test credentials, and test phone numbers are provided by
Marvin Pay on request. Contact your Marvin Pay account manager to obtain:

- the base URL of the test environment,
- a test API key, and
- test phone numbers that produce deterministic `SUCCESS` / `FAILED` outcomes.

## What to test before going live

Run through every flow you use:

- **Collect** — a successful charge and a failed/declined charge.
- **Payout** — a successful payout and a failure.
- **Status polling** — confirm you correctly detect `SUCCESSFUL` / `FAILED` /
  `PENDING`. See [Transaction Status](05-transaction-status.md).
- **Webhooks** — confirm your endpoint verifies the signature (when configured),
  confirms via the status endpoint, and dedupes repeated deliveries. See
  [Webhooks](08-webhooks.md).
- **Idempotency** — retry with the same `X-Idempotency-Key` and confirm you get the
  original result rather than a second money movement. See
  [Idempotency](06-idempotency.md).

## Example (test collect)

```bash
curl -X POST "{TEST_BASE}/v1/payment/collect" \
  -H "X-API-KEY: YOUR_TEST_API_KEY" \
  -H "X-Idempotency-Key: test-1001" \
  -H "Content-Type: application/json" \
  -d '{
    "country_code": "CM",
    "currency": "XAF",
    "amount": 5000,
    "mobile_number": "2376XXXXXXXX",
    "payment_method": "mtn_cm",
    "transaction_id": "test-1001"
  }'
```

Replace `{TEST_BASE}`, the test API key, and the phone number with the values
Marvin Pay provides, then confirm the outcome via
`GET {TEST_BASE}/v1/payment/status/test-1001`.
