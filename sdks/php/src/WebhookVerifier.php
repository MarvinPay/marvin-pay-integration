<?php

declare(strict_types=1);

namespace MarvinPay;

/**
 * Verifies the `X-Webhook-Signature` header on inbound Marvin Pay webhooks.
 *
 * The scheme (per the API contract, §8) is HMAC-SHA256 computed over the RAW
 * request body, hex-encoded, and sent as `sha256=<hex>`. Configure a webhook
 * secret on your account to enable signed deliveries.
 *
 * ────────────────────────────────────────────────────────────────────────────
 *  Do NOT use signature verification as your only trust anchor. Because
 *  deliveries are at-least-once, on EVERY webhook you must confirm the outcome
 *  out-of-band via {@see MarvinPayClient::getStatus()} before acting (fulfilling
 *  orders, crediting users, etc.), and dedupe on transactionId + status.
 * ────────────────────────────────────────────────────────────────────────────
 */
class WebhookVerifier
{
    /**
     * Constant-time HMAC-SHA256 verification of a raw webhook body.
     *
     * @param string      $rawBody         the exact bytes of the request body (do not re-encode)
     * @param string|null $signatureHeader the `X-Webhook-Signature` header value, e.g. `sha256=abcd…`
     * @param string      $secret          your account's webhook secret
     *
     * @return bool true only when the signature is present, the secret is set,
     *              and the HMAC matches. Returns false otherwise.
     */
    public static function verify(string $rawBody, ?string $signatureHeader, string $secret): bool
    {
        if ($signatureHeader === null || $signatureHeader === '' || $secret === '') {
            // No signature or no secret => cannot verify.
            return false;
        }

        $provided = $signatureHeader;
        if (\str_starts_with($provided, 'sha256=')) {
            $provided = \substr($provided, 7);
        }
        $provided = \trim($provided);

        $expected = \hash_hmac('sha256', $rawBody, $secret);

        // Constant-time comparison to avoid timing side-channels.
        return \hash_equals($expected, $provided);
    }
}
