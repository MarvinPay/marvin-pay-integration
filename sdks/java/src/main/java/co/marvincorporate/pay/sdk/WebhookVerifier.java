package co.marvincorporate.pay.sdk;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;

/**
 * Verifies the {@code X-Webhook-Signature} header on inbound Marvin Pay webhooks.
 *
 * <p>The intended scheme is <b>HMAC-SHA256 over the raw request body</b>, hex-encoded,
 * compared (constant-time) against the header value with its {@code sha256=} prefix
 * stripped.
 *
 * <h2>⚠️ Webhooks are effectively UNSIGNED today — read this</h2>
 * As of this writing the backend does <b>not</b> populate {@code webhookSecret}, so
 * {@code X-Webhook-Signature} is <b>not sent</b>, and the exact HMAC base string is
 * not yet finalized. This verifier implements the <i>intended</i> scheme and is
 * inert until signing is enabled server-side — it is safe to wire in now and will
 * start doing real work automatically once the backend begins signing.
 *
 * <p><b>Regardless of signature status, always confirm out-of-band</b> via
 * {@code GET /v1/payment/status/{transactionId}} before acting on a webhook
 * (fulfilling an order, crediting a user, etc.). Never treat the webhook payload
 * — or this signature check — as your sole trust anchor today.
 */
public final class WebhookVerifier {

    private static final String HMAC_SHA256 = "HmacSHA256";
    private static final char[] HEX = "0123456789abcdef".toCharArray();

    private WebhookVerifier() {
    }

    /**
     * Verify a webhook signature.
     *
     * @param rawBody         the <b>raw</b> request body bytes as received, decoded as UTF-8.
     *                        Verify against the raw bytes you received — do not re-serialize
     *                        a parsed object first, or the HMAC will not match.
     * @param signatureHeader the {@code X-Webhook-Signature} header value, e.g.
     *                        {@code sha256=abc123...}. The {@code sha256=} prefix is optional.
     * @param secret          your account's {@code webhookSecret}.
     * @return {@code true} only if the computed HMAC matches. Returns {@code false} for any
     *         {@code null} argument (including a missing header — which is the current
     *         reality, since the backend does not sign yet).
     */
    public static boolean verify(String rawBody, String signatureHeader, String secret) {
        if (rawBody == null) {
            return false;
        }
        return verify(rawBody.getBytes(StandardCharsets.UTF_8), signatureHeader, secret);
    }

    /**
     * Byte-array overload of {@link #verify(String, String, String)} — convenient when your
     * framework hands you the raw body as {@code byte[]} (recommended, to avoid any charset
     * round-tripping).
     */
    public static boolean verify(byte[] rawBody, String signatureHeader, String secret) {
        if (rawBody == null || signatureHeader == null || secret == null) {
            return false;
        }

        String provided = signatureHeader.trim();
        if (provided.regionMatches(true, 0, "sha256=", 0, "sha256=".length())) {
            provided = provided.substring("sha256=".length());
        }
        provided = provided.trim().toLowerCase();

        String computed = hmacSha256Hex(rawBody, secret.getBytes(StandardCharsets.UTF_8));

        // Constant-time comparison.
        return MessageDigest.isEqual(
                computed.getBytes(StandardCharsets.UTF_8),
                provided.getBytes(StandardCharsets.UTF_8));
    }

    private static String hmacSha256Hex(byte[] data, byte[] key) {
        try {
            Mac mac = Mac.getInstance(HMAC_SHA256);
            mac.init(new SecretKeySpec(key, HMAC_SHA256));
            byte[] raw = mac.doFinal(data);
            return toHex(raw);
        } catch (Exception e) {
            // HmacSHA256 is guaranteed present on every JVM; an empty key can throw.
            throw new MarvinPayException("Failed to compute webhook HMAC: " + e.getMessage(), e);
        }
    }

    private static String toHex(byte[] bytes) {
        char[] out = new char[bytes.length * 2];
        for (int i = 0; i < bytes.length; i++) {
            int v = bytes[i] & 0xFF;
            out[i * 2] = HEX[v >>> 4];
            out[i * 2 + 1] = HEX[v & 0x0F];
        }
        return new String(out);
    }
}
