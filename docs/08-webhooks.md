# Webhooks

Webhooks let Marvin Pay **push** a transaction outcome to your server instead of
you polling for it. Handle them defensively: verify the signature when a webhook
secret is configured, and **always confirm the outcome via
`GET /v1/payment/status/{transactionId}`** before acting on it.

## Registration

- Your callback URL is `webhookUrl` on the merchant account (**must be HTTPS**),
  configured via the portal / account update.
- Configure a **webhook secret** on your account to receive signed deliveries
  (see [Securing your webhook endpoint](#securing-your-webhook-endpoint)).

## Events

The `event` field is one of:

| `event` | `status` |
|---------|----------|
| `transaction.success` | `SUCCESS` |
| `transaction.failed` | `FAILED` |
| `transaction.pending` | `PENDING` |
| `transaction.cancel` | `CANCEL` |

Note webhooks use `SUCCESS` while the REST status endpoint uses `SUCCESSFUL` —
normalize both. See [Reference](10-reference.md#transaction-status).

## Payload

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

**Direction-aware amount split** (see [Fees & Fee Bearer](07-fees-and-fee-bearer.md)):

| Direction | Fields present |
|-----------|----------------|
| Credit / collect | `amountChargedToCustomer` + `amountCreditedToMerchant` |
| Debit / payout | `amountDebitedFromMerchant` + `amountReceivedByRecipient` |

## Delivery, retries, and backoff

- **Method:** `POST` to your `webhookUrl` with a JSON body.
- **Success = any 2xx.** Anything else is treated as a failure and retried.
- **Retry schedule:** a scheduler retries non-2xx every ~30s with backoff
  `30s × 2^attempt` (≈ 30s, 1m, 2m, 4m, 8m).
- After **5 attempts** the delivery is marked **`DEAD`** and no longer retried.
- Delivery is **at-least-once** — you may receive the same event more than once.
  **Dedupe on `transactionId` + `status`.**

## Headers

| Header | Meaning |
|--------|---------|
| `Content-Type` | `application/json` |
| `X-Webhook-Attempt` | retry attempt number |
| `X-Marvin-Timestamp` | epoch milliseconds |
| `X-Webhook-Nonce` | UUID (replay guard) |
| `X-Webhook-Delivery-Id` | unique per delivery |
| `X-Webhook-Signature` | HMAC-SHA256 signature, format `sha256=<hex>`, sent when a webhook secret is configured on your account |

## Securing your webhook endpoint

Handle webhooks defensively:

1. **Verify the signature.** When a webhook secret is configured on your account,
   Marvin Pay sends `X-Webhook-Signature` (`sha256=<hex>`), an HMAC-SHA256 over the
   **raw request body**. Verify it with your webhook secret using the
   `WebhookVerifier` shipped in each [SDK](../sdks). Compare with a constant-time
   check after removing the `sha256=` prefix.
2. **Confirm out-of-band.** On **every** webhook, confirm the outcome with
   `GET {BASE}/v1/payment/status/{transactionId}` before acting on it (fulfilling
   orders, crediting users, etc.). This is the authoritative source of truth and
   protects you regardless of transport. See
   [Transaction Status](05-transaction-status.md).
3. **Dedupe** on `transactionId` + `status` so repeated deliveries are harmless.
4. **Lock down the endpoint.** Use HTTPS and a hard-to-guess URL path, and contact
   Marvin Pay support for the current webhook source IP ranges to allowlist.

### Verifier snippet (Node)

```js
const { verifyWebhookSignature } = require('@marvinpay/sdk/webhook-verifier');

// rawBody = the exact bytes of the request body (do not re-serialize)
const ok = verifyWebhookSignature(rawBody, req.headers['x-webhook-signature'], WEBHOOK_SECRET);
if (!ok) return res.status(400).end();
// Then still confirm via GET /v1/payment/status/{transactionId} before acting.
```

Each SDK ships an equivalent verifier — see the [SDK READMEs](../sdks).
