'use strict';

/**
 * Example: a tiny webhook receiver for Marvin Pay events.
 *
 * Run:
 *   npm install            # installs express (see package.json here)
 *   MARVIN_API_KEY=... node webhook-server.js
 *   # or:  node --env-file=.env webhook-server.js
 *
 * ============================================================================
 *  VERIFY, THEN ALWAYS CONFIRM (CONTRACT.md §8.5).
 * ============================================================================
 *  `verifyWebhookSignature` checks the `X-Webhook-Signature` header (HMAC-SHA256
 *  over the raw body, sent as `sha256=<hex>`). Configure MARVIN_WEBHOOK_SECRET to
 *  enable signed deliveries. Because deliveries are at-least-once, we NEVER trust
 *  the webhook on its own:
 *    1. Confirm out-of-band via client.getStatus(transactionId) before acting.
 *    2. Dedupe on transactionId + status (events may be redelivered).
 *    3. Respond 2xx fast; do the real work after acking.
 *  Keep the getStatus confirmation regardless of the signature result.
 * ============================================================================
 */

const express = require('express');
const { MarvinPayClient } = require('../../sdks/node/marvin-pay.js');
const { verifyWebhookSignature, parseWebhookEvent } = require('../../sdks/node/webhook-verifier.js');

const apiKey = process.env.MARVIN_API_KEY;
if (!apiKey) {
  console.error('Set MARVIN_API_KEY (needed to confirm events via getStatus).');
  process.exit(1);
}

const client = new MarvinPayClient({
  apiKey,
  baseUrl: process.env.MARVIN_BASE_URL,
});

const webhookSecret = process.env.MARVIN_WEBHOOK_SECRET || '';
const port = Number(process.env.PORT) || 3000;

// Demo-only dedupe store. In production use a durable store (Redis/DB) so
// dedupe survives restarts and works across instances.
const processed = new Set();

const app = express();

// Capture the RAW body (Buffer) — do NOT let JSON middleware re-serialize it, or
// the HMAC signature check will not match. Use a hard-to-guess path.
app.post('/webhooks/marvin', express.raw({ type: '*/*' }), async (req, res) => {
  const raw = req.body; // Buffer

  // Returns false when the secret or signature is missing. Logged for visibility.
  const signatureValid = verifyWebhookSignature(
    raw,
    req.get('X-Webhook-Signature'),
    webhookSecret,
  );

  let event;
  try {
    event = parseWebhookEvent(raw);
  } catch (err) {
    return res.status(400).send('invalid json');
  }

  // Ack immediately so the retry scheduler backs off. Any 2xx = success.
  res.status(200).send('ok');

  // ---- everything below runs AFTER we've acked ----

  const dedupeKey = `${event.transactionId}:${event.status}`;
  if (processed.has(dedupeKey)) {
    console.log(`[webhook] duplicate ignored: ${dedupeKey}`);
    return;
  }
  processed.add(dedupeKey);

  console.log('[webhook] received', {
    event: event.event,
    transactionId: event.transactionId,
    status: event.status,
    signatureValid, // false when secret/signature missing — confirm via getStatus regardless
    attempt: req.get('X-Webhook-Attempt'),
    deliveryId: req.get('X-Webhook-Delivery-Id'),
  });

  // ALWAYS confirm out-of-band before acting on the event.
  try {
    const status = await client.getStatus(event.transactionId);
    const normalized = MarvinPayClient.normalizeStatus(status.transaction_status);
    console.log(`[webhook] confirmed ${event.transactionId} → ${normalized}`);

    if (normalized === 'SUCCEEDED') {
      // ... fulfill the order / credit the user here ...
      console.log(`[webhook] acting on confirmed success for ${event.transactionId}`);
    } else if (normalized === 'FAILED' || normalized === 'CANCELLED') {
      // ... mark failed / release hold ...
      console.log(`[webhook] ${event.transactionId} terminal non-success: ${normalized}`);
    } else {
      // Still PENDING per the authoritative check — do nothing; another webhook
      // (or your own polling) will resolve it.
      console.log(`[webhook] ${event.transactionId} still ${normalized}; waiting`);
    }
  } catch (err) {
    console.error(`[webhook] confirmation failed for ${event.transactionId}: ${err.message}`);
  }
});

app.listen(port, () => {
  console.log(`Marvin Pay webhook receiver listening on http://localhost:${port}/webhooks/marvin`);
});
