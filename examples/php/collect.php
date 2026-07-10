<?php

declare(strict_types=1);

/**
 * Collect from a customer (payer -> merchant).
 *
 * Run:
 *   MARVIN_API_KEY=sk_... php examples/php/collect.php
 *
 * Env: MARVIN_API_KEY (required), MARVIN_BASE_URL (optional).
 */

require __DIR__ . '/_bootstrap.php';

use MarvinPay\MarvinPayClient;
use MarvinPay\MarvinPayException;

$client = new MarvinPayClient(
    marvin_env('MARVIN_API_KEY', 'YOUR_API_KEY'),
    ['base_url' => marvin_env('MARVIN_BASE_URL', 'https://api.marvincorporate.co/api')]
);

// Your own unique reference for this transaction.
$transactionId = 'order-' . date('Ymd-His');

try {
    // X-Idempotency-Key defaults to transaction_id.
    $result = $client->collect([
        'country_code'   => 'CM',
        'currency'       => 'XAF',      // currency + country ALWAYS travel together
        'amount'         => 5000,        // whole number, 100–500000
        'mobile_number'  => '237670000001',
        'payment_method' => 'mtn_cm',    // see getPaymentMethods('CM')
        'transaction_id' => $transactionId,
        'description'    => 'Order #1001',
        // 'fee_bearer'     => 'CUSTOMER', // default MERCHANT
        // 'customer_email' => 'buyer@example.com',
    ]);

    echo "Collect submitted:\n";
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

    $headers = $client->getLastResponseHeaders();
    if (($headers['x-idempotency-replay'] ?? null) === 'true') {
        echo "(this was an idempotent replay of an earlier request)\n";
    }

    echo "\nMobile money is asynchronous — a 200/202 does NOT mean money moved.\n";
    echo "Confirm with: php examples/php/poll_status.php {$transactionId}\n";
} catch (MarvinPayException $e) {
    fwrite(STDERR, sprintf("Collect failed: %s (HTTP %s)\n", $e->getMessage(), $e->getHttpStatus() ?? 'n/a'));
    if ($e->getBody() !== null) {
        fwrite(STDERR, json_encode($e->getBody(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    exit(1);
}
