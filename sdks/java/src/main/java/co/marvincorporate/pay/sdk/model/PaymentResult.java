package co.marvincorporate.pay.sdk.model;

import com.fasterxml.jackson.annotation.JsonAutoDetect;
import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;

/**
 * Response from {@code collect}, {@code payout}, and the hosted-pay endpoints
 * (invoice pay, campaign contribute, QR pay).
 *
 * <p><b>A 2xx does not mean money moved.</b> Mobile-money collects/payouts are
 * typically asynchronous: expect {@link #getTransactionStatus()} to be
 * {@code PENDING}, then confirm the outcome by polling
 * {@code GET /v1/payment/status/{transactionId}} (see
 * {@code MarvinPayClient.waitForCompletion}) or via a webhook.
 */
@JsonIgnoreProperties(ignoreUnknown = true)
@JsonAutoDetect(
        fieldVisibility = JsonAutoDetect.Visibility.ANY,
        getterVisibility = JsonAutoDetect.Visibility.NONE,
        isGetterVisibility = JsonAutoDetect.Visibility.NONE,
        setterVisibility = JsonAutoDetect.Visibility.NONE)
public class PaymentResult {

    /** Echoes your reference. */
    @JsonProperty("transaction_id")
    private String transactionId;

    /** HTTP-style code (e.g. 200 / 202). NOTE: this is a number, not the status string. */
    @JsonProperty("status")
    private Integer status;

    @JsonProperty("message")
    private String message;

    /** Gateway/operator reference. */
    @JsonProperty("partner_transaction_id")
    private String partnerTransactionId;

    /** {@code SUCCESSFUL} / {@code FAILED} / {@code PENDING}. */
    @JsonProperty("transaction_status")
    private String transactionStatus;

    public PaymentResult() {
    }

    public String getTransactionId() {
        return transactionId;
    }

    public Integer getStatus() {
        return status;
    }

    public String getMessage() {
        return message;
    }

    public String getPartnerTransactionId() {
        return partnerTransactionId;
    }

    public String getTransactionStatus() {
        return transactionStatus;
    }

    /** True when {@code transaction_status == SUCCESSFUL}. */
    public boolean isSuccessful() {
        return "SUCCESSFUL".equalsIgnoreCase(transactionStatus);
    }

    /** True when {@code transaction_status == FAILED}. */
    public boolean isFailed() {
        return "FAILED".equalsIgnoreCase(transactionStatus);
    }

    /** True when {@code transaction_status == PENDING} (keep polling / await webhook). */
    public boolean isPending() {
        return "PENDING".equalsIgnoreCase(transactionStatus);
    }

    @Override
    public String toString() {
        return "PaymentResult{transactionId=" + transactionId
                + ", status=" + status
                + ", transactionStatus=" + transactionStatus
                + ", partnerTransactionId=" + partnerTransactionId
                + ", message=" + message + '}';
    }
}
