# Idempotency

Mobile-money requests can time out or be retried. Idempotency keys guarantee that
a retried request **does not move money twice** — a replay returns the original
result instead of starting a new transaction.

## The `X-Idempotency-Key` header

Send `X-Idempotency-Key: <key>` on the money-moving POSTs:

- `POST {BASE}/v1/payment/collect` ([Collect](03-collect.md))
- `POST {BASE}/v1/payment/payout` ([Payout](04-payout.md))

```bash
curl -X POST "https://api.marvincorporate.co/api/v1/payment/collect" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "X-Idempotency-Key: order-1001-attempt-1" \
  -H "Content-Type: application/json" \
  -d '{ "country_code": "CM", "currency": "XAF", "amount": 5000,
        "mobile_number": "237670000001", "payment_method": "mtn_cm",
        "transaction_id": "order-1001" }'
```

## Resolution order

The server picks the effective idempotency key in this order:

1. The **`X-Idempotency-Key` request header** (if present).
2. The body field **`idempotency_key`** (if present).
3. An **auto-generated deterministic key**: `auto:{apiKey}:{transactionId}`.

Because of step 3, even if you send no key at all, requests with the same
`transaction_id` under the same API key are naturally de-duplicated. Still,
sending an explicit key is best practice.

## Replay behavior and headers

- On replay **within the retention window (~24h)**, the server returns the
  **original** `PaymentResult` — it does not re-run the payment.
- Replays carry response headers:

| Response header | Meaning |
|-----------------|---------|
| `X-Idempotency-Replay: true` | this response is a replay of a prior request |
| `X-Idempotency-Key-Auto` | present when the key was auto-generated (step 3) |

Surface these headers to your calling code so you can tell a fresh result from a
replay.

## Bulk payout: `batch_reference`

[Bulk payout](05-bulk-payout.md) does not use `X-Idempotency-Key`. Its idempotency
key is the **`batch_reference`** body field — resubmitting the same
`batch_reference` will not create a duplicate batch.

## Best practices

- **Always send `X-Idempotency-Key`.** If you don't have a natural key, default it
  to your `transaction_id` (stable and unique per attempt).
- Use a **new key per genuinely new payment attempt**, and the **same key** when
  retrying the exact same request after a network error/timeout.
- Keep the key stable across retries but unique across distinct transactions.
- Treat a `429` or a network timeout as **retryable with the same key** — see
  [Errors & Rate Limits](13-errors-and-rate-limits.md).
