# Errors & Rate Limits

## HTTP status meanings

> **A 200/202 does not mean money moved.** For async collects/payouts, a 2xx means
> the request was **accepted** — you must inspect `transaction_status` (often
> `PENDING`) and confirm via [Transaction Status](05-transaction-status.md).

| Status | Meaning | What to do |
|--------|---------|-----------|
| `200` / `202` | Accepted. Inspect `transaction_status`. | Poll status / await webhook; a 2xx ≠ settled |
| `400` | Validation error — missing/invalid field, amount out of 100–500000, currency/country mismatch | Fix the request; do not retry as-is |
| `401` | Missing/unknown `X-API-KEY` | Check your API key |
| `403` | Account blocked/inactive, or origin/IP not whitelisted (prod) | Verify account status and IP/origin allowlist |
| `429` | Rate limited | Back off and retry (see below) |

See [Authentication](02-authentication.md) for the `401` / `403` conditions.

## Error body shape

Error responses return the appropriate HTTP status code and a JSON body containing
a human-readable `message`. Your integration should surface the **HTTP status +
`message` + the raw body** so you can diagnose issues.

## Rate limits

- The collect endpoint is rate-limited to roughly **100 requests/min per API key**
  (plus a global rate-limit service).
- Over the limit ⇒ **`429`**.
- Treat `429` as **retryable with backoff**. Respect a `Retry-After` response
  header if one is present.

## Retry guidance

| Situation | Retry? | How |
|-----------|--------|-----|
| `429` rate limited | Yes | Exponential backoff; honor `Retry-After`; reuse the same idempotency key |
| Network timeout / no response | Yes | Reuse the **same** `X-Idempotency-Key` so you don't double-charge |
| `400` validation error | No | Fix the payload first |
| `401` / `403` | No | Fix auth / allowlisting; retrying won't help |
| `PENDING` result | Not a retry | **Poll** status instead — do not resend the payment |

Reusing the same idempotency key on a retry guarantees a replay of the original
result instead of a second money movement. See [Idempotency](06-idempotency.md).
