<?php
/**
 * Front-end AJAX: initiate a collect and poll its status.
 * Every money decision funnels through MarvinPay_Rules; widget parameters
 * are only trusted when their HMAC signature verifies (MarvinPay_Widget_Config).
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_Ajax {

	const NONCE_ACTION   = 'marvinpay_pay';
	const RATE_LIMIT     = 5;    // initiations …
	const RATE_WINDOW    = 600;  // … per IP per 10 minutes
	const POLL_THROTTLE  = 4;    // seconds between real status calls per tx

	public static function init() {
		add_action( 'wp_ajax_marvinpay_initiate', array( __CLASS__, 'initiate' ) );
		add_action( 'wp_ajax_nopriv_marvinpay_initiate', array( __CLASS__, 'initiate' ) );
		add_action( 'wp_ajax_marvinpay_poll', array( __CLASS__, 'poll' ) );
		add_action( 'wp_ajax_nopriv_marvinpay_poll', array( __CLASS__, 'poll' ) );
	}

	// ── tested pure helpers ─────────────────────────────────────────────

	/**
	 * @param array    $in              raw request fields
	 * @param string[] $allowed_methods provider names valid for the configured country
	 * @return array|WP_Error normalized payer fields
	 */
	public static function validate_payer_input( array $in, array $allowed_methods ) {
		$method = isset( $in['payment_method'] ) ? sanitize_text_field( (string) $in['payment_method'] ) : '';
		if ( ! in_array( $method, $allowed_methods, true ) ) {
			return new WP_Error( 'marvinpay_method', __( 'Please choose a valid payment method.', 'marvinpay' ) );
		}
		$mobile = preg_replace( '/\D/', '', isset( $in['mobile_number'] ) ? (string) $in['mobile_number'] : '' );
		if ( strlen( $mobile ) < 8 || strlen( $mobile ) > 15 ) {
			return new WP_Error( 'marvinpay_mobile', __( 'Please enter a valid mobile money number.', 'marvinpay' ) );
		}
		return array(
			'payment_method' => $method,
			'mobile_number'  => $mobile,
			'payer_name'     => sanitize_text_field( isset( $in['payer_name'] ) ? (string) $in['payer_name'] : '' ),
			'payer_email'    => sanitize_email( isset( $in['payer_email'] ) ? (string) $in['payer_email'] : '' ),
		);
	}

	/**
	 * Amount authority: a signed fixed amount must match exactly; an open
	 * amount is bounded by the signed min/max intersected with global limits.
	 *
	 * @return int|WP_Error
	 */
	public static function resolve_amount( array $config, $requested ) {
		$amount = MarvinPay_Rules::validate_amount( $requested );
		if ( false === $amount ) {
			return new WP_Error( 'marvinpay_amount', __( 'Amount must be a whole number between 100 and 500,000.', 'marvinpay' ) );
		}
		$fixed = isset( $config['amount'] ) ? (int) $config['amount'] : 0;
		if ( $fixed > 0 ) {
			if ( $amount !== $fixed ) {
				return new WP_Error( 'marvinpay_amount', __( 'Amount does not match this payment button.', 'marvinpay' ) );
			}
			return $amount;
		}
		$min = max( isset( $config['min'] ) ? (int) $config['min'] : 0, MarvinPay_Rules::MIN_AMOUNT );
		$max = isset( $config['max'] ) && (int) $config['max'] > 0
			? min( (int) $config['max'], MarvinPay_Rules::MAX_AMOUNT )
			: MarvinPay_Rules::MAX_AMOUNT;
		if ( $amount < $min || $amount > $max ) {
			return new WP_Error(
				'marvinpay_amount',
				sprintf(
					/* translators: 1: minimum, 2: maximum */
					__( 'Amount must be between %1$s and %2$s.', 'marvinpay' ),
					number_format_i18n( $min ),
					number_format_i18n( $max )
				)
			);
		}
		return $amount;
	}

	// ── endpoints ───────────────────────────────────────────────────────

	public static function initiate() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! MarvinPay_Settings::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Payments are not configured on this site yet.', 'marvinpay' ) ) );
		}
		self::enforce_rate_limit();

		// 1. Verify the signed widget config (echoed verbatim by the browser).
		$config_json = isset( $_POST['config'] ) ? wp_unslash( (string) $_POST['config'] ) : '';
		$sig         = isset( $_POST['sig'] ) ? sanitize_text_field( (string) $_POST['sig'] ) : '';
		if ( ! MarvinPay_Widget_Config::verify( $config_json, $sig ) ) {
			wp_send_json_error( array( 'message' => __( 'This payment form has expired — reload the page and try again.', 'marvinpay' ) ) );
		}
		$config = MarvinPay_Widget_Config::decode( $config_json );
		if ( null === $config || empty( $config['type'] )
			|| ! in_array( $config['type'], array( 'button', 'donation', 'inline', 'woocommerce' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payment configuration.', 'marvinpay' ) ) );
		}

		// 2. Payer fields.
		$payer = self::validate_payer_input( wp_unslash( $_POST ), MarvinPay_Settings::payment_methods() );
		if ( is_wp_error( $payer ) ) {
			wp_send_json_error( array( 'message' => $payer->get_error_message() ) );
		}

		// 3. Amount authority + description + email defaults per source.
		$source      = (string) $config['type'];
		$source_ref  = isset( $config['source_ref'] ) ? (string) $config['source_ref'] : '';
		$description = isset( $config['description'] ) ? (string) $config['description'] : '';
		$payer_email = $payer['payer_email'];

		if ( 'woocommerce' === $source ) {
			if ( ! function_exists( 'wc_get_order' ) ) {
				wp_send_json_error( array( 'message' => __( 'WooCommerce is not active.', 'marvinpay' ) ) );
			}
			$order = wc_get_order( absint( $source_ref ) );
			if ( ! $order || ! isset( $config['order_key'] ) || ! hash_equals( $order->get_order_key(), (string) $config['order_key'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Order not found.', 'marvinpay' ) ) );
			}
			if ( ! $order->needs_payment() ) {
				wp_send_json_error( array( 'message' => __( 'This order is already paid.', 'marvinpay' ) ) );
			}
			// The ORDER is the amount authority — never the client.
			$total  = (string) $order->get_total();
			$amount = MarvinPay_Rules::validate_amount( $total );
			if ( false === $amount ) {
				wp_send_json_error( array( 'message' => __( 'Order total must be a whole amount between 100 and 500,000.', 'marvinpay' ) ) );
			}
			$description = sprintf( 'Order #%s', $order->get_order_number() );
			if ( '' === $payer_email ) {
				$payer_email = sanitize_email( (string) $order->get_billing_email() );
			}
		} else {
			$amount = self::resolve_amount( $config, isset( $_POST['amount'] ) ? wp_unslash( (string) $_POST['amount'] ) : '' );
			if ( is_wp_error( $amount ) ) {
				wp_send_json_error( array( 'message' => $amount->get_error_message() ) );
			}
		}

		// 4. Record + collect.
		$transaction_id = MarvinPay_Rules::new_transaction_id();
		$country        = MarvinPay_Settings::country();
		$currency       = MarvinPay_Settings::currency();

		$inserted = MarvinPay_Transactions::insert( array(
			'transaction_id' => $transaction_id,
			'source'         => $source,
			'source_ref'     => $source_ref,
			'amount'         => $amount,
			'currency'       => $currency,
			'country'        => $country,
			'payment_method' => $payer['payment_method'],
			'mobile_number'  => $payer['mobile_number'],
			'payer_name'     => $payer['payer_name'],
			'payer_email'    => $payer_email,
			'description'    => $description,
			'mode'           => MarvinPay_Settings::mode(),
		) );
		if ( ! $inserted ) {
			// Never move money without a local record to reconcile against.
			wp_send_json_error( array( 'message' => __( 'Could not start the payment — please try again.', 'marvinpay' ) ), 500 );
		}

		$body = array(
			'country_code'   => $country,
			'currency'       => $currency,
			'amount'         => $amount,
			'mobile_number'  => $payer['mobile_number'],
			'payment_method' => $payer['payment_method'],
			'transaction_id' => $transaction_id,
		);
		if ( '' !== $payer['payer_name'] ) {
			$body['beneficiary_name'] = $payer['payer_name'];
		}
		if ( '' !== $description ) {
			$body['description'] = $description;
		}
		if ( '' !== $payer_email ) {
			$body['customer_email'] = $payer_email;
		}

		try {
			$result = MarvinPay_Plugin::client()->collect( $body );
		} catch ( MarvinPay_API_Exception $e ) {
			MarvinPay_Transactions::transition( $transaction_id, 'FAILED' );
			$message = ( 0 === $e->http_status || $e->http_status >= 500 )
				? __( 'The payment service is temporarily unavailable — please try again.', 'marvinpay' )
				: $e->getMessage();
			wp_send_json_error( array( 'message' => $message, 'transaction_id' => $transaction_id ) );
		}

		$status = MarvinPay_Rules::normalize_status( isset( $result['transaction_status'] ) ? $result['transaction_status'] : '' );
		if ( 'PENDING' !== $status ) {
			$row = MarvinPay_Transactions::find( $transaction_id );
			if ( $row ) {
				MarvinPay_Fulfillment::apply( $row, $status, isset( $result['partner_transaction_id'] ) ? $result['partner_transaction_id'] : null );
			}
		}

		wp_send_json_success( array( 'transaction_id' => $transaction_id, 'status' => $status ) );
	}

	public static function poll() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$tx = isset( $_POST['tx'] ) ? sanitize_text_field( (string) $_POST['tx'] ) : '';
		if ( ! preg_match( '/^wpmp_[0-9a-f]{20}$/', $tx ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown transaction.', 'marvinpay' ) ), 404 );
		}
		$row = MarvinPay_Transactions::find( $tx );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Unknown transaction.', 'marvinpay' ) ), 404 );
		}

		// Terminal already? No API call needed.
		if ( 'PENDING' !== $row->status ) {
			wp_send_json_success( array( 'status' => $row->status, 'message' => '' ) );
		}

		// Throttle: multiple tabs must not hammer the status endpoint.
		$throttle_key = 'marvinpay_poll_' . $tx;
		if ( false !== get_transient( $throttle_key ) ) {
			wp_send_json_success( array( 'status' => $row->status, 'message' => '' ) );
		}
		set_transient( $throttle_key, 1, self::POLL_THROTTLE );

		try {
			$result = MarvinPay_Plugin::client()->get_status( $tx );
		} catch ( MarvinPay_API_Exception $e ) {
			wp_send_json_success( array( 'status' => $row->status, 'message' => '' ) ); // transient API hiccup: report current state
		}

		$status = MarvinPay_Rules::normalize_status( isset( $result['transaction_status'] ) ? $result['transaction_status'] : '' );
		if ( 'PENDING' !== $status ) {
			MarvinPay_Fulfillment::apply( $row, $status, isset( $result['partner_transaction_id'] ) ? $result['partner_transaction_id'] : null );
		}

		wp_send_json_success( array(
			// Report the freshly confirmed status even when a concurrent webhook
			// won the row transition (apply() then changes nothing locally).
			'status'  => 'PENDING' === $status ? $row->status : $status,
			'message' => isset( $result['message'] ) ? (string) $result['message'] : '',
		) );
	}

	private static function enforce_rate_limit() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key = 'marvinpay_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= self::RATE_LIMIT ) {
			wp_send_json_error( array( 'message' => __( 'Too many payment attempts — please wait a few minutes and try again.', 'marvinpay' ) ), 429 );
		}
		set_transient( $key, $n + 1, self::RATE_WINDOW );
	}
}
