package co.marvincorporate.pay.sdk;

import co.marvincorporate.pay.sdk.model.Direction;
import co.marvincorporate.pay.sdk.model.FeeBearer;
import co.marvincorporate.pay.sdk.model.FeeEstimate;
import co.marvincorporate.pay.sdk.model.PaymentRequest;
import co.marvincorporate.pay.sdk.model.PaymentResult;
import co.marvincorporate.pay.sdk.model.TransactionStatusResponse;
import com.fasterxml.jackson.core.type.TypeReference;
import com.fasterxml.jackson.databind.DeserializationFeature;
import com.fasterxml.jackson.databind.ObjectMapper;

import java.io.IOException;
import java.net.URI;
import java.net.URLEncoder;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.Arrays;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

/**
 * Thin, self-contained client for the Marvin Pay payment gateway.
 *
 * <p>Built on the JDK {@link HttpClient} and Jackson only. Thread-safe and cheap to
 * reuse — create one instance per API key and share it.
 *
 * <h2>Authentication</h2>
 * <ul>
 *   <li>{@code X-API-KEY} is sent on the authenticated payment surface when an
 *       {@code apiKey} is configured (collect, payout, status, fees, payment-methods).</li>
 *   <li>{@code Authorization: Bearer} is sent when a {@code bearerToken} is configured
 *       (only needed for JWT/portal endpoints, which this thin client does not expose
 *       beyond passing the header).</li>
 *   <li>The hosted-pay helpers ({@code payInvoice}, {@code contributeCampaign},
 *       {@code payQr}, {@code getQrStatus}) are <b>public</b> and send no auth header.</li>
 * </ul>
 *
 * <h2>Idempotency</h2>
 * Every {@code collect}/{@code payout} sends {@code X-Idempotency-Key}. If you do not
 * pass one explicitly it defaults to the request's {@code idempotency_key}, and failing
 * that to its {@code transaction_id}.
 *
 * <h2>Errors &amp; retries</h2>
 * Non-2xx responses raise {@link MarvinPayException} (with HTTP status + body). GETs are
 * retried once on {@code 429}/{@code 5xx} (respecting {@code Retry-After} when present);
 * money-moving POSTs are never auto-retried.
 */
public class MarvinPayClient {

    private final MarvinPayConfig config;
    private final HttpClient http;
    private final ObjectMapper mapper;

    /** Convenience constructor: production base URL, API-key auth, 30s timeout. */
    public MarvinPayClient(String apiKey) {
        this(MarvinPayConfig.builder().apiKey(apiKey).build());
    }

    public MarvinPayClient(MarvinPayConfig config) {
        if (config == null) {
            throw new IllegalArgumentException("config must not be null");
        }
        this.config = config;
        this.http = HttpClient.newBuilder()
                .connectTimeout(config.getTimeout())
                .build();
        this.mapper = new ObjectMapper()
                .configure(DeserializationFeature.FAIL_ON_UNKNOWN_PROPERTIES, false);
    }

    // =====================================================================
    // Core payment surface (X-API-KEY)
    // =====================================================================

    /** {@code POST /v1/payment/collect} — collect from a customer (payer → merchant). */
    public PaymentResult collect(PaymentRequest req) {
        return collect(req, null);
    }

    /** As {@link #collect(PaymentRequest)} but with an explicit idempotency key. */
    public PaymentResult collect(PaymentRequest req, String idempotencyKey) {
        return postPayment("/v1/payment/collect", req, idempotencyKey);
    }

    /** {@code POST /v1/payment/payout} — pay out (merchant → recipient). */
    public PaymentResult payout(PaymentRequest req) {
        return payout(req, null);
    }

    /** As {@link #payout(PaymentRequest)} but with an explicit idempotency key. */
    public PaymentResult payout(PaymentRequest req, String idempotencyKey) {
        return postPayment("/v1/payment/payout", req, idempotencyKey);
    }

    /** {@code GET /v1/payment/status/{transactionId}} — the authoritative outcome check. */
    public TransactionStatusResponse getStatus(String transactionId) {
        requireNonBlank(transactionId, "transactionId");
        return get("/v1/payment/status/" + encodePathSegment(transactionId),
                true, TransactionStatusResponse.class);
    }

