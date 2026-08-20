<?php
/**
 * Reconciliation poller. Webhooks are best-effort; this cron is the
 * authority that eventually resolves every PENDING transaction.
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_Cron {

	const HOOK        = 'marvinpay_reconcile';
	const INTERVAL    = 'marvinpay_5min';
	const MIN_AGE     = 120;   // only touch rows quiet for ≥2 min (the browser is still polling fresher ones)
	const BATCH       = 25;    // per run — respect the API rate limit
	const ABANDON_AGE = DAY_IN_SECONDS;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_interval' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function add_interval( $schedules ) {
		$schedules[ self::INTERVAL ] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 minutes (Marvin Pay reconciliation)', 'marvinpay' ),
		);
		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, self::INTERVAL, self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run() {
		if ( ! MarvinPay_Settings::is_configured() ) {
			return;
		}
		$rows = MarvinPay_Transactions::pending_older_than( self::MIN_AGE, self::BATCH );
		foreach ( $rows as $row ) {
			$created = strtotime( $row->created_at . ' UTC' );
			if ( false !== $created && $created < time() - self::ABANDON_AGE ) {
				self::abandon( $row );
				continue;
			}
			try {
				$confirmed = MarvinPay_Plugin::client()->get_status( $row->transaction_id );
			} catch ( MarvinPay_API_Exception $e ) {
				continue; // next run retries this row
			}
			$status = isset( $confirmed['transaction_status'] ) ? $confirmed['transaction_status'] : '';
			if ( 'PENDING' === MarvinPay_Rules::normalize_status( $status ) ) {
				// Still pending: bump updated_at is NOT done — leaving it lets the
				// row age toward the 24h abandon cutoff based on created_at.
				continue;
			}
			MarvinPay_Fulfillment::apply( $row, $status, isset( $confirmed['partner_transaction_id'] ) ? $confirmed['partner_transaction_id'] : null );
		}
	}

	private static function abandon( $row ) {
		if ( ! MarvinPay_Transactions::transition( $row->transaction_id, 'ABANDONED' ) ) {
			return;
		}
		if ( 'woocommerce' === $row->source && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $row->source_ref );
			if ( $order ) {
				$order->add_order_note( __( 'Marvin Pay: payment still unconfirmed after 24 hours — marked abandoned locally. A later confirmation will still be applied.', 'marvinpay' ) );
			}
		}
	}
}
