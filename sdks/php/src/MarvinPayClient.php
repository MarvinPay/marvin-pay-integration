<?php

declare(strict_types=1);

namespace MarvinPay;

/**
 * Zero-dependency HTTP client for the Marvin Pay payment gateway.
 *
 * Transport is plain cURL + JSON. Authentication is the merchant `X-API-KEY`
 * header for `/v1/payment/**`; an optional portal JWT (`Authorization: Bearer`)
 * can be supplied for the JWT-gated surfaces.
 *
 * All field names on request bodies match the API contract's snake_case exactly.
 * Amounts are whole numbers (XAF/XOF have no minor units); the client serializes
 * the `amount` field without a fractional part.
 *
 * @see ../../CONTRACT.md  the authoritative API contract
 */
class MarvinPayClient
{
    public const DEFAULT_BASE_URL = 'https://api.marvincorporate.co/api';

    private string $apiKey;
    private string $baseUrl;
    private ?string $bearerToken;
    private int $timeout;

    /** @var array<string,string> lowercased header name => value from the last response */
    private array $lastResponseHeaders = [];

    /**
     * @param string               $apiKey  merchant API key sent as `X-API-KEY` (may be '' for public hosted-pay only)
     * @param array<string,mixed>  $options {
     *     base_url?:     string  default https://api.marvincorporate.co/api
     *     bearer_token?: string  optional portal JWT for JWT-gated endpoints
     *     timeout?:      int     request timeout in seconds (default 30)
     * }
     */
    public function __construct(string $apiKey, array $options = [])
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = \rtrim((string) ($options['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        $this->bearerToken = isset($options['bearer_token']) && $options['bearer_token'] !== ''
            ? (string) $options['bearer_token']
            : null;
        $this->timeout = (int) ($options['timeout'] ?? 30);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Core payment endpoints (X-API-KEY)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Collect from a customer (payer -> merchant). `POST /v1/payment/collect`.
     *
     * Always sends `X-Idempotency-Key`; when $idempotencyKey is null it defaults
     * to the body's `transaction_id`.
     *
     * @param array<string,mixed> $paymentRequest snake_case PaymentRequest
     * @return array<string,mixed> PaymentResult
     */
    public function collect(array $paymentRequest, ?string $idempotencyKey = null): array
    {
        return $this->moneyMove('/v1/payment/collect', $paymentRequest, $idempotencyKey);
    }

    /**
     * Pay out to a recipient (merchant -> recipient). `POST /v1/payment/payout`.
     *
     * @param array<string,mixed> $paymentRequest snake_case PaymentRequest
     * @return array<string,mixed> PaymentResult
     */
    public function payout(array $paymentRequest, ?string $idempotencyKey = null): array
    {
        return $this->moneyMove('/v1/payment/payout', $paymentRequest, $idempotencyKey);
    }

    /**
     * Authoritative status check. `GET /v1/payment/status/{transactionId}`.
     *
     * @return array<string,mixed> TransactionStatusResponse
     */
    public function getStatus(string $transactionId): array
    {
        return $this->request('GET', '/v1/payment/status/' . \rawurlencode($transactionId));
    }

    /**
     * Fee estimate. `GET /v1/payment/fees`.
     *
     * @param array<string,mixed> $params query params: currency, amount, direction, fee_bearer
     * @return array<string,mixed> FeeEstimateResponse
     */
    public function getFees(array $params): array
    {
        if (isset($params['amount']) && \is_numeric($params['amount'])) {
            $params['amount'] = (int) $params['amount'];
        }
        return $this->request('GET', '/v1/payment/fees', ['query' => $params]);
    }

    /**
     * List provider names valid for a country.
     * `GET /v1/payment/payment-methods/{countryCode}`.
     *
     * @return array<int,string> provider-name strings (the values you pass as payment_method)
     */
    public function getPaymentMethods(string $countryCode): array
    {
        return $this->request('GET', '/v1/payment/payment-methods/' . \rawurlencode($countryCode));
    }

    /**
     * Poll {@see getStatus()} until the transaction reaches a terminal state.
     *
     * Schedule (per the contract): first poll after 5s, then exponential backoff
     * capped at 60s, giving up after 600s (10 min). Returns the last
     * TransactionStatusResponse — inspect `transaction_status`
     * (`SUCCESSFUL` / `FAILED` / still `PENDING` on timeout).
     *
     * @param array<string,mixed> $opts {
     *     initial_delay?: int   first-poll delay in seconds (default 5)
     *     max_delay?:     int   backoff cap in seconds (default 60)
     *     timeout?:       int   overall budget in seconds (default 600)
     *     sleep?:         callable(int):void  injectable sleeper (default \sleep) for testing
     * }
     * @return array<string,mixed> the final TransactionStatusResponse
     */
    public function waitForCompletion(string $transactionId, array $opts = []): array
    {
        $delay = (int) ($opts['initial_delay'] ?? 5);
        $cap = (int) ($opts['max_delay'] ?? 60);
        $timeout = (int) ($opts['timeout'] ?? 600);
        /** @var callable(int):void $sleep */
        $sleep = $opts['sleep'] ?? static function (int $seconds): void {
            if ($seconds > 0) {
                \sleep($seconds);
            }
        };

        $deadline = \time() + $timeout;
        $last = null;

        while (true) {
            $remaining = $deadline - \time();
            if ($remaining <= 0) {
                break;
            }
            $sleep(\min($delay, $remaining));

            $last = $this->getStatus($transactionId);
            $status = \strtoupper((string) ($last['transaction_status'] ?? ''));
            if ($status === 'SUCCESSFUL' || $status === 'FAILED') {
                return $last;
            }

            $delay = \min($delay * 2, $cap);
        }

        // Timed out still pending — return the last observation (or fetch once).
        return $last ?? $this->getStatus($transactionId);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Public "hosted pay" flows (no API key required by the server)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Pay a public invoice. `POST /v1/invoices/{reference}/pay`.
     *
     * @param array<string,mixed> $payInvoiceRequest PayInvoiceRequest:
     *        country_code, currency, mobile_number, payment_method,
     *        beneficiary_name (required), customer_email (optional)
     * @return array<string,mixed> PaymentResult
     */
    public function payInvoice(string $reference, array $payInvoiceRequest): array
    {
        return $this->request('POST', '/v1/invoices/' . \rawurlencode($reference) . '/pay', [
            'json' => $this->normalizeAmount($payInvoiceRequest),
        ]);
    }

    /**
     * Contribute to a public campaign. `POST /v1/campaigns/{reference}/contribute`.
     *
     * @param array<string,mixed> $contributeRequest ContributeRequest:
     *        country_code, currency, amount (min 100), mobile_number,
     *        payment_method, beneficiary_name, customer_email
     * @return array<string,mixed> PaymentResult
     */
    public function contributeCampaign(string $reference, array $contributeRequest): array
    {
        return $this->request('POST', '/v1/campaigns/' . \rawurlencode($reference) . '/contribute', [
            'json' => $this->normalizeAmount($contributeRequest),
        ]);
    }

    /**
     * Pay a QR code. `POST /v1/merchant/qrcode/pay/{qrReference}`.
     * The merchant API key is resolved server-side from the QR reference.
     *
     * @param array<string,mixed> $qrPaymentRequest QRPaymentRequest:
     *        country_code, currency, amount (ignored if the QR has a fixedAmount),
     *        mobile_number, payment_method, beneficiary_name (required), customer_email
     * @return array<string,mixed> PaymentResult
     */
    public function payQr(string $qrReference, array $qrPaymentRequest): array
    {
        return $this->request('POST', '/v1/merchant/qrcode/pay/' . \rawurlencode($qrReference), [
            'json' => $this->normalizeAmount($qrPaymentRequest),
        ]);
    }

    /**
     * Public poll for a hosted-pay (QR/invoice/campaign) transaction.
     * `GET /v1/merchant/qrcode/status/{transactionId}`. Poll ~every 5s.
     *
     * @return array<string,mixed> plain map: transactionId, status, amount,
     *         currency, paymentMethod, mobileNumber, timestamp
     */
    public function getQrStatus(string $transactionId): array
    {
        return $this->request('GET', '/v1/merchant/qrcode/status/' . \rawurlencode($transactionId));
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Response-header access (idempotency replay surfacing)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Headers from the most recent response, keyed by lowercased header name.
     * Use to read `x-idempotency-replay` / `x-idempotency-key-auto` after a
     * collect/payout.
     *
     * @return array<string,string>
     */
    public function getLastResponseHeaders(): array
    {
        return $this->lastResponseHeaders;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function moneyMove(string $path, array $body, ?string $idempotencyKey): array
    {
        $body = $this->normalizeAmount($body);

        // Always send X-Idempotency-Key; default to transaction_id.
        $key = $idempotencyKey ?? ($body['transaction_id'] ?? null);
        $headers = [];
        if ($key !== null && $key !== '') {
            $headers['X-Idempotency-Key'] = (string) $key;
        }

        return $this->request('POST', $path, ['json' => $body, 'headers' => $headers]);
    }

    /**
     * Ensure `amount` serializes as a whole number (no fractional part).
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function normalizeAmount(array $body): array
    {
        if (isset($body['amount']) && \is_numeric($body['amount'])) {
            $body['amount'] = (int) $body['amount'];
        }
        return $body;
    }

    /**
     * Perform an HTTP request with cURL. Retries once on 429/5xx for GETs only.
     *
     * @param array<string,mixed> $opts { json?: array, query?: array, headers?: array<string,string> }
     * @return array<mixed> decoded JSON body ([] for an empty/non-array 2xx body)
     */
    private function request(string $method, string $path, array $opts = []): array
    {
        $method = \strtoupper($method);
        $isGet = $method === 'GET';
        $maxAttempts = $isGet ? 2 : 1; // retry once on GET

        $attempt = 0;

        while (true) {
            $attempt++;
            [$status, $headers, $raw, $curlError] = $this->doRequest($method, $path, $opts);
            $this->lastResponseHeaders = $headers;

            if ($curlError !== null) {
                if ($isGet && $attempt < $maxAttempts) {
                    $this->backoff(1, 0);
                    continue;
                }
                throw new MarvinPayException('Marvin Pay request failed: ' . $curlError);
            }

            $retryable = $status === 429 || $status >= 500;
            if ($retryable && $isGet && $attempt < $maxAttempts) {
                $retryAfter = isset($headers['retry-after']) ? (int) $headers['retry-after'] : 0;
                $this->backoff($attempt, $retryAfter);
                continue;
            }

            $decoded = $this->decode($raw);

            if ($status < 200 || $status >= 300) {
                $message = $this->extractMessage($decoded, $raw, $status);
                throw new MarvinPayException($message, $status, $decoded ?? ($raw !== '' ? $raw : null));
            }

            // These endpoints return JSON objects/arrays; coerce an empty or
            // non-array 2xx body to [] so typed callers never see null.
            return \is_array($decoded) ? $decoded : [];
        }
    }

    /**
     * Single cURL round-trip.
     *
     * @param array<string,mixed> $opts
     * @return array{0:int,1:array<string,string>,2:string,3:?string} [status, headers, rawBody, curlError]
     */
    private function doRequest(string $method, string $path, array $opts): array
    {
        $url = $this->baseUrl . $path;
        if (!empty($opts['query'])) {
            $qs = \http_build_query($opts['query']);
            if ($qs !== '') {
                $url .= (\str_contains($url, '?') ? '&' : '?') . $qs;
            }
        }

        $headers = ['Accept: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'X-API-KEY: ' . $this->apiKey;
        }
        if ($this->bearerToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->bearerToken;
        }
        foreach (($opts['headers'] ?? []) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $bodyJson = null;
        if (\array_key_exists('json', $opts)) {
            $bodyJson = \json_encode($opts['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        $ch = \curl_init();
        $responseHeaders = [];

        \curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => \min($this->timeout, 15),
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$responseHeaders): int {
                $len = \strlen($header);
                $parts = \explode(':', $header, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[\strtolower(\trim($parts[0]))] = \trim($parts[1]);
                }
                return $len;
            },
        ]);

        if ($bodyJson !== null) {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
        }

        $raw = \curl_exec($ch);
        $errno = \curl_errno($ch);
        $error = $errno !== 0 ? \curl_error($ch) : null;
        $status = (int) \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        \curl_close($ch);

        return [$status, $responseHeaders, \is_string($raw) ? $raw : '', $error];
    }

    /**
     * @return mixed decoded array/scalar, or null for empty/undecodable bodies
     */
    private function decode(string $raw)
    {
        if ($raw === '') {
            return null;
        }
        $decoded = \json_decode($raw, true);
        return \json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * @param mixed $decoded
     */
    private function extractMessage($decoded, string $raw, int $status): string
    {
        if (\is_array($decoded)) {
            if (isset($decoded['message']) && \is_string($decoded['message']) && $decoded['message'] !== '') {
                return $decoded['message'];
            }
            if (isset($decoded['error']) && \is_string($decoded['error']) && $decoded['error'] !== '') {
                return $decoded['error'];
            }
        }
        if ($raw !== '') {
            return 'HTTP ' . $status . ': ' . $raw;
        }
        return 'HTTP ' . $status;
    }

    private function backoff(int $attempt, int $retryAfterSeconds): void
    {
        $seconds = $retryAfterSeconds > 0 ? $retryAfterSeconds : \min(2 ** $attempt, 5);
        \sleep($seconds);
    }
}