    /**
     * {@code GET /v1/payment/fees} — fee estimate / fee-bearer split.
     *
     * @param direction {@code COLLECT} or {@code PAYOUT} (also {@code TOPUP}/{@code WITHDRAWAL}).
     * @param feeBearer {@code MERCHANT}/{@code CUSTOMER}, or {@code null} to omit (server default MERCHANT).
     */
    public FeeEstimate getFees(String currency, long amount, String direction, String feeBearer) {
        requireNonBlank(currency, "currency");
        requireNonBlank(direction, "direction");
        StringBuilder path = new StringBuilder("/v1/payment/fees")
                .append("?currency=").append(encodeQuery(currency))
                .append("&amount=").append(amount)
                .append("&direction=").append(encodeQuery(direction));
        if (feeBearer != null && !feeBearer.isBlank()) {
            path.append("&fee_bearer=").append(encodeQuery(feeBearer));
        }
        return get(path.toString(), true, FeeEstimate.class);
    }

    /** Type-safe overload of {@link #getFees(String, long, String, String)}. */
    public FeeEstimate getFees(String currency, long amount, Direction direction, FeeBearer feeBearer) {
        return getFees(currency, amount,
                direction == null ? null : direction.name(),
                feeBearer == null ? null : feeBearer.name());
    }

    /**
     * {@code GET /v1/payment/payment-methods/{countryCode}} — provider-name strings valid
     * for the country (the values to pass as {@code payment_method}, e.g. {@code mtn_cm}).
     */
    public List<String> getPaymentMethods(String countryCode) {
        requireNonBlank(countryCode, "countryCode");
        String[] methods = get("/v1/payment/payment-methods/" + encodePathSegment(countryCode),
                true, String[].class);
        return methods == null ? List.of() : Arrays.asList(methods);
    }

    // =====================================================================
    // Waiting for completion (poll GET /v1/payment/status)
    // =====================================================================

    /**
     * Poll {@code getStatus} until the transaction is terminal or the timeout elapses,
     * using the SDK defaults: first poll after <b>5s</b>, exponential backoff capped at
     * <b>60s</b>, give up after <b>10 minutes</b>.
     *
     * @return the last status observed. If it timed out this is still {@code PENDING};
     *         check {@link TransactionStatusResponse#isTerminal()}.
     */
    public TransactionStatusResponse waitForCompletion(String transactionId) {
        return waitForCompletion(transactionId,
                Duration.ofSeconds(5), Duration.ofSeconds(60), Duration.ofMinutes(10));
    }

    /**
     * Poll {@code getStatus} until the transaction reaches a terminal state
     * ({@code SUCCESSFUL}/{@code FAILED}) or {@code timeout} elapses.
     *
     * <p>Sleeps {@code startDelay} before the first poll, then doubles the delay after
     * each still-pending poll, capped at {@code maxDelay}.
     *
     * @return the last status observed (still {@code PENDING} if it timed out).
     * @throws MarvinPayException if the thread is interrupted while waiting.
     */
    public TransactionStatusResponse waitForCompletion(String transactionId,
                                                       Duration startDelay,
                                                       Duration maxDelay,
                                                       Duration timeout) {
        requireNonBlank(transactionId, "transactionId");
        long deadlineNanos = System.nanoTime() + timeout.toNanos();
        Duration delay = startDelay;
        TransactionStatusResponse last = null;
        while (true) {
            sleep(delay);
            last = getStatus(transactionId);
            if (last.isTerminal()) {
                return last;
            }
            if (System.nanoTime() >= deadlineNanos) {
                return last; // timed out; still pending
            }
            long nextMillis = Math.min(delay.toMillis() * 2, maxDelay.toMillis());
            delay = Duration.ofMillis(Math.max(nextMillis, 1));
        }
    }

    // =====================================================================
    // Public "hosted pay" helpers (no API key sent; auth resolved server-side)
    // =====================================================================

