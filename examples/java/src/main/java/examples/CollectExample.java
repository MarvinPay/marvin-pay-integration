package examples;

import co.marvincorporate.pay.sdk.MarvinPayClient;
import co.marvincorporate.pay.sdk.MarvinPayConfig;
import co.marvincorporate.pay.sdk.MarvinPayException;
import co.marvincorporate.pay.sdk.model.FeeBearer;
import co.marvincorporate.pay.sdk.model.PaymentRequest;
import co.marvincorporate.pay.sdk.model.PaymentResult;
import co.marvincorporate.pay.sdk.model.TransactionStatusResponse;

/**
 * Collect money from a customer, then wait for the transaction to resolve.
 *
 * <p>Environment:
 * <ul>
 *   <li>{@code MARVIN_API_KEY} (required) — your {@code X-API-KEY}.</li>
 *   <li>{@code MARVIN_BASE_URL} (optional) — defaults to
 *       {@code https://api.marvincorporate.co/api}. Set to
 *       {@code http://localhost:9090/api} for local dev.</li>
 * </ul>
 *
 * <p>Run (after {@code mvn install} on the SDK, then {@code mvn -q compile} here):
 * <pre>
 *   MARVIN_API_KEY=sk_... mvn -q exec:java -Dexec.mainClass=examples.CollectExample
 * </pre>
 */
public final class CollectExample {

    public static void main(String[] args) {
        String apiKey = System.getenv("MARVIN_API_KEY");
        if (apiKey == null || apiKey.isBlank()) {
            System.err.println("Set MARVIN_API_KEY to your Marvin Pay API key.");
            System.exit(1);
            return;
        }

        MarvinPayConfig.Builder cfg = MarvinPayConfig.builder().apiKey(apiKey);
        String baseUrl = System.getenv("MARVIN_BASE_URL");
        if (baseUrl != null && !baseUrl.isBlank()) {
            cfg.baseUrl(baseUrl);
        }
        MarvinPayClient client = new MarvinPayClient(cfg.build());

        // Your own unique reference for this transaction. It also becomes the default
        // X-Idempotency-Key, so re-running with the same id is safe (returns the original).
        String transactionId = "demo-collect-" + System.currentTimeMillis();

        PaymentRequest request = new PaymentRequest()
                .countryCode("CM")            // currency + country ALWAYS travel together
                .currency("XAF")
                .amount(5000L)                // whole number, 100–500000
                .mobileNumber("<your-test-msisdn>") // the payer's mobile-money number
                .paymentMethod("mtn_cm")      // provider name; see getPaymentMethods("CM")
                .transactionId(transactionId)
                .description("SDK demo collect")
                .feeBearer(FeeBearer.MERCHANT);

        try {
            PaymentResult result = client.collect(request);
            System.out.println("Collect accepted:");
            System.out.println("  transaction_id       = " + result.getTransactionId());
            System.out.println("  transaction_status   = " + result.getTransactionStatus());
            System.out.println("  partner_transaction  = " + result.getPartnerTransactionId());
            System.out.println("  message              = " + result.getMessage());
            System.out.println("  (a 2xx does NOT mean money moved — confirming...)");

            // Poll status: first after 5s, backoff to 60s, give up after 10 min.
            TransactionStatusResponse status = client.waitForCompletion(transactionId);
            System.out.println();
            System.out.println("Final status: " + status.getTransactionStatus());
            if (status.isSuccessful()) {
                System.out.println("  Payment succeeded. Safe to fulfil the order.");
            } else if (status.isFailed()) {
                System.out.println("  Payment failed: " + status.getMessage());
            } else {
                System.out.println("  Still pending after the timeout — keep polling later.");
            }
        } catch (MarvinPayException e) {
            System.err.println("Marvin Pay error: HTTP " + e.getHttpStatus() + " — " + e.getMessage());
            if (e.getBody() != null) {
                System.err.println("Response body: " + e.getBody());
            }
            System.exit(2);
        }
    }

    private CollectExample() {
    }
}
