package examples;

import co.marvincorporate.pay.sdk.MarvinPayClient;
import co.marvincorporate.pay.sdk.MarvinPayConfig;
import co.marvincorporate.pay.sdk.MarvinPayException;
import co.marvincorporate.pay.sdk.model.Direction;
import co.marvincorporate.pay.sdk.model.FeeBearer;
import co.marvincorporate.pay.sdk.model.FeeEstimate;
import co.marvincorporate.pay.sdk.model.PaymentRequest;
import co.marvincorporate.pay.sdk.model.PaymentResult;
import co.marvincorporate.pay.sdk.model.TransactionStatusResponse;

/**
 * Pay out to a recipient (merchant → recipient), previewing the fee first.
 *
 * <p>Environment: {@code MARVIN_API_KEY} (required),
 * {@code MARVIN_BASE_URL} (optional, defaults to production).
 *
 * <p>Run:
 * <pre>
 *   MARVIN_API_KEY=sk_... mvn -q exec:java -Dexec.mainClass=examples.PayoutExample
 * </pre>
 */
public final class PayoutExample {

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

        long amount = 10000L; // whole number, 100–500000
        String transactionId = "demo-payout-" + System.currentTimeMillis();

        try {
            // 1) Preview the fee-bearer split before moving money.
            FeeEstimate fee = client.getFees("XOF", amount, Direction.PAYOUT, FeeBearer.MERCHANT);
            System.out.println("Fee preview (PAYOUT, XOF " + amount + "):");
            System.out.println("  amountDebitedFromMerchant  = " + fee.getAmountDebitedFromMerchant());
            System.out.println("  amountReceivedByRecipient  = " + fee.getAmountReceivedByRecipient());
            System.out.println("  extra fields               = " + fee.getAdditionalProperties());

            // 2) Send the payout. beneficiary_name / mobile_number identify the RECIPIENT.
            PaymentRequest request = new PaymentRequest()
                    .countryCode("CI")             // currency + country ALWAYS travel together
                    .currency("XOF")
                    .amount(amount)
                    .mobileNumber("2250700000000")
                    .paymentMethod("orange_ci")    // see getPaymentMethods("CI")
                    .beneficiaryName("Ama Kouassi")
                    .transactionId(transactionId)
                    .description("SDK demo payout")
                    .feeBearer(FeeBearer.MERCHANT);

            PaymentResult result = client.payout(request); // X-Idempotency-Key defaults to transactionId
            System.out.println();
            System.out.println("Payout accepted: " + result.getTransactionStatus()
                    + " (" + result.getMessage() + ")");

            // 3) Confirm the outcome.
            TransactionStatusResponse status = client.waitForCompletion(transactionId);
            System.out.println("Final status: " + status.getTransactionStatus());
        } catch (MarvinPayException e) {
            System.err.println("Marvin Pay error: HTTP " + e.getHttpStatus() + " — " + e.getMessage());
            if (e.getBody() != null) {
                System.err.println("Response body: " + e.getBody());
            }
            System.exit(2);
        }
    }

    private PayoutExample() {
    }
}
