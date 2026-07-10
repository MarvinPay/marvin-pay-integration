package co.marvincorporate.pay.sdk;

import java.time.Duration;

/**
 * Immutable configuration for {@link MarvinPayClient}.
 *
 * <p>Defaults:
 * <ul>
 *   <li>{@code baseUrl} = {@value #DEFAULT_BASE_URL} (production, note the trailing {@code /api}).</li>
 *   <li>{@code timeout} = 30s (used for both connect and per-request read timeout).</li>
 * </ul>
 *
 * <p>Build with {@link #builder()}. At minimum set the {@code apiKey} for the
 * {@code X-API-KEY} payment surface.
 */
public final class MarvinPayConfig {

    /** Production base URL. Includes the {@code /api} servlet context path. */
    public static final String DEFAULT_BASE_URL = "https://api.marvincorporate.co/api";

    /** Local/dev base URL, for convenience. */
    public static final String LOCAL_BASE_URL = "http://localhost:9090/api";

    private static final Duration DEFAULT_TIMEOUT = Duration.ofSeconds(30);

    private final String apiKey;
    private final String baseUrl;
    private final Duration timeout;

    private MarvinPayConfig(Builder b) {
        this.apiKey = b.apiKey;
        this.baseUrl = stripTrailingSlash(b.baseUrl != null ? b.baseUrl : DEFAULT_BASE_URL);
        this.timeout = b.timeout != null ? b.timeout : DEFAULT_TIMEOUT;
    }

    public String getApiKey() {
        return apiKey;
    }

    /** Base URL with any trailing slash removed (paths are appended as {@code /v1/...}). */
    public String getBaseUrl() {
        return baseUrl;
    }

    public Duration getTimeout() {
        return timeout;
    }

    public static Builder builder() {
        return new Builder();
    }

    private static String stripTrailingSlash(String url) {
        if (url == null) {
            return null;
        }
        String trimmed = url.trim();
        while (trimmed.endsWith("/")) {
            trimmed = trimmed.substring(0, trimmed.length() - 1);
        }
        return trimmed;
    }

    /** Builder for {@link MarvinPayConfig}. */
    public static final class Builder {
        private String apiKey;
        private String baseUrl;
        private Duration timeout;

        public Builder apiKey(String apiKey) {
            this.apiKey = apiKey;
            return this;
        }

        public Builder baseUrl(String baseUrl) {
            this.baseUrl = baseUrl;
            return this;
        }

        public Builder timeout(Duration timeout) {
            this.timeout = timeout;
            return this;
        }

        public MarvinPayConfig build() {
            return new MarvinPayConfig(this);
        }
    }
}
