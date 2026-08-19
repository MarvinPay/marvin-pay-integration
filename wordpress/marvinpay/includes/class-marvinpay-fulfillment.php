<?php
/**
 * The single place an authoritative transaction outcome is applied:
 * row transition (atomic, forward-only) → WooCommerce order sync → hooks.
 * Callers must pass a status they confirmed via GET /v1/payment/status —
 * never a raw webhook status.
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_Fulfillment {

	/**
	 * @param object      $row        row from MarvinPay_Transactions::find()
	 * @param string      $new_status raw status (any wording; normalized here)
	 * @param string|null $partner_id operator/partner transaction id when known
	 * @return bool true when a transition actually happened
	 */
	public static function apply( $row, $new_status, $partner_id = null ) {
		$new_status = MarvinPay_Rules::normalize_status( $new_status );
		if ( 'PENDING' === $new_status ) {
			return false;
		}
		$extra = array();
		if ( null !== $partner_id && '' !== $partner_id ) {
			$extra['partner_transaction_id'] = (string) $partner_id;
		}
		if ( ! MarvinPay_Transactions::transition( $row->transaction_id, $new_status, $extra ) ) {
			return false; // already terminal (dedupe) or unknown row
		}
		$row->status = $new_status;

		if ( 'woocommerce' === $row->source ) {
			self::sync_woo_order( $row );
		}

		do_action(
			'SUCCESSFUL' === $new_status ? 'marvinpay_payment_completed' : 'marvinpay_payment_failed',
			$row
		);
		return true;
	}

	/**
	 * @internal Public only so the unit tests can exercise the branches directly.
	 */
	public static function sync_woo_order( $row ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( (int) $row->source_ref );
		if ( ! $order ) {
			return;
		}
		if ( 'SUCCESSFUL' === $row->status ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $row->transaction_id );
				$order->add_order_note(
					sprintf( 'Marvin Pay: payment confirmed (ref %s).', $row->transaction_id )
				);
			} else {
				// A second successful attempt on an already-paid order: money was
				// collected twice — surface it, a refund may be required.
				$order->add_order_note(
					sprintf( 'Marvin Pay: SECOND successful payment (ref %s) received for an already-paid order — a refund may be required.', $row->transaction_id )
				);
			}
		} elseif ( $order->needs_payment() ) {
			$order->update_status( 'failed', __( 'Marvin Pay: payment failed.', 'marvinpay' ) );
		} else {
			// Never downgrade a paid order because a stale sibling attempt failed.
			$order->add_order_note(
				sprintf( 'Marvin Pay: attempt %s failed after the order was already paid by another attempt — no status change.', $row->transaction_id )
			);
		}
	}
}
