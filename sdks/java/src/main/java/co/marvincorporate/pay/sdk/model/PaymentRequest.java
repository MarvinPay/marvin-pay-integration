package co.marvincorporate.pay.sdk.model;

import com.fasterxml.jackson.annotation.JsonAutoDetect;
import com.fasterxml.jackson.annotation.JsonInclude;
import com.fasterxml.jackson.annotation.JsonProperty;

/**
 * Request body for the core money-moving calls and the hosted-pay helpers.
 *
 * <p>This one class covers every request shape the SDK sends because they are all
 * subsets of the same {@code snake_case} field set:
 * <ul>
 *   <li>{@code POST /v1/payment/collect} and {@code /payout} — use all the core fields.</li>
 *   <li>{@code POST /v1/invoices/{ref}/pay} — set {@link #countryCode},
 *       {@link #currency}, {@link #mobileNumber}, {@link #paymentMethod},
 *       {@link #beneficiaryName} (required), {@link #customerEmail} (optional).
 *       The invoice already carries the amount, so leave {@link #amount} unset.</li>
 *   <li>{@code POST /v1/campaigns/{ref}/contribute} — as above plus {@link #amount}.</li>
 *   <li>{@code POST /v1/merchant/qrcode/pay/{ref}} — as invoice-pay plus an optional
 *       {@link #amount} (ignored if the QR has a fixed amount).</li>
 * </ul>
 *
 * <p>{@code null} fields are omitted from the JSON, so you only populate what a
 * given endpoint needs.
 *
 * <p><b>Amounts are whole numbers.</b> XAF and XOF have no minor units — never
 * send decimals. Per-transaction range is 100–500000. This field is a {@link Long}.
 *
 * <p><b>Currency and country travel together.</b> Always set both
 * {@link #countryCode} and {@link #currency}.
 *
 * <p>Fluent setters return {@code this} so requests can be built in one expression.
 */
@JsonInclude(JsonInclude.Include.NON_NULL)
@JsonAutoDetect(
        fieldVisibility = JsonAutoDetect.Visibility.ANY,
        getterVisibility = JsonAutoDetect.Visibility.NONE,
        isGetterVisibility = JsonAutoDetect.Visibility.NONE,
        setterVisibility = JsonAutoDetect.Visibility.NONE)
public class PaymentRequest {

    @JsonProperty("country_code")
    private String countryCode;

    @JsonProperty("currency")
    private String currency;

    /** Whole number, 100–500000. Serialized as a bare JSON number. */
    @JsonProperty("amount")
    private Long amount;

    @JsonProperty("mobile_number")
    private String mobileNumber;

    /** Provider-name string, e.g. {@code mtn_cm}, {@code orange_cm}. See payment-methods. */
    @JsonProperty("payment_method")
    private String paymentMethod;

    /** Your unique reference for this transaction. */
    @JsonProperty("transaction_id")
    private String transactionId;

    /** For collect: the payer's name. For payout / hosted-pay: the recipient/payer name. */
    @JsonProperty("beneficiary_name")
    private String beneficiaryName;

    @JsonProperty("description")
    private String description;

    /** If set, the gateway sends a receipt email. */
    @JsonProperty("customer_email")
    private String customerEmail;

    /** {@code MERCHANT} (default) or {@code CUSTOMER}. Null ⇒ MERCHANT server-side. */
    @JsonProperty("fee_bearer")
    private FeeBearer feeBearer;

    /**
     * Optional in-body idempotency key. Prefer the {@code X-Idempotency-Key} header
     * (the SDK sends it for you). Server resolution order is:
     * header &rarr; this field &rarr; auto {@code auto:{apiKey}:{transaction_id}}.
     */
    @JsonProperty("idempotency_key")
    private String idempotencyKey;

    public PaymentRequest() {
    }

    // --- fluent setters -------------------------------------------------

    public PaymentRequest countryCode(String countryCode) {
        this.countryCode = countryCode;
        return this;
    }

    public PaymentRequest currency(String currency) {
        this.currency = currency;
        return this;
    }

    public PaymentRequest amount(Long amount) {
        this.amount = amount;
        return this;
    }

    public PaymentRequest amount(long amount) {
        this.amount = amount;
        return this;
    }

    public PaymentRequest mobileNumber(String mobileNumber) {
        this.mobileNumber = mobileNumber;
        return this;
    }

    public PaymentRequest paymentMethod(String paymentMethod) {
        this.paymentMethod = paymentMethod;
        return this;
    }

    public PaymentRequest transactionId(String transactionId) {
        this.transactionId = transactionId;
        return this;
    }

    public PaymentRequest beneficiaryName(String beneficiaryName) {
        this.beneficiaryName = beneficiaryName;
        return this;
    }

    public PaymentRequest description(String description) {
        this.description = description;
        return this;
    }

    public PaymentRequest customerEmail(String customerEmail) {
        this.customerEmail = customerEmail;
        return this;
    }

    public PaymentRequest feeBearer(FeeBearer feeBearer) {
        this.feeBearer = feeBearer;
        return this;
    }

    public PaymentRequest idempotencyKey(String idempotencyKey) {
        this.idempotencyKey = idempotencyKey;
        return this;
    }

    // --- getters --------------------------------------------------------

    public String getCountryCode() {
        return countryCode;
    }

    public String getCurrency() {
        return currency;
    }

    public Long getAmount() {
        return amount;
    }

    public String getMobileNumber() {
        return mobileNumber;
    }

    public String getPaymentMethod() {
        return paymentMethod;
    }

    public String getTransactionId() {
        return transactionId;
    }

    public String getBeneficiaryName() {
        return beneficiaryName;
    }

    public String getDescription() {
        return description;
    }

    public String getCustomerEmail() {
        return customerEmail;
    }

    public FeeBearer getFeeBearer() {
        return feeBearer;
    }

    public String getIdempotencyKey() {
        return idempotencyKey;
    }
}
