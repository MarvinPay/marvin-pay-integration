package co.marvincorporate.pay.sdk.model;

import com.fasterxml.jackson.annotation.JsonAutoDetect;
import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;

/**
 * Response from {@code GET /v1/payment/status/{transactionId}} — the
 * <b>authoritative</b> way to confirm a transaction's outcome.
 *
 * <p>Terminal states are {@code SUCCESSFUL} and {@code FAILED}; {@code PENDING}
 * means keep polling / await a webhook.
 */
@JsonIgnoreProperties(ignoreUnknown = true)
@JsonAutoDetect(
        fieldVisibility = JsonAutoDetect.Visibility.ANY,
        getterVisibility = JsonAutoDetect.Visibility.NONE,
        isGetterVisibility = JsonAutoDetect.Visibility.NONE,
        setterVisibility = JsonAutoDetect.Visibility.NONE)
public class TransactionStatusResponse {

    @JsonProperty("transaction_id")
    private String transactionId;

    /** HTTP-style code (a number), not the status string. */
    @JsonProperty("status")
    private Integer status;

    @JsonProperty("message")
    private String message;

    @JsonProperty("currency")
    private String currency;

    /** May arrive as a string or a number, so it is exposed as {@link Object}. */
    @JsonProperty("timestamp")
    private Object timestamp;

    /** {@code SUCCESSFUL} / {@code FAILED} / {@code PENDING}. */
    @JsonProperty("transaction_status")
    private String transactionStatus;

    public TransactionStatusResponse() {
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

    public String getCurrency() {
        return currency;
    }

    public Object getTimestamp() {
        return timestamp;
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

    /** True when {@code transaction_status == PENDING}. */
    public boolean isPending() {
        return "PENDING".equalsIgnoreCase(transactionStatus);
    }

    /** True once the transaction has reached a terminal state (succeeded or failed). */
    public boolean isTerminal() {
        return isSuccessful() || isFailed();
    }

    @Override
    public String toString() {
        return "TransactionStatusResponse{transactionId=" + transactionId
                + ", status=" + status
                + ", transactionStatus=" + transactionStatus
                + ", currency=" + currency
                + ", timestamp=" + timestamp
                + ", message=" + message + '}';
    }
}
