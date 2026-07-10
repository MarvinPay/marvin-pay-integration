# Webhooks

Webhooks let Marvin Pay **push** a transaction outcome to your server instead of
you polling for it. Treat every webhook as a **hint** — always confirm via
`GET /v1/payment/status/{transactionId}` before acting. See the
[Current signature status](#current-signature-status--read-this) section below for
why this matters today.

## Registration

- Your callback URL is `webhookUrl` on the merchant account (**must be HTTPS**),
  configured via the portal / account update.
- The signing secret is `webhookSecret` (see the signature status section — it is
  **not populated today**).

## Events

The `event` field is one of:

| `event` | `status` |
|---------|----------|
| `transaction.success` | `SUCCESS` |
| `transaction.failed` | `FAILED` |
| `transaction.pending` | `PENDING` |
| `transaction.cancel` | `CANCEL` |

Note webhooks use `SUCCESS` while the REST status endpoint uses `SUCCESSFUL` —
normalize both. See [Reference](14-reference.md#transaction-status).

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

**Direction-aware amount split** (see [Fees & Fee Bearer](11-fees-and-fee-bearer.md)):

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
- Delivery is **idempotent per `transactionId`** — you may receive the same event
  more than once. **Dedupe on `transactionId` + `status`.**

## Headers

| Header | Meaning |
|--------|---------|
| `Content-Type` | `application/json` |
| `X-Webhook-Attempt` | retry attempt number |
| `X-Marvin-Timestamp` | epoch milliseconds |
| `X-Webhook-Nonce` | UUID (replay guard) |
| `X-Webhook-Delivery-Id` | unique per delivery |
| `X-Webhook-Signature` | **only sent when `webhookSecret` is set** — format `sha256=<hex>` (HMAC-SHA256). See below. |

## Current signature status — READ THIS

As of this writing, outbound webhooks are effectively **UNSIGNED**:

- `webhookSecret` is **not populated by any code path**, so `X-Webhook-Signature`
  is **not sent** today.
- The HMAC scheme (base string + timestamp field name) is **not yet aligned** with
  the SDK verifier, so signature verification is **not usable in production yet**.

**Do not overstate webhook security.** Because signatures are not usable yet:

1. **Do not rely on the signature** as your only trust anchor right now.
2. On **every** webhook, **confirm out-of-band** with
   `GET {BASE}/v1/payment/status/{transactionId}` before acting on it (fulfilling
   orders, crediting users, etc.). See [Transaction Status](09-transaction-status.md).
3. **Dedupe on `transactionId` + `status`** so repeated deliveries are harmless.
4. Restrict your webhook endpoint: allowlist Marvin Pay egress IPs (`⟨CONFIRM⟩`)
   and use a hard-to-guess URL path.

### Intended HMAC scheme (forward-compatible)

Documented for forward-compatibility only. The **intended** scheme is
**HMAC-SHA256 over the raw request body**, compared against the
`X-Webhook-Signature` value with its `sha256=` prefix removed, using a
constant-time comparison. You can wire a verifier in now: it becomes effective
automatically once backend signing is enabled. **Until then, keep the
status-confirmation step (#2) regardless** — the signature check cannot be your
trust anchor today.
