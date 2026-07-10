package co.marvincorporate.pay.sdk.model;

/**
 * Who absorbs the transaction fee.
 *
 * <p>Sent as the {@code fee_bearer} JSON field on a {@link PaymentRequest}.
 * Omitting it (leaving it {@code null}) is treated by the server as {@link #MERCHANT}.
 *
 * <ul>
 *   <li>{@link #MERCHANT} (default) — the merchant absorbs the fee.</li>
 *   <li>{@link #CUSTOMER} — grosses up a collect / nets down a payout so the
 *       merchant receives (or pays) exactly {@code amount}.</li>
 * </ul>
 *
 * The enum constant names match the wire values exactly, so Jackson serializes
 * them verbatim ({@code "MERCHANT"} / {@code "CUSTOMER"}).
 */
public enum FeeBearer {
    MERCHANT,
    CUSTOMER
}
