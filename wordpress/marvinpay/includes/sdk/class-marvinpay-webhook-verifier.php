<?php
/**
 * Verifies X-Webhook-Signature: HMAC-SHA256 over the raw request body,
 * formatted `sha256=<hex>`. Port of the kit's PHP SDK WebhookVerifier.
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_Webhook_Verifier {

	public static function verify( $raw_body, $signature_header, $secret ) {
		if ( ! is_string( $secret ) || '' === $secret ) {
			return false;
		}
		if ( ! is_string( $signature_header ) || '' === trim( $signature_header ) ) {
			return false;
		}
		$sig = trim( $signature_header );
		if ( 0 === stripos( $sig, 'sha256=' ) ) {
			$sig = substr( $sig, 7 );
		}
		$sig = strtolower( trim( $sig ) );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $sig ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', (string) $raw_body, $secret );
		return hash_equals( $expected, $sig );
	}
}
