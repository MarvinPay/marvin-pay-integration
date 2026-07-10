package co.marvincorporate.pay.sdk.model;

import com.fasterxml.jackson.annotation.JsonAnyGetter;
import com.fasterxml.jackson.annotation.JsonAnySetter;
import com.fasterxml.jackson.annotation.JsonAutoDetect;
import com.fasterxml.jackson.annotation.JsonIgnore;
import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;

import java.math.BigDecimal;
import java.util.LinkedHashMap;
import java.util.Map;

/**
 * Response from {@code GET /v1/payment/fees}.
 *
 * <p>The known fields are modelled below. The server may return additional fields
 * (including the fee amount itself, whose exact key can vary); those are captured
 * in {@link #getAdditionalProperties()} so nothing is lost. Treat the live
 * response as authoritative.
 *
 * <p>Depending on {@link #getDirection()} only one side of the split is populated:
 * a <b>collect</b> carries {@code amountChargedToCustomer} +
 * {@code amountCreditedToMerchant}; a <b>payout</b> carries
 * {@code amountDebitedFromMerchant} + {@code amountReceivedByRecipient}.
 */
@JsonIgnoreProperties(ignoreUnknown = true)
@JsonAutoDetect(
        fieldVisibility = JsonAutoDetect.Visibility.ANY,
        getterVisibility = JsonAutoDetect.Visibility.NONE,
        isGetterVisibility = JsonAutoDetect.Visibility.NONE,
        setterVisibility = JsonAutoDetect.Visibility.NONE)
public class FeeEstimate {

    @JsonProperty("baseAmount")
    private BigDecimal baseAmount;

    @JsonProperty("feeBearer")
    private String feeBearer;

    @JsonProperty("direction")
    private String direction;

    @JsonProperty("amountChargedToCustomer")
    private BigDecimal amountChargedToCustomer;

    @JsonProperty("amountCreditedToMerchant")
    private BigDecimal amountCreditedToMerchant;

    @JsonProperty("amountDebitedFromMerchant")
    private BigDecimal amountDebitedFromMerchant;

    @JsonProperty("amountReceivedByRecipient")
    private BigDecimal amountReceivedByRecipient;

    /** Any fields the server returns that are not modelled above (e.g. the fee amount). */
    @JsonIgnore
    private final Map<String, Object> additionalProperties = new LinkedHashMap<>();

    public FeeEstimate() {
    }

    public BigDecimal getBaseAmount() {
        return baseAmount;
    }

    public String getFeeBearer() {
        return feeBearer;
    }

    public String getDirection() {
        return direction;
    }

    public BigDecimal getAmountChargedToCustomer() {
        return amountChargedToCustomer;
    }

    public BigDecimal getAmountCreditedToMerchant() {
        return amountCreditedToMerchant;
    }

    public BigDecimal getAmountDebitedFromMerchant() {
        return amountDebitedFromMerchant;
    }

    public BigDecimal getAmountReceivedByRecipient() {
        return amountReceivedByRecipient;
    }

    @JsonAnyGetter
    public Map<String, Object> getAdditionalProperties() {
        return additionalProperties;
    }

    @JsonAnySetter
    public void setAdditionalProperty(String key, Object value) {
        additionalProperties.put(key, value);
    }

    @Override
    public String toString() {
        return "FeeEstimate{baseAmount=" + baseAmount
                + ", feeBearer=" + feeBearer
                + ", direction=" + direction
                + ", amountChargedToCustomer=" + amountChargedToCustomer
                + ", amountCreditedToMerchant=" + amountCreditedToMerchant
                + ", amountDebitedFromMerchant=" + amountDebitedFromMerchant
                + ", amountReceivedByRecipient=" + amountReceivedByRecipient
                + ", additionalProperties=" + additionalProperties + '}';
    }
}
