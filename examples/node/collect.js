'use strict';

/**
 * Example: collect from a customer, then wait for the transaction to settle.
 *
 * Run:
 *   MARVIN_API_KEY=... node collect.js
 *   # or, with a .env file (Node 20.6+):  node --env-file=.env collect.js
 *
 * Mobile-money collects are asynchronous: the customer gets a USSD/app prompt,
 * so `collect` typically returns PENDING and we poll `waitForCompletion`.
 */

const crypto = require('node:crypto');
const { MarvinPayClient, MarvinPayError } = require('../../sdks/node/marvin-pay.js');

const apiKey = process.env.MARVIN_API_KEY;
if (!apiKey) {
  console.error('Set MARVIN_API_KEY (see .env.example).');
  process.exit(1);
}

const client = new MarvinPayClient({
  apiKey,
  baseUrl: process.env.MARVIN_BASE_URL, // optional; defaults to production
});

async function main() {
  // transaction_id is YOUR unique reference. The SDK also uses it as the
  // idempotency key, so re-running with the same id returns the same result.
  const transactionId = `example-collect-${crypto.randomUUID()}`;

  const paymentRequest = {
    country_code: 'CM', // currency + country always travel together
    currency: 'XAF',
    amount: 5000, // whole number, 100–500000
    mobile_number: '<your-test-msisdn>',
    payment_method: 'mtn_cm', // from client.getPaymentMethods('CM')
    transaction_id: transactionId,
    beneficiary_name: 'Jane Payer',
    description: 'Example collect',
    fee_bearer: 'MERCHANT', // or 'CUSTOMER' to gross up the payer
    // customer_email: 'jane@example.com', // optional → sends a receipt
  };

  console.log(`Submitting collect ${transactionId} ...`);
  const result = await client.collect(paymentRequest);
  console.log('Accepted:', {
    transaction_id: result.transaction_id,
    transaction_status: result.transaction_status,
    message: result.message,
    partner_transaction_id: result.partner_transaction_id,
    replayed: result.idempotencyReplayed === true,
  });

  console.log('Waiting for completion (poll: 5s start, backoff to 60s, 10m cap) ...');
  const final = await client.waitForCompletion(transactionId);
  console.log('Final status:', {
    raw: final.transaction_status,
    normalized: MarvinPayClient.normalizeStatus(final.transaction_status),
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
