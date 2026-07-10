<?php

declare(strict_types=1);

namespace MarvinPay\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array collect(array $paymentRequest, ?string $idempotencyKey = null)
 * @method static array payout(array $paymentRequest, ?string $idempotencyKey = null)
 * @method static array getStatus(string $transactionId)
 * @method static array getFees(array $params)
 * @method static array getPaymentMethods(string $countryCode)
 * @method static array waitForCompletion(string $transactionId, array $opts = [])
 * @method static array getLastResponseHeaders()
 *
 * @see \MarvinPay\Laravel\MarvinPay
 */
class MarvinPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'marvinpay';
    }
}
