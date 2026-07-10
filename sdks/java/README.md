# Marvin Pay — Java SDK

A lightweight, self-contained Java client for the [Marvin Pay](https://api.marvincorporate.co)
mobile-money payment gateway (West & Central Africa, **XAF** / **XOF**, mobile money
only — no card channel).

- **Java 17**, `java.net.http.HttpClient` + Jackson only. No other runtime deps.
- Covers the programmatic surface: **collect**, **payout**, **status**, **fees**,
  **payment-methods**, a `waitForCompletion` poller, the public **hosted-pay** helpers
  (invoice / campaign / QR), and a **webhook verifier**.
- Generated against [`../../CONTRACT.md`](../../CONTRACT.md) — the source of truth.
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
        .mobileNumber("237670000001")
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

### Hosted-pay helpers (public — no API key sent)

```java
// Pay an invoice reference. The invoice already carries the amount.
PaymentResult r1 = client.payInvoice("INV-abc", new PaymentRequest()
        .countryCode("CM").currency("XAF")
        .mobileNumber("237670000001").paymentMethod("mtn_cm")
        .beneficiaryName("Jane Payer").customerEmail("jane@example.com"));

// Contribute to a campaign (amount required, min 100).
PaymentResult r2 = client.contributeCampaign("CMP-xyz", new PaymentRequest()
        .countryCode("CM").currency("XAF").amount(2000L)
        .mobileNumber("237670000002").paymentMethod("orange_cm")
        .beneficiaryName("Kind Donor"));

// Pay a QR (amount ignored if the QR is fixed-amount).
PaymentResult r3 = client.payQr("QR-123", new PaymentRequest()
        .countryCode("CM").currency("XAF")
        .mobileNumber("237670000003").paymentMethod("mtn_cm")
        .beneficiaryName("Walk-in Customer"));

// Public poll for hosted-pay transactions (plain map).
Map<String, Object> qrStatus = client.getQrStatus("MARVIN-...");
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
the **intended** scheme: HMAC-SHA256 over the raw request body, hex, compared
constant-time against `X-Webhook-Signature` (minus its `sha256=` prefix).

**But webhooks are effectively UNSIGNED today.** The backend does not populate
`webhookSecret`, so `X-Webhook-Signature` is not sent, and the exact HMAC base string
is not yet finalized. The verifier is inert until backend signing lands — safe to wire
in now, it starts working automatically later.

**Regardless of signature status:** on every webhook, **confirm out-of-band** with
`getStatus(transactionId)` before acting (fulfilling an order, crediting a user).
Dedupe on `transactionId` + `status`. See
[`../../docs/12-webhooks.md`](../../docs/12-webhooks.md) and the
[`SpringWebhookController` example](../../examples/java/src/main/java/examples/SpringWebhookController.java).

---

## Not bundled (by design)

- **Portal JWT / OTP login** — the SDK accepts a `bearerToken` you already hold but does
  not run the interactive `/merchant-auth` OTP flow.
- **Bulk payout** — needs client-side AES encryption + an OTP; see
  [`../../docs/05-bulk-payout.md`](../../docs/05-bulk-payout.md).
- **Creation** of invoices/campaigns/QR codes (JWT endpoints) — create them in the
  portal; the SDK covers the public *pay* side.

## Reference

- Contract (source of truth): [`../../CONTRACT.md`](../../CONTRACT.md)
- Full docs: [`../../docs/`](../../docs/)
- Runnable examples: [`../../examples/java/`](../../examples/java/)
