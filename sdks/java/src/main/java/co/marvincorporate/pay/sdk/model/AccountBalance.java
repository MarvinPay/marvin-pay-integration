package co.marvincorporate.pay.sdk.model;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;

import java.math.BigDecimal;

/**
 * Response from {@code GET /v1/payment/balance} — the account the API key belongs to.
 *
 * <p>{@code totalBalance = available + pending + reserved + onHold}. Only
 * {@code availableBalance} is payable now.
 */
@JsonIgnoreProperties(ignoreUnknown = true)
public class AccountBalance {

    @JsonProperty("account_id")        private String accountId;
    @JsonProperty("name")              private String name;
    @JsonProperty("currency")          private String currency;
    @JsonProperty("country_code")      private String countryCode;
    @JsonProperty("status")            private String status;
    @JsonProperty("available_balance") private BigDecimal availableBalance;
    @JsonProperty("pending_balance")   private BigDecimal pendingBalance;
    @JsonProperty("reserved_balance")  private BigDecimal reservedBalance;
    @JsonProperty("on_hold_balance")   private BigDecimal onHoldBalance;
    @JsonProperty("total_balance")     private BigDecimal totalBalance;
    @JsonProperty("collect_balance")   private BigDecimal collectBalance;
    @JsonProperty("payout_balance")    private BigDecimal payoutBalance;
    /** ISO-8601 timestamp of the snapshot. */
    @JsonProperty("as_of")             private String asOf;

    public String getAccountId() { return accountId; }
    public String getName() { return name; }
    public String getCurrency() { return currency; }
    public String getCountryCode() { return countryCode; }
    public String getStatus() { return status; }
    public BigDecimal getAvailableBalance() { return availableBalance; }
    public BigDecimal getPendingBalance() { return pendingBalance; }
    public BigDecimal getReservedBalance() { return reservedBalance; }
    public BigDecimal getOnHoldBalance() { return onHoldBalance; }
    public BigDecimal getTotalBalance() { return totalBalance; }
    public BigDecimal getCollectBalance() { return collectBalance; }
    public BigDecimal getPayoutBalance() { return payoutBalance; }
    public String getAsOf() { return asOf; }
}
