<?php

declare(strict_types=1);

/**
 * Confirm a transaction's outcome via status polling.
 *
 * Run:
 *   MARVIN_API_KEY=sk_... php examples/php/poll_status.php <transaction_id>
 *
 * Env: MARVIN_API_KEY (required), MARVIN_BASE_URL (optional),
 *      MARVIN_TXN_ID (fallback if no CLI arg).
 */

require __DIR__ . '/_bootstrap.php';

use MarvinPay\MarvinPayClient;
use MarvinPay\MarvinPayException;

$transactionId = $argv[1] ?? marvin_env('MARVIN_TXN_ID');
if (!$transactionId) {
    fwrite(STDERR, "Usage: php poll_status.php <transaction_id>\n");
    exit(2);
}

$client = new MarvinPayClient(
    marvin_env('MARVIN_API_KEY', 'YOUR_API_KEY'),
    ['base_url' => marvin_env('MARVIN_BASE_URL', 'https://api.marvincorporate.co/api')]
);

try {
    // One-shot authoritative read.
    $status = $client->getStatus($transactionId);
    echo "Current status:\n";
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n\n";

    // Block until terminal: first poll after 5s, backoff to 60s, give up at 600s.
    echo "Waiting for a terminal state...\n";
    $final = $client->waitForCompletion($transactionId);
    echo "Final transaction_status: " . ($final['transaction_status'] ?? 'UNKNOWN') . "\n";
} catch (MarvinPayException $e) {
    fwrite(STDERR, sprintf("Status check failed: %s (HTTP %s)\n", $e->getMessage(), $e->getHttpStatus() ?? 'n/a'));
    exit(1);
}
