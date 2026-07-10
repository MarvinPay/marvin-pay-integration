<?php

declare(strict_types=1);

namespace MarvinPay\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MarvinPay\Laravel\Facades\MarvinPay;

/**
 * Example webhook receiver.
 *
 * Flow: read the event → dedupe on (transactionId + status) → confirm the
 * outcome out-of-band via getStatus() (webhooks are a HINT; deliveries are
 * at-least-once) → act → return 200 (any 2xx counts as success to Marvin Pay).
 *
 * Copy/adapt this into your app; wire it behind {@see \MarvinPay\Laravel\Http\Middleware\VerifyMarvinPayWebhook}.
 */
class WebhookController
{
    public function handle(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $event */
        $event = $request->json()->all();

        $transactionId = $event['transactionId'] ?? null;
        $status = $event['status'] ?? null; // SUCCESS | FAILED | PENDING | CANCEL

        if (!is_string($transactionId) || $transactionId === '') {
            // Nothing actionable; still 2xx so Marvin Pay does not retry.
            return response()->json(['ok' => true, 'ignored' => 'missing transactionId']);
        }

        // Dedupe: deliveries can repeat. Key on transactionId + status.
        $dedupeKey = 'marvinpay:webhook:' . $transactionId . ':' . (string) $status;
        if (Cache::has($dedupeKey)) {
            return response()->json(['ok' => true, 'deduped' => true]);
        }
        Cache::put($dedupeKey, true, now()->addHours(24));

        // The webhook is only a hint — confirm before acting (deliveries are at-least-once).
        try {
            $confirmed = MarvinPay::getStatus($transactionId);
            $txStatus = $confirmed['transaction_status'] ?? null; // SUCCESSFUL | FAILED | PENDING

            if ($txStatus === 'SUCCESSFUL') {
                // TODO: fulfill the order / credit the user (idempotently).
            } elseif ($txStatus === 'FAILED') {
                // TODO: mark the payment failed.
            }
            // PENDING: do nothing yet; a later webhook or your poller resolves it.
        } catch (\Throwable $e) {
            // Do not fail the webhook on a transient confirm error; retry/poll later.
            Log::error('MarvinPay webhook confirmation failed for ' . $transactionId . ': ' . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }
}
