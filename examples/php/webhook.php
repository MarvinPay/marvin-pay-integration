<?php

declare(strict_types=1);

/**
 * Raw-PHP webhook receiver: verify (best-effort) then CONFIRM out-of-band.
 *
 * Point your account's webhookUrl at this script (behind HTTPS). Serve it with
 * any PHP SAPI, e.g. for a quick local test:
 *
 *   MARVIN_API_KEY=sk_... php -S 127.0.0.1:8080 examples/php/webhook.php
 *
 * Env: MARVIN_API_KEY (required to confirm), MARVIN_BASE_URL (optional),
 *      MARVIN_WEBHOOK_SECRET (optional; unused today — webhooks are UNSIGNED).
 */

require __DIR__ . '/_bootstrap.php';

use MarvinPay\MarvinPayClient;
use MarvinPay\MarvinPayException;
use MarvinPay\WebhookVerifier;

// 1) Read the RAW body exactly as received (do not re-encode).
$raw = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? null;
$secret = marvin_env('MARVIN_WEBHOOK_SECRET', '');

// 2) Best-effort signature check. NOTE: webhooks are UNSIGNED today, so this
//    returns false in production right now. Do NOT trust it as your only anchor.
$verified = WebhookVerifier::verify($raw, $signature, (string) $secret);
error_log('MarvinPay webhook signature verified=' . ($verified ? 'true' : 'false (unsigned today)'));

$event = json_decode($raw, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid JSON']);
    return;
}

$transactionId = $event['transactionId'] ?? null;
$status = $event['status'] ?? null; // SUCCESS | FAILED | PENDING | CANCEL

// 3) Dedupe on transactionId + status (deliveries can repeat). Toy file store;
//    use a real cache/DB in production.
if (is_string($transactionId) && $transactionId !== '') {
    $seenFile = sys_get_temp_dir() . '/marvin_webhook_seen.json';
    $seen = is_file($seenFile) ? (json_decode((string) file_get_contents($seenFile), true) ?: []) : [];
    $key = $transactionId . ':' . (string) $status;
    if (isset($seen[$key])) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'deduped' => true]);
        return;
    }
    $seen[$key] = time();
    @file_put_contents($seenFile, json_encode($seen));
}

// 4) CONFIRM out-of-band before acting. This is mandatory while webhooks are
//    unsigned — never fulfil an order on the webhook payload alone.
if (is_string($transactionId) && $transactionId !== '') {
    $client = new MarvinPayClient(
        marvin_env('MARVIN_API_KEY', ''),
        ['base_url' => marvin_env('MARVIN_BASE_URL', 'https://api.marvincorporate.co/api')]
    );
    try {
        $confirmed = $client->getStatus($transactionId);
        $txStatus = $confirmed['transaction_status'] ?? null; // SUCCESSFUL | FAILED | PENDING
        if ($txStatus === 'SUCCESSFUL') {
            // TODO: fulfil the order / credit the user (idempotently).
            error_log("MarvinPay: {$transactionId} confirmed SUCCESSFUL");
        } elseif ($txStatus === 'FAILED') {
            // TODO: mark the payment failed.
            error_log("MarvinPay: {$transactionId} confirmed FAILED");
        }
    } catch (MarvinPayException $e) {
        // Do not fail the webhook on a transient confirm error — 2xx and retry later.
        error_log('MarvinPay confirm failed: ' . $e->getMessage());
    }
}

// 5) Any 2xx tells Marvin Pay the delivery succeeded.
http_response_code(200);
echo json_encode(['ok' => true]);
