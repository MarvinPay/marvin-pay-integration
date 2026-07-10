package examples;

// --- Marvin Pay SDK -------------------------------------------------------
import co.marvincorporate.pay.sdk.MarvinPayClient;
import co.marvincorporate.pay.sdk.WebhookVerifier;
import co.marvincorporate.pay.sdk.model.TransactionStatusResponse;

// --- Spring Web (needs Spring on the classpath — see note below) ----------
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RestController;

import java.nio.charset.StandardCharsets;
import java.util.Set;
import java.util.concurrent.ConcurrentHashMap;

/**
 * Example Spring {@code @RestController} that receives Marvin Pay webhooks safely.
 *
 * <p><b>This is an EXAMPLE snippet.</b> It requires Spring Web on the classpath
 * (e.g. {@code spring-boot-starter-web}) to compile and run — it does not compile
 * standalone with only the SDK. In this examples module Spring is declared
 * {@code provided} purely so this file compiles; a real service would have Spring on
 * its normal (compile) classpath and this class would be a component in your app.
 *
 * <h2>The safe webhook recipe</h2>
 * <ol>
 *   <li>Read the <b>raw</b> body (as {@code byte[]}) so any signature check sees exactly
 *       what was sent.</li>
 *   <li>Verify {@code X-Webhook-Signature} with {@link WebhookVerifier}. <b>Note:</b>
 *       webhooks are effectively UNSIGNED today (no {@code webhookSecret} is set, so the
 *       header is not sent). The check is inert now and becomes effective automatically
 *       once backend signing lands — keep it wired in.</li>
 *   <li><b>Always confirm out-of-band</b> via {@code getStatus(transactionId)} before
 *       acting. This is the real trust anchor today. Never fulfil on the payload alone.</li>
 *   <li><b>Dedupe</b> on {@code transactionId + status} — deliveries retry and can repeat.</li>
 *   <li>Return any <b>2xx</b> quickly; do the heavy lifting async. Non-2xx triggers retries.</li>
 * </ol>
 */
@RestController
public class SpringWebhookController {

    private final MarvinPayClient client;

    /** Idempotency guard: remember (transactionId|status) we have already handled. */
    private final Set<String> processed = ConcurrentHashMap.newKeySet();

    public SpringWebhookController(MarvinPayClient client) {
        this.client = client;
    }

    /**
     * Marvin Pay POSTs webhook events here. We take the body as {@code byte[]} so we can
     * both verify the (future) signature over raw bytes and parse it ourselves.
     */
    @PostMapping(value = "/webhooks/marvin", consumes = MediaType.ALL_VALUE)
    public ResponseEntity<String> handle(
            @RequestBody(required = false) byte[] rawBody,
            @RequestHeader(value = "X-Webhook-Signature", required = false) String signature) {

        byte[] body = rawBody == null ? new byte[0] : rawBody;

        // 1) Signature check (inert today — the header is not sent yet). Do NOT rely on it.
        //    Configure your account's webhookSecret here once backend signing is enabled.
        String webhookSecret = System.getenv("MARVIN_WEBHOOK_SECRET"); // may be null today
        boolean signatureValid = webhookSecret != null
                && WebhookVerifier.verify(body, signature, webhookSecret);
        // We deliberately do NOT reject on !signatureValid right now, because no signature
        // is sent. We rely on step 3 (status confirmation) instead. Once signing is live and
        // you have a secret, you can start rejecting unsigned/invalid deliveries.

        // 2) Parse just enough to learn which transaction this is about.
        String payload = new String(body, StandardCharsets.UTF_8);
        String transactionId = crudeJsonString(payload, "transactionId");
        String eventStatus = crudeJsonString(payload, "status"); // SUCCESS | FAILED | PENDING | CANCEL
        if (transactionId == null) {
            // Nothing actionable; ack so Marvin Pay does not retry a malformed delivery.
            return ResponseEntity.ok("ignored: no transactionId");
        }

        // 3) Dedupe on transactionId + status (deliveries can repeat).
        String dedupeKey = transactionId + "|" + eventStatus;
        if (!processed.add(dedupeKey)) {
            return ResponseEntity.ok("duplicate ignored");
        }

        // 4) THE TRUST ANCHOR: confirm out-of-band before acting on anything.
        TransactionStatusResponse confirmed = client.getStatus(transactionId);
        if (confirmed.isSuccessful()) {
            // fulfilOrder(transactionId);   // credit the user, ship the goods, etc.
            System.out.println("[webhook] confirmed SUCCESSFUL for " + transactionId
                    + " (signatureValid=" + signatureValid + ")");
        } else if (confirmed.isFailed()) {
            // markFailed(transactionId);
            System.out.println("[webhook] confirmed FAILED for " + transactionId);
        } else {
            // Still pending per the authoritative check — do not act yet.
            // Let it retry / poll later, so drop it from the dedupe set.
            processed.remove(dedupeKey);
            System.out.println("[webhook] still PENDING for " + transactionId + " — not acting");
        }

        // 5) Acknowledge. Any 2xx = success; non-2xx makes Marvin Pay retry.
        return ResponseEntity.ok("received");
    }

    /**
     * Minimal string-field extractor so this example has no JSON dependency of its own.
     * In real code, parse with Jackson (already a transitive dep of the SDK) or bind to a DTO.
     */
    private static String crudeJsonString(String json, String field) {
        String needle = "\"" + field + "\"";
        int k = json.indexOf(needle);
        if (k < 0) {
            return null;
        }
        int colon = json.indexOf(':', k + needle.length());
        if (colon < 0) {
            return null;
        }
        int i = colon + 1;
        while (i < json.length() && Character.isWhitespace(json.charAt(i))) {
            i++;
        }
        if (i >= json.length() || json.charAt(i) != '"') {
            return null; // not a string value
        }
        int start = i + 1;
        int end = json.indexOf('"', start);
        return end < 0 ? null : json.substring(start, end);
    }
}
