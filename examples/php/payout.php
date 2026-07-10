<?php

declare(strict_types=1);

/**
 * Pay out to a recipient (merchant -> recipient).
 *
 * Run:
 *   MARVIN_API_KEY=sk_... php examples/php/payout.php
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

$transactionId = 'payout-' . date('Ymd-His');

try {
    // Here mobile_number / beneficiary_name identify the RECIPIENT.
    // X-Idempotency-Key defaults to transaction_id.
    $result = $client->payout([
        'country_code'     => 'CM',
        'currency'         => 'XAF',
        'amount'           => 2500,      // whole number, 100–500000
        'mobile_number'    => '237670000002',
        'payment_method'   => 'mtn_cm',
        'transaction_id'   => $transactionId,
        'beneficiary_name' => 'Jane Doe',
        'description'      => 'Supplier settlement',
        // 'fee_bearer'    => 'CUSTOMER', // nets the fee out of amount
    ]);

    echo "Payout submitted:\n";
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    echo "\nConfirm with: php examples/php/poll_status.php {$transactionId}\n";
} catch (MarvinPayException $e) {
    fwrite(STDERR, sprintf("Payout failed: %s (HTTP %s)\n", $e->getMessage(), $e->getHttpStatus() ?? 'n/a'));
    exit(1);
}
