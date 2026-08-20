<?php
/**
 * Pure money/status invariants shared by every code path.
 * No WordPress calls in here — this class is fully unit-tested.
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_Rules {

	const MIN_AMOUNT = 100;
	const MAX_AMOUNT = 500000;

	// Currency is derived from country — they always travel together.
	const CURRENCIES = array(
		'CM' => 'XAF', 'CF' => 'XAF', 'TD' => 'XAF', 'CG' => 'XAF', 'GQ' => 'XAF', 'GA' => 'XAF',
		'BJ' => 'XOF', 'BF' => 'XOF', 'CI' => 'XOF', 'GW' => 'XOF', 'ML' => 'XOF', 'NE' => 'XOF',
		'SN' => 'XOF', 'TG' => 'XOF',
	);

	// Countries with mobile-money providers actually wired (kit docs §10).
	const LIVE_COUNTRIES = array( 'CM', 'GA', 'CI', 'SN', 'BJ', 'TG', 'ML' );

	// Offline fallback only — the authoritative list is GET /v1/payment/payment-methods/{country}.
	const FALLBACK_METHODS = array(
		'CM' => array( 'mtn_cm', 'orange_cm' ),
		'GA' => array( 'airtel_ga', 'moov_ga' ),
		'CI' => array( 'mtn_ci', 'orange_ci', 'moov_ci' ),
		'SN' => array( 'orange_sn', 'free_money_sn', 'expresso_sn' ),
		'BJ' => array( 'mtn_bj', 'moov_bj' ),
		'TG' => array( 't_money_tg' ),
		'ML' => array( 'orange_ml', 'moov_ml' ),
	);

	public static function currency_for_country( $country ) {
		$country = strtoupper( (string) $country );
		return isset( self::CURRENCIES[ $country ] ) ? self::CURRENCIES[ $country ] : null;
	}

	/**
	 * @param mixed $value
	 * @return int|false whole-unit amount, or false when invalid.
	 */
	public static function validate_amount( $value ) {
		if ( is_bool( $value ) || is_array( $value ) || is_object( $value ) || null === $value ) {
			return false;
		}
		if ( is_string( $value ) ) {
			$value = trim( $value );
			// Accept "5000" and WooCommerce-style "5000.00" (whole value) — reject real fractions.
			if ( ! preg_match( '/^(\d+)(\.0+)?$/', $value, $m ) ) {
				return false;
			}
			$value = (int) $m[1];
		} elseif ( is_float( $value ) ) {
			if ( floor( $value ) !== $value ) {
				return false;
			}
			$value = (int) $value;
		} elseif ( ! is_int( $value ) ) {
			return false;
		}
		if ( $value < self::MIN_AMOUNT || $value > self::MAX_AMOUNT ) {
			return false;
		}
		return $value;
	}

	/**
	 * REST says SUCCESSFUL, webhooks say SUCCESS / CANCEL — one internal enum.
	 */
	public static function normalize_status( $raw ) {
		$raw = strtoupper( trim( (string) $raw ) );
		if ( 'SUCCESS' === $raw || 'SUCCESSFUL' === $raw ) {
			return 'SUCCESSFUL';
		}
		if ( 'FAILED' === $raw || 'CANCEL' === $raw || 'CANCELLED' === $raw ) {
			return 'FAILED';
		}
		return 'PENDING';
	}

	/**
	 * Local transaction state machine. Terminal rows are immutable;
	 * ABANDONED may still be corrected by money truth.
	 *
	 * @return string[] statuses a row may currently have for a move to $to.
	 */
	public static function allowed_sources( $to ) {
		switch ( $to ) {
			case 'SUCCESSFUL':
			case 'FAILED':
				return array( 'PENDING', 'ABANDONED' );
			case 'ABANDONED':
				return array( 'PENDING' );
			default:
				return array();
		}
	}

	public static function can_transition( $from, $to ) {
		return in_array( $from, self::allowed_sources( $to ), true );
	}

	public static function mask_mobile( $mobile ) {
		$digits = preg_replace( '/\D/', '', (string) $mobile );
		if ( strlen( $digits ) <= 4 ) {
			return $digits;
		}
		return str_repeat( '•', strlen( $digits ) - 4 ) . substr( $digits, -4 );
	}

	public static function new_transaction_id() {
		return 'wpmp_' . bin2hex( random_bytes( 10 ) );
	}
}
