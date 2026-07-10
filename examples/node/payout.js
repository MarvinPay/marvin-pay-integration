'use strict';

/**
 * Example: a single payout (merchant → recipient).
 *
 * Run:
 *   MARVIN_API_KEY=... node payout.js
 *   # or:  node --env-file=.env payout.js
 *
 * `payout` uses the same PaymentRequest shape as `collect`; here mobile_number /
 * beneficiary_name identify the RECIPIENT. fee_bearer=CUSTOMER nets the fee out
 * of the amount.
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
  baseUrl: process.env.MARVIN_BASE_URL,
});

async function main() {
  const transactionId = `example-payout-${crypto.randomUUID()}`;

  const paymentRequest = {
    country_code: 'CI',
    currency: 'XOF',
    amount: 25000, // whole number, 100–500000
    mobile_number: '2250700000000',
    payment_method: 'orange_ci', // from client.getPaymentMethods('CI')
    transaction_id: transactionId,
    beneficiary_name: 'Kwame Recipient',
    description: 'Example payout',
    fee_bearer: 'MERCHANT', // 'CUSTOMER' nets the fee out of the amount
  };

  console.log(`Submitting payout ${transactionId} ...`);
  const result = await client.payout(paymentRequest);
  console.log('Accepted:', {
    transaction_id: result.transaction_id,
    transaction_status: result.transaction_status,
    message: result.message,
    partner_transaction_id: result.partner_transaction_id,
    replayed: result.idempotencyReplayed === true,
  });

  console.log('Waiting for completion ...');
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
