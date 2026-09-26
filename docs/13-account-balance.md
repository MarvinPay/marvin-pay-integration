# Account Balance

`GET {BASE}/v1/payment/balance` returns the balances of **the account your API
key belongs to** — so your platform can show or check your Marvin Pay balance
without logging into the merchant portal.

- **Auth:** `X-API-KEY` (see [Authentication](02-authentication.md)).
- **Scope:** one key → one account. If you run several accounts (one per app,
  currency or country), call once with each account's key. The combined view of
  all your accounts is in the merchant portal. This is deliberate: a leaked key
  never exposes your other accounts.
- **Read-only:** no idempotency key needed; counts toward the normal
  [rate limit](09-errors-and-rate-limits.md).

## Response — `AccountBalanceResponse`

| JSON field | Type | Notes |
|------------|------|-------|
| `account_id` | string | |
| `name` | string | Account name from the portal |
| `currency` | string | `XAF` / `XOF` |
| `country_code` | string | ISO-3166 α2 — always read together with `currency` |
| `status` | string | e.g. `active` |
| `available_balance` | number | Cleared funds — **the amount you can pay out now** |
| `pending_balance` | number | Collections not yet settled |
| `reserved_balance` | number | Rolling reserve (chargebacks / refunds) |
| `on_hold_balance` | number | Frozen pending compliance review |
| `total_balance` | number | `available + pending + reserved + on_hold` |
| `collect_balance` | number | Inflow sub-ledger (credited by collects) |
| `payout_balance` | number | Outflow sub-ledger (debited by payouts) |
| `as_of` | string | ISO-8601 timestamp of the snapshot |

Empty buckets are returned as `0`, never `null`. Parse amounts as decimals, not
floats.

## Example

```bash
curl -H "X-API-KEY: $MARVIN_API_KEY" \
  "https://api.marvincorporate.co/api/v1/payment/balance"
```

```json
{
  "account_id": "66f1c0...",
  "name": "My Shop App",
  "currency": "XAF",
  "country_code": "CM",
  "status": "active",
  "available_balance": 150000.00,
  "pending_balance": 20000.00,
  "reserved_balance": 0,
  "on_hold_balance": 0,
  "total_balance": 170000.00,
  "collect_balance": 170000.00,
  "payout_balance": 0,
  "as_of": "2026-09-26T10:00:00Z"
}
```

## SDKs

| SDK | Call |
|-----|------|
| Node | `await client.getBalance()` |
| Python | `client.get_balance()` |
| PHP | `$client->getBalance()` |
| Laravel | `MarvinPay::getBalance()` |
| Java | `client.getBalance()` → `AccountBalance` |

## Errors

| HTTP | Meaning |
|------|---------|
| `401` | Missing or unknown `X-API-KEY` |
| `403` | Account blocked / not active, or origin not whitelisted |
| `429` | Rate limit exceeded — respect `Retry-After` |
