# Marvin Pay — Java SDK examples

Runnable examples for the [Marvin Pay Java SDK](../../sdks/java/).

| File | What it shows |
|------|----------------|
| [`CollectExample.java`](src/main/java/examples/CollectExample.java) | Collect from a customer, then `waitForCompletion`. |
| [`PayoutExample.java`](src/main/java/examples/PayoutExample.java) | Preview fees, pay out to a recipient, confirm status. |
| [`SpringWebhookController.java`](src/main/java/examples/SpringWebhookController.java) | Safe webhook handling in a Spring `@RestController` (snippet — needs Spring). |

## Prerequisites

Build and install the SDK into your local Maven repo first:

```bash
cd ../../sdks/java
mvn install          # installs co.marvincorporate:marvinpay-sdk:0.1.0
```

Then, from this directory:

```bash
mvn -q compile
```

## Running the console examples

```bash
export MARVIN_API_KEY=your_api_key
# optional; defaults to https://api.marvincorporate.co/api
export MARVIN_BASE_URL=http://localhost:9090/api

mvn -q exec:java -Dexec.mainClass=examples.CollectExample
mvn -q exec:java -Dexec.mainClass=examples.PayoutExample
```

(If you don't have the `exec-maven-plugin` configured, run the compiled classes on the
classpath produced by `mvn dependency:build-classpath`, or import the module into your IDE.)

The examples use the sandbox-style Cameroon MTN number `237670000001`. Note that
**sandbox magic numbers only short-circuit the hosted-pay flows** (invoice/campaign/QR)
and only on the backend `dev` profile — direct API collects/payouts always hit the real
gateway. See [`../../docs/15-testing-and-sandbox.md`](../../docs/15-testing-and-sandbox.md).

## About `SpringWebhookController.java`

This one is an **example snippet**, not a standalone program. It requires **Spring Web**
on the classpath (in a real service, `spring-boot-starter-web`). In this module Spring is
declared with `provided` scope purely so the file compiles alongside the console examples;
it is not a runnable Spring Boot app on its own.

Drop the class into your own Spring Boot application and register a `MarvinPayClient` bean,
e.g.:

```java
@Bean
MarvinPayClient marvinPayClient() {
    return new MarvinPayClient(System.getenv("MARVIN_API_KEY"));
}
```

It demonstrates the mandatory safe-webhook recipe: take the raw body, run the (currently
inert) signature check, **confirm out-of-band via `getStatus` before acting**, dedupe on
`transactionId + status`, and return `200`. See
[`../../docs/12-webhooks.md`](../../docs/12-webhooks.md).

### Don't want Spring on the classpath?

If you only want the two console examples, delete `SpringWebhookController.java` (or remove
the two `spring-*` dependencies from `pom.xml` and exclude the file from compilation). The
console examples depend on the SDK alone.
