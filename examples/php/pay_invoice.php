<?php

declare(strict_types=1);

/**
 * Pay a public invoice (hosted-pay flow). No API key is required server-side;
 * the invoice reference carries the amount and merchant.
 *
 * Run:
 *   php examples/php/pay_invoice.php <invoice_reference>
 *
 * Env: MARVIN_BASE_URL (optional), MARVIN_API_KEY (optional).
 */

require __DIR__ . '/_bootstrap.php';

use MarvinPay\MarvinPayClient;
use MarvinPay\MarvinPayException;

$reference = $argv[1] ?? marvin_env('MARVIN_INVOICE_REF', 'INV-EXAMPLE');

// API key is optional for hosted-pay; pass '' if you don't have one.
$client = new MarvinPayClient(
    marvin_env('MARVIN_API_KEY', ''),
    ['base_url' => marvin_env('MARVIN_BASE_URL', 'https://api.marvincorporate.co/api')]
);

try {
    // Optionally inspect the invoice and its fee quote first:
    //   $view  = $client->getStatus(...);  // (invoices use their own public GETs; see docs)
    // PayInvoiceRequest has NO amount — the invoice already fixes it.
    $result = $client->payInvoice($reference, [
        'country_code'     => 'CM',
        'currency'         => 'XAF',
        'mobile_number'    => '237670000001',
        'payment_method'   => 'mtn_cm',
        'beneficiary_name' => 'Jane Doe',   // required — payer name
        // 'customer_email' => 'buyer@example.com',
    ]);

    echo "Invoice payment submitted:\n";
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

    // Hosted-pay transactions poll via the public QR-status endpoint.
    if (!empty($result['transaction_id'])) {
        echo "\nPoll public status:\n";
        echo json_encode($client->getQrStatus($result['transaction_id']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    }
} catch (MarvinPayException $e) {
    fwrite(STDERR, sprintf("Invoice payment failed: %s (HTTP %s)\n", $e->getMessage(), $e->getHttpStatus() ?? 'n/a'));
    exit(1);
}
