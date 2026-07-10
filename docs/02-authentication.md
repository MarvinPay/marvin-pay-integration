# Authentication

The payment API uses a single authentication scheme: an **API key** sent in the
`X-API-KEY` header on every `/v1/payment/**` call.

- **Header:** `X-API-KEY: <your api key>`.
- Obtain your API key from the merchant portal or your Marvin Pay account manager.

```bash
curl -X GET "https://api.marvincorporate.co/api/v1/payment/payment-methods/CM" \
  -H "X-API-KEY: YOUR_API_KEY"
```

## Production hardening: origin / IP whitelisting

In production the server validates each request against:

- the request `Origin` / `Referer` against the account's **whitelisted origins**, and
- the source **IP** against the merchant's **whitelisted IPs**.

Server-to-server calls should originate from a **whitelisted IP**. Rate limiting
also applies — see [Errors & Rate Limits](09-errors-and-rate-limits.md).

## Auth responses

| Status | Meaning |
|--------|---------|
| `401` | API key missing or unknown |
| `403` | Account blocked/inactive, or origin/IP not whitelisted (prod) |

Keep your API key secret: use it only from your backend, never in browser or mobile
client code. Store it in an environment variable, not in source control.
