'use strict';

/**
 * Example: pay a public invoice by reference (a "hosted pay" flow).
 *
 * Run:
 *   node pay-invoice.js <invoiceReference>
 *   # or:  node --env-file=.env pay-invoice.js INV-abc123
 *
 * Public flows need NO API key — the merchant is resolved from the reference. So
 * MARVIN_API_KEY is optional here; only MARVIN_BASE_URL matters (to point at the
 * right environment). The invoice fixes the amount, so PayInvoiceRequest has no
 * `amount` field.
 */

const { MarvinPayClient, MarvinPayError } = require('../../sdks/node/marvin-pay.js');

const reference = process.argv[2];
if (!reference) {
  console.error('Usage: node pay-invoice.js <invoiceReference>');
  process.exit(1);
}

// No apiKey needed for public flows; pass baseUrl to target an environment.
const client = new MarvinPayClient({
  baseUrl: process.env.MARVIN_BASE_URL,
});

async function main() {
  const body = {
    country_code: 'CM',
    currency: 'XAF',
    mobile_number: '237670000001',
    payment_method: 'mtn_cm',
    beneficiary_name: 'Jane Payer', // required — payer name
    // customer_email: 'jane@example.com', // optional → receipt email
  };

  console.log(`Paying invoice ${reference} ...`);
  const result = await client.payInvoice(reference, body);
  console.log('Accepted:', {
    transaction_id: result.transaction_id,
    transaction_status: result.transaction_status,
    message: result.message,
  });

  // Public flows are polled via the public QR-status endpoint (no auth).
  console.log('Polling public status ...');
  const status = await client.getQrStatus(result.transaction_id);
  console.log('Public status:', {
    status: status.status,
    normalized: MarvinPayClient.normalizeStatus(status.status),
    amount: status.amount,
    currency: status.currency,
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
