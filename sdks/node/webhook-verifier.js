'use strict';

/**
 * Marvin Pay — webhook helpers (zero runtime dependencies, Node 18+).
 *
 * ============================================================================
 *  WEBHOOK VERIFICATION + THE MANDATORY CONFIRM STEP
 * ============================================================================
 *
 * `verifyWebhookSignature` checks the `X-Webhook-Signature` header (HMAC-SHA256
 * over the raw request body, hex-encoded, sent as `sha256=<hex>`). Configure a
 * webhook secret on your account to enable signed deliveries.
 *
 * Because deliveries are at-least-once, the signature is never your only trust
 * anchor. Regardless of the signature, on EVERY webhook:
 *
 *   1. Treat every webhook as a HINT, not proof.
 *   2. On each webhook, CONFIRM OUT-OF-BAND via
 *      `GET /v1/payment/status/{transactionId}` (client.getStatus) BEFORE you
 *      act on it (fulfilling orders, crediting users, etc.).
 *   3. Dedupe on `transactionId` + `status` — the same event may be delivered
 *      more than once (CONTRACT §8.2).
 *   4. Restrict your webhook endpoint (hard-to-guess path, IP allowlist).
 *
 * This verifier computes HMAC-SHA256 over the raw request body and compares it
 * (constant-time) against `X-Webhook-Signature` minus the `sha256=` prefix. It
 * returns `false` when the secret or signature is missing — so keep the
 * status-confirmation step regardless of what it returns.
 * ============================================================================
 */

const crypto = require('node:crypto');

/**
 * Verify an HMAC-SHA256 webhook signature.
 *
 * Returns `false` when either the secret or the signature header is missing, so
 * callers must still confirm via `getStatus` (deliveries are at-least-once).
 *
 * @param {string|Buffer} rawBody         The EXACT raw request body bytes/string.
 *   You must capture this before any JSON parsing/re-serialization, otherwise the
 *   HMAC will not match (whitespace/key-order differences change the bytes).
 * @param {string|null|undefined} signatureHeader  The `X-Webhook-Signature`
 *   header value, e.g. `sha256=<hex>`. A leading `sha256=` is stripped.
 * @param {string} secret                 Your account webhook secret.
 * @returns {boolean} true only if the signature is present and valid.
 */
function verifyWebhookSignature(rawBody, signatureHeader, secret) {
  // No secret configured → cannot verify. Confirm via getStatus.
  if (!secret) return false;
  // No signature header → nothing to verify against.
  if (!signatureHeader) return false;

  const provided = String(signatureHeader)
    .replace(/^sha256=/i, '')
    .trim()
    .toLowerCase();

  // A SHA-256 HMAC is 32 bytes → 64 hex chars. Reject anything that isn't valid
  // lowercase hex of the right length before touching crypto.
  if (!/^[0-9a-f]{64}$/.test(provided)) return false;

  const bodyBuf = Buffer.isBuffer(rawBody) ? rawBody : Buffer.from(String(rawBody), 'utf8');
  const expectedHex = crypto.createHmac('sha256', secret).update(bodyBuf).digest('hex');

  const expectedBuf = Buffer.from(expectedHex, 'hex');
  const providedBuf = Buffer.from(provided, 'hex');

  // Equal length is guaranteed by the regex above, but guard anyway.
  if (expectedBuf.length !== providedBuf.length) return false;

  try {
    return crypto.timingSafeEqual(expectedBuf, providedBuf);
  } catch (_) {
    return false;
  }
}

/**
 * Parse a raw webhook body into an event object.
 * Does NOT verify anything — it is just `JSON.parse` with Buffer support.
 * @param {string|Buffer} rawBody
 * @returns {object} the parsed webhook event (see CONTRACT §8.4)
 */
function parseWebhookEvent(rawBody) {
  const text = Buffer.isBuffer(rawBody) ? rawBody.toString('utf8') : String(rawBody);
  return JSON.parse(text);
}

module.exports = { verifyWebhookSignature, parseWebhookEvent };
