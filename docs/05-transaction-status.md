# Transaction Status

`GET {BASE}/v1/payment/status/{transactionId}` is the **authoritative** way to
confirm a transaction's outcome. Use it to poll asynchronous
[collects](03-collect.md) / [payouts](04-payout.md), and to confirm every
[webhook](08-webhooks.md) before acting on it.

- **Auth:** `X-API-KEY` (see [Authentication](02-authentication.md)).
- **Path:** your `transaction_id`.

## Response — `TransactionStatusResponse`

| JSON field | Type | Notes |
|------------|------|-------|
| `transaction_id` | string | |
| `status` | number | HTTP-style code |
| `message` | string | |
| `currency` | string | |
| `timestamp` | string/number | |
| `transaction_status` | string | `SUCCESSFUL` / `FAILED` / `PENDING` |

Terminal states are `SUCCESSFUL` and `FAILED`. `PENDING` means keep polling /
await the webhook.

> The REST status field says `SUCCESSFUL`, while webhooks say `SUCCESS`. Normalize
> both in your code. See the [Reference](10-reference.md#transaction-status).

## Example

```bash
curl -X GET "https://api.marvincorporate.co/api/v1/payment/status/order-1001" \
  -H "X-API-KEY: YOUR_API_KEY"
```

```json
{
  "transaction_id": "order-1001",
  "status": 200,
  "message": "Payment successful",
  "currency": "XAF",
  "timestamp": 1752143400000,
  "transaction_status": "SUCCESSFUL"
}
```

## Recommended poll schedule

Mobile money is asynchronous, so poll rather than assume. The recommended schedule:

1. **Wait 5 seconds** after receiving the initial `PENDING` result before the first poll.
2. Then poll with **exponential backoff**, capped at **60 seconds** between polls.
3. **Give up after 10 minutes** and report the transaction as still pending.
4. **Stop as soon as** `transaction_status` is `SUCCESSFUL` or `FAILED`.

This is the same schedule a `waitForCompletion` helper would implement. Don't
hammer the endpoint at a fixed short interval — respect the backoff and the rate
limit (see [Errors & Rate Limits](09-errors-and-rate-limits.md)).
