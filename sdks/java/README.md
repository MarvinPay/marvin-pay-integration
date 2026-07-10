# Marvin Pay — Java SDK

A lightweight, self-contained Java client for the [Marvin Pay](https://api.marvincorporate.co)
mobile-money payment gateway (West & Central Africa, **XAF** / **XOF**, mobile money
only — no card channel).

- **Java 17**, `java.net.http.HttpClient` + Jackson only. No other runtime deps.
- Covers the programmatic surface: **collect**, **payout**, **status**, **fees**,
  **payment-methods**, a `waitForCompletion` poller, and a **webhook verifier**.
- See [`../../CONTRACT.md`](../../CONTRACT.md) for the API reference.
  Deeper docs live in [`../../docs/`](../../docs/).

> **Base URL includes `/api`.** Production is `https://api.marvincorporate.co/api`
> (the SDK default). The wire path for `/v1/payment/collect` is
> `POST https://api.marvincorporate.co/api/v1/payment/collect`.

---

## Install

This SDK is not yet on Maven Central. Build and install it locally, then depend on it:

```bash
cd sdks/java
mvn install        # installs co.marvincorporate:marvinpay-sdk:0.1.0 to your local repo
```

```xml
<dependency>
  <groupId>co.marvincorporate</groupId>
  <artifactId>marvinpay-sdk</artifactId>
  <version>0.1.0</version>
</dependency>
```

The only transitive dependency is `com.fasterxml.jackson.core:jackson-databind`.

---

## Quickstart — collect, then wait for completion

Mobile-money collects are **asynchronous**: the customer approves on their handset,
so a `2xx` means *accepted*, not *paid*. Always confirm the outcome.

```java
import co.marvincorporate.pay.sdk.*;
import co.marvincorporate.pay.sdk.model.*;

MarvinPayClient client = new MarvinPayClient("YOUR_API_KEY");
// or, with overrides:
// MarvinPayClient client = new MarvinPayClient(
//     MarvinPayConfig.builder()
//         .apiKey("YOUR_API_KEY")
//         .baseUrl("https://api.marvincorporate.co/api") // default
//         .timeout(java.time.Duration.ofSeconds(30))
//         .build());

String txnId = "order-1001";

PaymentRequest req = new PaymentRequest()
        .countryCode("CM")           // currency + country ALWAYS travel together
        .currency("XAF")
        .amount(5000L)               // whole number, 100–500000, no decimals
        .mobileNumber("<your-test-msisdn>")
        .paymentMethod("mtn_cm")     // provider name — fetch the live list per country
        .transactionId(txnId)        // your unique reference
        .description("Order #1001")
        .feeBearer(FeeBearer.MERCHANT); // default; CUSTOMER grosses up the collect

// X-Idempotency-Key defaults to txnId. Pass an explicit key with collect(req, key).
PaymentResult result = client.collect(req);
System.out.println("Accepted: " + result.getTransactionStatus()); // usually PENDING

// Poll GET /v1/payment/status: first after 5s, backoff to 60s, give up after 10min.
TransactionStatusResponse status = client.waitForCompletion(txnId);
if (status.isSuccessful())      System.out.println("Paid!");
else if (status.isFailed())     System.out.println("Failed: " + status.getMessage());
else                            System.out.println("Still pending after timeout — keep checking.");
```

### Payout, fees, payment-methods

```java
// Pay out to a recipient (merchant → recipient). Same request shape as collect.
PaymentResult payout = client.payout(new PaymentRequest()
        .countryCode("CI").currency("XOF").amount(10000L)
        .mobileNumber("2250700000000").paymentMethod("orange_ci")
        .beneficiaryName("Ama Kouassi").transactionId("payout-77"));

// Fee estimate / fee-bearer split.
FeeEstimate fee = client.getFees("XAF", 5000, Direction.COLLECT, FeeBearer.CUSTOMER);

// Live provider names for a country (values you pass as payment_method).
List<String> methods = client.getPaymentMethods("CM"); // e.g. [mtn_cm, orange_cm]
```

---

## Errors

Non-2xx responses throw `MarvinPayException` carrying the HTTP status, a best-effort
message, and the raw body:

```java
try {
    client.collect(req);
} catch (MarvinPayException e) {
    System.err.println("status=" + e.getHttpStatus() + " body=" + e.getBody());
}
```

- `400` validation (bad field, amount outside 100–500000, currency/country mismatch)
- `401` missing/unknown `X-API-KEY`
- `403` account blocked/inactive or origin/IP not whitelisted (prod)
- `429` rate limited (~100 collect req/min per key)

GETs are retried **once** on `429`/`5xx` (honouring `Retry-After` if present).
Money-moving POSTs are **never** auto-retried — rely on idempotency and re-send yourself.

---

## Webhooks — read this before you trust one

The SDK ships `WebhookVerifier.verify(rawBody, signatureHeader, secret)` implementing
the scheme: HMAC-SHA256 over the raw request body, hex, compared constant-time
against `X-Webhook-Signature` (minus its `sha256=` prefix).

Marvin Pay signs webhook deliveries with this signature when your account has a
webhook secret configured. `WebhookVerifier.verify` returns `false` when the secret
or signature is missing. Because deliveries are at-least-once, the signature is never
your only trust anchor.

**Regardless of signature status:** on every webhook, **confirm out-of-band** with
`getStatus(transactionId)` before acting (fulfilling an order, crediting a user).
Dedupe on `transactionId` + `status`. See
[`../../docs/08-webhooks.md`](../../docs/08-webhooks.md) and the
[`SpringWebhookController` example](../../examples/java/src/main/java/examples/SpringWebhookController.java).

---

## Reference

- API reference: [`../../CONTRACT.md`](../../CONTRACT.md)
- Full docs: [`../../docs/`](../../docs/)
- Runnable examples: [`../../examples/java/`](../../examples/java/)
