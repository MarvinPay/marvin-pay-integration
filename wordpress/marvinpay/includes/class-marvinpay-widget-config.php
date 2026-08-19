<?php
/**
 * HMAC-signs widget configuration rendered into data- attributes so the
 * amount/bounds a visitor posts back cannot be tampered with. The signature
 * covers the exact JSON string in data-config — the browser must echo it
 * verbatim and the server must verify before re-serializing.
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_Widget_Config {

	public static function encode( array $config ) {
		return (string) wp_json_encode( $config );
	}

	public static function sign( $config_json ) {
		return hash_hmac( 'sha256', (string) $config_json, wp_salt( 'nonce' ) );
	}

	public static function verify( $config_json, $sig ) {
		if ( ! is_string( $sig ) || ! preg_match( '/^[0-9a-f]{64}$/', $sig ) ) {
			return false;
		}
		return hash_equals( self::sign( $config_json ), $sig );
	}

	public static function decode( $config_json ) {
		$decoded = json_decode( (string) $config_json, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
