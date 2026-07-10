'use strict';

/**
 * Example: fetch the authoritative status of one transaction.
 *
 * Run:
 *   MARVIN_API_KEY=... node poll-status.js <transactionId>
 *   # or:  node --env-file=.env poll-status.js order-123
 *
 * This does a single GET /v1/payment/status/{transactionId}. To block until the
 * transaction settles, use client.waitForCompletion (see collect.js).
 */

const { MarvinPayClient, MarvinPayError } = require('../../sdks/node/marvin-pay.js');

const apiKey = process.env.MARVIN_API_KEY;
if (!apiKey) {
  console.error('Set MARVIN_API_KEY (see .env.example).');
  process.exit(1);
}

const transactionId = process.argv[2];
if (!transactionId) {
  console.error('Usage: node poll-status.js <transactionId>');
  process.exit(1);
}

const client = new MarvinPayClient({
  apiKey,
  baseUrl: process.env.MARVIN_BASE_URL,
});

async function main() {
  const status = await client.getStatus(transactionId);
  console.log('Status:', {
    transaction_id: status.transaction_id,
    transaction_status: status.transaction_status,
    normalized: MarvinPayClient.normalizeStatus(status.transaction_status),
    currency: status.currency,
    message: status.message,
    timestamp: status.timestamp,
  });
}

main().catch((err) => {
  if (err instanceof MarvinPayError) {
    console.error(`MarvinPayError ${err.httpStatus} (${err.code || 'HTTP'}): ${err.message}`);
    if (err.body) console.error('body:', err.body);
  } else {
    console.error(err);
  }
  process.exit(1);
});
