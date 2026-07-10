package co.marvincorporate.pay.sdk.model;

/**
 * Fee direction, used by {@code GET /v1/payment/fees}.
 *
 * <ul>
 *   <li>{@link #COLLECT} — payer &rarr; merchant inflow.</li>
 *   <li>{@link #PAYOUT} — merchant &rarr; recipient outflow.</li>
 *   <li>{@link #TOPUP} — admin balance credit (settlement wire).</li>
 *   <li>{@link #WITHDRAWAL} — admin balance debit (settlement wire).</li>
 * </ul>
 *
 * Merchants integrating over the API only ever use {@link #COLLECT} and
 * {@link #PAYOUT}; {@link #TOPUP}/{@link #WITHDRAWAL} are admin/settlement
 * directions included for completeness.
 *
 * The enum constant names match the wire values exactly.
 */
public enum Direction {
    COLLECT,
    PAYOUT,
    TOPUP,
    WITHDRAWAL
}