    /**
     * {@code POST /v1/invoices/{reference}/pay}. Populate {@link PaymentRequest} with
     * {@code countryCode}, {@code currency}, {@code mobileNumber}, {@code paymentMethod},
     * {@code beneficiaryName} (required), and optionally {@code customerEmail}. The invoice
     * already carries the amount, so leave {@code amount} unset.
     */
    public PaymentResult payInvoice(String reference, PaymentRequest req) {
        requireNonBlank(reference, "reference");
        return post("/v1/invoices/" + encodePathSegment(reference) + "/pay",
                req, null, false, PaymentResult.class);
    }

    /**
     * {@code POST /v1/campaigns/{reference}/contribute}. Set {@code amount} (min 100) plus
     * the payer fields ({@code countryCode}, {@code currency}, {@code mobileNumber},
     * {@code paymentMethod}, {@code beneficiaryName}, optional {@code customerEmail}).
     */
    public PaymentResult contributeCampaign(String reference, PaymentRequest req) {
        requireNonBlank(reference, "reference");
        return post("/v1/campaigns/" + encodePathSegment(reference) + "/contribute",
                req, null, false, PaymentResult.class);
    }

    /**
     * {@code POST /v1/merchant/qrcode/pay/{qrReference}}. {@code amount} is ignored if the
     * QR has a fixed amount. The merchant API key is resolved server-side from the QR
     * reference — never send it.
     */
    public PaymentResult payQr(String qrReference, PaymentRequest req) {
        requireNonBlank(qrReference, "qrReference");
        return post("/v1/merchant/qrcode/pay/" + encodePathSegment(qrReference),
                req, null, false, PaymentResult.class);
    }

    /**
     * {@code GET /v1/merchant/qrcode/status/{transactionId}} — public poll used by the
     * QR/invoice/campaign pay pages. Returns a plain map with keys {@code transactionId},
     * {@code status}, {@code amount}, {@code currency}, {@code paymentMethod},
     * {@code mobileNumber}, {@code timestamp}. No merchant/fee data is exposed. Poll ~every 5s.
     */
    public Map<String, Object> getQrStatus(String transactionId) {
        requireNonBlank(transactionId, "transactionId");
        return getMap("/v1/merchant/qrcode/status/" + encodePathSegment(transactionId), false);
    }

    // =====================================================================
    // Internals
    // =====================================================================

    private PaymentResult postPayment(String path, PaymentRequest req, String idempotencyKey) {
        if (req == null) {
            throw new IllegalArgumentException("PaymentRequest must not be null");
        }
        String key = firstNonBlank(idempotencyKey, req.getIdempotencyKey(), req.getTransactionId());
        Map<String, String> headers = new HashMap<>();
        if (key != null) {
            headers.put("X-Idempotency-Key", key);
        }
        return post(path, req, headers, true, PaymentResult.class);
    }

