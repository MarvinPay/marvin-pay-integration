<?php

declare(strict_types=1);

namespace MarvinPay\Laravel;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Marvin Pay service for Laravel, implemented with the `Http` facade.
 *
 * Self-contained: it does NOT depend on the vanilla marvinpay/sdk package.
 * Request bodies use the contract's snake_case field names; amounts are whole
 * numbers. Non-2xx responses throw Laravel's {@see RequestException} (via
 * `$response->throw()`); GETs retry once on 429/5xx.
 *
 * @see ../../../CONTRACT.md the authoritative API contract
 */
class MarvinPay
{
    /** @var array<string,mixed> */
    private array $config;

    /** @var array<string,string> lowercased header name => value from the last response */
    private array $lastResponseHeaders = [];

    /**
     * @param array<string,mixed> $config the `marvinpay` config array
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Core payment endpoints (X-API-KEY)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Collect from a customer (payer -> merchant). `POST /v1/payment/collect`.
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
        return $this->get('/v1/payment/status/' . rawurlencode($transactionId));
    }

    /**
     * Fee estimate. `GET /v1/payment/fees`.
     *
     * @param array<string,mixed> $params currency, amount, direction, fee_bearer
     * @return array<string,mixed> FeeEstimateResponse
     */
    public function getFees(array $params): array
    {
        if (isset($params['amount']) && is_numeric($params['amount'])) {
            $params['amount'] = (int) $params['amount'];
        }
        return $this->get('/v1/payment/fees', $params);
    }

    /**
     * List provider names valid for a country.
     * `GET /v1/payment/payment-methods/{countryCode}`.
     *
     * @return array<int,string>
     */
    public function getPaymentMethods(string $countryCode): array
    {
        return $this->get('/v1/payment/payment-methods/' . rawurlencode($countryCode));
    }

    /**
     * Poll {@see getStatus()} until terminal. First poll after 5s, exponential
     * backoff capped at 60s, give up after 600s. Returns the last
     * TransactionStatusResponse.
     *
     * @param array<string,mixed> $opts initial_delay, max_delay, timeout, sleep(callable)
     * @return array<string,mixed>
     */
    public function waitForCompletion(string $transactionId, array $opts = []): array
    {
        $delay = (int) ($opts['initial_delay'] ?? 5);
        $cap = (int) ($opts['max_delay'] ?? 60);
        $timeout = (int) ($opts['timeout'] ?? 600);
        $sleep = $opts['sleep'] ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };

        $deadline = time() + $timeout;
        $last = null;

        while (true) {
            $remaining = $deadline - time();
            if ($remaining <= 0) {
                break;
            }
            $sleep(min($delay, $remaining));

            $last = $this->getStatus($transactionId);
            $status = strtoupper((string) ($last['transaction_status'] ?? ''));
            if ($status === 'SUCCESSFUL' || $status === 'FAILED') {
                return $last;
            }

            $delay = min($delay * 2, $cap);
        }

        return $last ?? $this->getStatus($transactionId);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Public "hosted pay" flows
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Pay a public invoice. `POST /v1/invoices/{reference}/pay`.
     *
     * @param array<string,mixed> $payInvoiceRequest PayInvoiceRequest
     * @return array<string,mixed> PaymentResult
     */
    public function payInvoice(string $reference, array $payInvoiceRequest): array
    {
        return $this->post('/v1/invoices/' . rawurlencode($reference) . '/pay', $this->normalizeAmount($payInvoiceRequest));
    }

    /**
     * Contribute to a public campaign. `POST /v1/campaigns/{reference}/contribute`.
     *
     * @param array<string,mixed> $contributeRequest ContributeRequest
     * @return array<string,mixed> PaymentResult
     */
    public function contributeCampaign(string $reference, array $contributeRequest): array
    {
        return $this->post('/v1/campaigns/' . rawurlencode($reference) . '/contribute', $this->normalizeAmount($contributeRequest));
    }

    /**
     * Pay a QR code. `POST /v1/merchant/qrcode/pay/{qrReference}`.
     *
     * @param array<string,mixed> $qrPaymentRequest QRPaymentRequest
     * @return array<string,mixed> PaymentResult
     */
    public function payQr(string $qrReference, array $qrPaymentRequest): array
    {
        return $this->post('/v1/merchant/qrcode/pay/' . rawurlencode($qrReference), $this->normalizeAmount($qrPaymentRequest));
    }

    /**
     * Public poll for a hosted-pay transaction.
     * `GET /v1/merchant/qrcode/status/{transactionId}`.
     *
     * @return array<string,mixed>
     */
    public function getQrStatus(string $transactionId): array
    {
        return $this->get('/v1/merchant/qrcode/status/' . rawurlencode($transactionId));
    }

    /**
     * Headers from the most recent response (lowercased keys). Use to read
     * `x-idempotency-replay` / `x-idempotency-key-auto`.
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

    private const DEFAULT_BASE_URL = 'https://api.marvincorporate.co/api';

    private function client(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) ($this->config['base_url'] ?? self::DEFAULT_BASE_URL), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) ($this->config['timeout'] ?? 30));

        if (!empty($this->config['api_key'])) {
            $request = $request->withHeaders(['X-API-KEY' => $this->config['api_key']]);
        }
        if (!empty($this->config['bearer_token'])) {
            $request = $request->withToken($this->config['bearer_token']);
        }

        return $request;
    }

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

        return $this->post($path, $body, $headers);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $attempts = 0;
        while (true) {
            $attempts++;
            $response = $this->client()->get($path, $query);
            $this->lastResponseHeaders = $this->flattenHeaders($response->headers());

            if ($response->successful()) {
                return (array) $response->json();
            }

            $status = $response->status();
            if (($status === 429 || $status >= 500) && $attempts < 2) {
                $retryAfter = (int) $response->header('Retry-After');
                usleep(($retryAfter > 0 ? $retryAfter : 1) * 1_000_000);
                continue;
            }

            // Throws Illuminate\Http\Client\RequestException on 4xx/5xx.
            $response->throw();
            return (array) $response->json();
        }
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @return array<mixed>
     */
    private function post(string $path, array $body, array $headers = []): array
    {
        $request = $this->client();
        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        $response = $request->post($path, $body);
        $this->lastResponseHeaders = $this->flattenHeaders($response->headers());

        // Money-moving POSTs are NOT retried (idempotency protects, but we only
        // retry idempotent GETs per the contract). Throw on non-2xx.
        $response->throw();

        return (array) $response->json();
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function normalizeAmount(array $body): array
    {
        if (isset($body['amount']) && is_numeric($body['amount'])) {
            $body['amount'] = (int) $body['amount'];
        }
        return $body;
    }

    /**
     * @param array<string,array<int,string>> $headers
     * @return array<string,string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $values) {
            $flat[strtolower((string) $name)] = is_array($values) ? implode(', ', $values) : (string) $values;
        }
        return $flat;
    }
}
