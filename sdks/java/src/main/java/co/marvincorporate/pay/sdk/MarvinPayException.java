package co.marvincorporate.pay.sdk;

/**
 * Thrown for any Marvin Pay SDK failure: a non-2xx HTTP response, a transport
 * error, or an interruption while polling.
 *
 * <p>For HTTP failures {@link #getHttpStatus()} carries the status code and
 * {@link #getBody()} the raw response body (which may be a Spring error object
 * or a {@code PaymentResult}-shaped payload with a non-2xx {@code status} and a
 * {@code message}). For transport/interruption failures {@link #getHttpStatus()}
 * is {@code 0} and {@link #getBody()} is {@code null}.
 */
public class MarvinPayException extends RuntimeException {

    private static final long serialVersionUID = 1L;

    private final int httpStatus;
    private final String body;

    /** Transport / client-side failure (no HTTP status). */
    public MarvinPayException(String message) {
        super(message);
        this.httpStatus = 0;
        this.body = null;
    }

    /** Transport / client-side failure with an underlying cause. */
    public MarvinPayException(String message, Throwable cause) {
        super(message, cause);
        this.httpStatus = 0;
        this.body = null;
    }

    /** Non-2xx HTTP response. */
    public MarvinPayException(int httpStatus, String message, String body) {
        super(message);
        this.httpStatus = httpStatus;
        this.body = body;
    }

    /** HTTP status code of the failing response, or {@code 0} for a non-HTTP failure. */
    public int getHttpStatus() {
        return httpStatus;
    }

    /** Raw response body of the failing response, or {@code null}. */
    public String getBody() {
        return body;
    }
}