    private <T> T post(String path, Object body, Map<String, String> extraHeaders,
                       boolean auth, Class<T> responseType) {
        HttpRequest.Builder b = requestBuilder(path, auth)
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofByteArray(writeJson(body)));
        if (extraHeaders != null) {
            extraHeaders.forEach(b::header);
        }
        HttpResponse<String> resp = send(b.build(), false); // POSTs are not auto-retried
        return handle(resp, responseType);
    }

    private <T> T get(String path, boolean auth, Class<T> responseType) {
        HttpRequest req = requestBuilder(path, auth).GET().build();
        HttpResponse<String> resp = send(req, true); // GETs retry once on 429/5xx
        return handle(resp, responseType);
    }

    private Map<String, Object> getMap(String path, boolean auth) {
        HttpRequest req = requestBuilder(path, auth).GET().build();
        HttpResponse<String> resp = send(req, true);
        int code = resp.statusCode();
        if (code < 200 || code >= 300) {
            throw new MarvinPayException(code, extractMessage(resp.body(), code), resp.body());
        }
        String body = resp.body();
        if (body == null || body.isBlank()) {
            return Map.of();
        }
        try {
            return mapper.readValue(body, new TypeReference<Map<String, Object>>() {});
        } catch (IOException e) {
            throw new MarvinPayException("Failed to parse response JSON: " + e.getMessage(), e);
        }
    }

    private HttpRequest.Builder requestBuilder(String path, boolean auth) {
        HttpRequest.Builder b = HttpRequest.newBuilder(URI.create(config.getBaseUrl() + path))
                .timeout(config.getTimeout())
                .header("Accept", "application/json");
        if (auth) {
            if (config.getApiKey() != null) {
                b.header("X-API-KEY", config.getApiKey());
            }
            if (config.getBearerToken() != null) {
                b.header("Authorization", "Bearer " + config.getBearerToken());
            }
        }
        return b;
    }

    private HttpResponse<String> send(HttpRequest request, boolean retryable) {
        try {
            HttpResponse<String> resp = http.send(request,
                    HttpResponse.BodyHandlers.ofString(StandardCharsets.UTF_8));
            if (retryable && isRetryable(resp.statusCode())) {
                sleep(retryDelay(resp));
                resp = http.send(request, HttpResponse.BodyHandlers.ofString(StandardCharsets.UTF_8));
            }
            return resp;
        } catch (IOException e) {
            throw new MarvinPayException("HTTP request failed: " + e.getMessage(), e);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            throw new MarvinPayException("HTTP request interrupted", e);
        }
    }

    private static boolean isRetryable(int status) {
        return status == 429 || status >= 500;
    }

    /** Honour {@code Retry-After} (seconds) when present, capped; else a small fixed backoff. */
    private static Duration retryDelay(HttpResponse<String> resp) {
        return resp.headers().firstValue("Retry-After")
                .map(String::trim)
                .flatMap(MarvinPayClient::parseLong)
                .map(secs -> Duration.ofSeconds(Math.min(Math.max(secs, 0), 30)))
                .orElse(Duration.ofSeconds(1));
    }

    private <T> T handle(HttpResponse<String> resp, Class<T> responseType) {
        int code = resp.statusCode();
        String body = resp.body();
        if (code < 200 || code >= 300) {
            throw new MarvinPayException(code, extractMessage(body, code), body);
        }
        if (responseType == null || responseType == Void.class) {
            return null;
        }
        return readJson(body, responseType);
    }

    /** Best-effort extraction of a human message from an error body. */
    private String extractMessage(String body, int code) {
        if (body != null && !body.isBlank()) {
            try {
                Map<String, Object> map = mapper.readValue(body, new TypeReference<Map<String, Object>>() {});
                Object msg = map.get("message");
                if (msg == null) {
                    msg = map.get("error");
                }
                if (msg != null && !msg.toString().isBlank()) {
                    return "HTTP " + code + ": " + msg;
                }
            } catch (IOException ignored) {
                // Non-JSON body; fall through.
            }
        }
        return "HTTP " + code;
    }

    private byte[] writeJson(Object body) {
        try {
            return mapper.writeValueAsBytes(body);
        } catch (IOException e) {
            throw new MarvinPayException("Failed to serialize request JSON: " + e.getMessage(), e);
        }
    }

    private <T> T readJson(String body, Class<T> type) {
        if (body == null || body.isBlank()) {
            throw new MarvinPayException("Expected a JSON body but the response was empty");
        }
        try {
            return mapper.readValue(body, type);
        } catch (IOException e) {
            throw new MarvinPayException("Failed to parse response JSON: " + e.getMessage(), e);
        }
    }

    private static void sleep(Duration d) {
        long millis = d == null ? 0 : Math.max(d.toMillis(), 0);
        if (millis == 0) {
            return;
        }
        try {
            Thread.sleep(millis);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            throw new MarvinPayException("Interrupted while waiting", e);
        }
    }

    private static String encodePathSegment(String value) {
        // URLEncoder targets query strings (space → '+'); fix that for path segments.
        return URLEncoder.encode(value, StandardCharsets.UTF_8).replace("+", "%20");
    }

    private static String encodeQuery(String value) {
        return URLEncoder.encode(value, StandardCharsets.UTF_8);
    }

    private static String firstNonBlank(String... values) {
        if (values != null) {
            for (String v : values) {
                if (v != null && !v.isBlank()) {
                    return v;
                }
            }
        }
        return null;
    }

    private static void requireNonBlank(String value, String name) {
        if (value == null || value.isBlank()) {
            throw new IllegalArgumentException(name + " must not be null or blank");
        }
    }

    private static java.util.Optional<Long> parseLong(String s) {
        try {
            return java.util.Optional.of(Long.parseLong(s));
        } catch (NumberFormatException e) {
            return java.util.Optional.empty();
        }
    }
}
