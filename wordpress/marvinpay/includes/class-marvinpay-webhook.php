<?php
/**
 * Webhook receiver. Defensive by design (kit docs §08):
 * verify signature when a secret is set, ALWAYS re-confirm via the status
 * endpoint before acting, dedupe via the forward-only row transition.
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_Webhook {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route( 'marvinpay/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true',
		) );
	}

	/** @param WP_REST_Request $request */
	public static function handle( $request ) {
		$raw    = $request->get_body();
		$secret = (string) MarvinPay_Settings::get( 'webhook_secret' );

		if ( '' !== $secret ) {
			$sig = $request->get_header( 'x-webhook-signature' );
			if ( ! MarvinPay_Webhook_Verifier::verify( $raw, $sig, $secret ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid signature' ), 400 );
			}
		}

		$payload = json_decode( $raw, true );
		$txid    = is_array( $payload ) && isset( $payload['transactionId'] ) ? (string) $payload['transactionId'] : '';
		$claimed = is_array( $payload ) && isset( $payload['status'] ) ? (string) $payload['status'] : '';

		return new WP_REST_Response( self::process( $txid, $claimed ), 200 );
	}

	/**
	 * Look up the row, RE-CONFIRM via the status endpoint (the webhook is a
	 * hint, not the authority), then apply.
	 */
	public static function process( $txid, $claimed = '' ) {
		if ( '' === $txid ) {
			return array( 'ok' => true, 'handled' => false );
		}
		$row = MarvinPay_Transactions::find( $txid );
		if ( ! $row ) {
			return array( 'ok' => true, 'handled' => false ); // not ours — ack so the sender stops retrying
		}
		if ( 'SUCCESSFUL' === $row->status || 'FAILED' === $row->status ) {
			$claimed_norm = MarvinPay_Rules::normalize_status( $claimed );
			if ( '' !== $claimed && 'PENDING' !== $claimed_norm && $claimed_norm !== $row->status ) {
				// Upstream disagrees with our terminal record (e.g. a late
				// FAIL→SUCCESS correction). Terminal rows stay immutable —
				// surface the divergence for a human instead of acking silently.
				do_action( 'marvinpay_status_divergence', $row, $claimed_norm );
				if ( 'woocommerce' === $row->source && function_exists( 'wc_get_order' ) ) {
					$order = wc_get_order( (int) $row->source_ref );
					if ( $order ) {
						$order->add_order_note( sprintf(
							'Marvin Pay: upstream reports %s for ref %s but the local record is %s — manual reconciliation needed.',
							$claimed_norm,
							$row->transaction_id,
							$row->status
						) );
					}
				}
			}
			return array( 'ok' => true, 'handled' => false ); // dedupe: already terminal
		}

		// Shared per-transaction status-check throttle (same key as the browser
		// poll): an unauthenticated webhook flood cannot amplify into upstream
		// API spam — at most one get_status per transaction per window.
		$throttle_key = 'marvinpay_poll_' . $txid;
		if ( false !== get_transient( $throttle_key ) ) {
			return array( 'ok' => true, 'handled' => false ); // recently checked; poll/cron will resolve
		}
		set_transient( $throttle_key, 1, MarvinPay_Ajax::POLL_THROTTLE );

		try {
			$confirmed = MarvinPay_Plugin::client()->get_status( $txid );
		} catch ( MarvinPay_API_Exception $e ) {
			return array( 'ok' => true, 'handled' => false ); // cron will reconcile
		}

		$status  = isset( $confirmed['transaction_status'] ) ? $confirmed['transaction_status'] : '';
		$handled = MarvinPay_Fulfillment::apply( $row, $status, isset( $confirmed['partner_transaction_id'] ) ? $confirmed['partner_transaction_id'] : null );

		return array( 'ok' => true, 'handled' => $handled );
	}
}
