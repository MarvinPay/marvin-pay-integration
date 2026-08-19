<?php
/**
 * Marvin Pay API client on the WordPress HTTP API.
 *
 * Port of sdks/php/src/MarvinPayClient.php (the kit's reference client) with
 * wp_remote_request() as transport. Behavior parity: X-API-KEY auth, snake_case
 * bodies, whole-integer amounts, X-Idempotency-Key on money moves (defaulting
 * to the body's transaction_id), single GET retry on 429/5xx/transport error.
 * Deviation from the SDK: no sleep between retries — we run inside web requests.
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_Client {

	private $api_key;
	private $base_url;
	private $timeout;

	public function __construct( $api_key, $base_url, $timeout = 30 ) {
		$this->api_key  = (string) $api_key;
		$this->base_url = rtrim( (string) $base_url, '/' );
		$this->timeout  = (int) $timeout;
	}

	/** POST /v1/payment/collect — payer → merchant. */
	public function collect( array $payment_request, $idempotency_key = null ) {
		if ( isset( $payment_request['amount'] ) && is_numeric( $payment_request['amount'] ) ) {
			$payment_request['amount'] = (int) $payment_request['amount'];
		}
		$key = ( null === $idempotency_key || '' === $idempotency_key )
			? ( isset( $payment_request['transaction_id'] ) ? $payment_request['transaction_id'] : null )
			: $idempotency_key;
		if ( null === $key || '' === $key ) {
			// Money-moving requests never go out unprotected (spec: every collect
			// sends X-Idempotency-Key). Fail loudly instead of skipping the header.
			throw new MarvinPay_API_Exception( 'collect() requires an idempotency key or a transaction_id.' );
		}
		return $this->request( 'POST', '/v1/payment/collect', array(
			'json'    => $payment_request,
			'headers' => array( 'X-Idempotency-Key' => (string) $key ),
		) );
	}

	/** GET /v1/payment/status/{transactionId} — the authoritative outcome. */
	public function get_status( $transaction_id ) {
		return $this->request( 'GET', '/v1/payment/status/' . rawurlencode( (string) $transaction_id ) );
	}

	/** GET /v1/payment/payment-methods/{countryCode} — provider-name strings. */
	public function get_payment_methods( $country_code ) {
		return $this->request( 'GET', '/v1/payment/payment-methods/' . rawurlencode( strtoupper( (string) $country_code ) ) );
	}

	private function request( $method, $path, array $opts = array() ) {
		$is_get   = ( 'GET' === $method );
		$attempts = $is_get ? 2 : 1;

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$args = array(
				'method'  => $method,
				'timeout' => $this->timeout,
				'headers' => array_merge(
					array(
						'Accept'    => 'application/json',
						'X-API-KEY' => $this->api_key,
					),
					isset( $opts['headers'] ) ? $opts['headers'] : array()
				),
			);
			if ( array_key_exists( 'json', $opts ) ) {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = wp_json_encode( $opts['json'] );
			}

			$response = wp_remote_request( $this->base_url . $path, $args );

			if ( is_wp_error( $response ) ) {
				if ( $is_get && $attempt < $attempts ) {
					continue;
				}
				throw new MarvinPay_API_Exception( 'Marvin Pay request failed: ' . $response->get_error_message(), 0, null );
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );

			if ( ( 429 === $status || $status >= 500 ) && $is_get && $attempt < $attempts ) {
				continue;
			}

			$decoded = json_decode( $raw, true );

			if ( $status < 200 || $status >= 300 ) {
				throw new MarvinPay_API_Exception(
					self::extract_message( $decoded, $raw, $status ),
					$status,
					is_array( $decoded ) ? $decoded : null
				);
			}

			return is_array( $decoded ) ? $decoded : array();
		}

		throw new MarvinPay_API_Exception( 'Marvin Pay request failed', 0, null ); // unreachable
	}

	private static function extract_message( $decoded, $raw, $status ) {
		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) && '' !== $decoded['message'] ) {
				return $decoded['message'];
			}
			if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) && '' !== $decoded['error'] ) {
				return $decoded['error'];
			}
		}
		if ( '' !== $raw ) {
			return 'HTTP ' . $status . ': ' . $raw;
		}
		return 'HTTP ' . $status;
	}
}
