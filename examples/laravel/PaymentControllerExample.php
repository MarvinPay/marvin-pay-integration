<?php

declare(strict_types=1);

/**
 * EXAMPLE controller — copy/adapt into your app (e.g.
 * app/Http/Controllers/CheckoutController.php). Illustrative; not auto-loaded.
 *
 * Shows MarvinPay::collect(...) followed by waitForCompletion(...).
 */

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use MarvinPay\Laravel\Facades\MarvinPay;

class PaymentControllerExample
{
    /**
     * Kick off a collect and (for demo purposes) block until it resolves.
     *
     * In production prefer to return immediately after the 202 and resolve the
     * outcome via a webhook or a queued poller rather than blocking the request.
     */
    public function pay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['required', 'string'],
            'amount'        => ['required', 'integer', 'min:100', 'max:500000'],
        ]);

        // Your own unique reference for this transaction.
        $transactionId = 'order-' . $request->user()?->id . '-' . now()->format('YmdHis');

        try {
            // X-Idempotency-Key defaults to transaction_id.
            $result = MarvinPay::collect([
                'country_code'   => 'CM',
                'currency'       => 'XAF',      // currency + country ALWAYS travel together
                'amount'         => $validated['amount'],
                'mobile_number'  => $validated['mobile_number'],
                'payment_method' => 'mtn_cm',   // see MarvinPay::getPaymentMethods('CM')
                'transaction_id' => $transactionId,
                'description'    => 'Web checkout',
            ]);
        } catch (RequestException $e) {
            return response()->json([
                'error'  => 'collect_failed',
                'status' => $e->response?->status(),
                'body'   => $e->response?->json(),
            ], 422);
        }

        // Demo: wait for a terminal state (5s → backoff to 60s → give up at 600s).
        // A 202 does NOT mean money moved — this is the authoritative confirmation.
        $final = MarvinPay::waitForCompletion($transactionId);

        return response()->json([
            'transaction_id'     => $transactionId,
            'submit'             => $result,
            'transaction_status' => $final['transaction_status'] ?? 'PENDING',
        ]);
    }
}
