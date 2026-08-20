<?php
/**
 * Plugin core: singleton wiring, activation lifecycle, shared client factory.
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init() {
		load_plugin_textdomain( 'marvinpay', false, dirname( plugin_basename( MARVINPAY_FILE ) ) . '/languages' );
		MarvinPay_Settings::init();
		MarvinPay_Ajax::init();
		MarvinPay_Shortcodes::init();
		MarvinPay_Blocks::init();
		MarvinPay_Webhook::init();
		MarvinPay_Cron::init();
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		if ( class_exists( 'WooCommerce' ) ) {
			$this->init_woocommerce();
		}
	}

	/** One client per call, configured from settings. */
	public static function client() {
		return new MarvinPay_Client( MarvinPay_Settings::api_key(), MarvinPay_Settings::base_url() );
	}

	public static function activate() {
		MarvinPay_Transactions::create_table();
		MarvinPay_Cron::schedule();
	}

	public static function deactivate() {
		MarvinPay_Cron::unschedule();
	}

	/** Registered (not enqueued) — widget renderers enqueue on demand. */
	public function register_assets() {
		wp_register_style( 'marvinpay', MARVINPAY_URL . 'assets/css/marvinpay.css', array(), MARVINPAY_VERSION );
		wp_register_script( 'marvinpay', MARVINPAY_URL . 'assets/js/marvinpay.js', array(), MARVINPAY_VERSION, true );

		$success_page = (int) MarvinPay_Settings::get( 'success_page' );
		$failure_page = (int) MarvinPay_Settings::get( 'failure_page' );

		wp_localize_script( 'marvinpay', 'MarvinPayData', array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( MarvinPay_Ajax::NONCE_ACTION ),
			'methods'    => MarvinPay_Settings::is_configured() ? MarvinPay_Settings::payment_methods() : array(),
			'currency'   => MarvinPay_Settings::currency(),
			'minAmount'  => MarvinPay_Rules::MIN_AMOUNT,
			'maxAmount'  => MarvinPay_Rules::MAX_AMOUNT,
			'successUrl' => $success_page ? get_permalink( $success_page ) : '',
			'failureUrl' => $failure_page ? get_permalink( $failure_page ) : '',
			'i18n'       => array(
				'payTitle'      => __( 'Pay with Marvin Pay', 'marvinpay' ),
				'amountLabel'   => __( 'Amount', 'marvinpay' ),
				'nameLabel'     => __( 'Your name (optional)', 'marvinpay' ),
				'emailLabel'    => __( 'Email for receipt (optional)', 'marvinpay' ),
				'mobileLabel'   => __( 'Mobile money number', 'marvinpay' ),
				'methodLabel'   => __( 'Payment method', 'marvinpay' ),
				'payNow'        => __( 'Pay now', 'marvinpay' ),
				'cancel'        => __( 'Cancel', 'marvinpay' ),
				'close'         => __( 'Close', 'marvinpay' ),
				'confirmPrompt' => __( 'Confirm the payment prompt on your phone…', 'marvinpay' ),
				'waiting'       => __( 'Waiting for confirmation — this can take a minute.', 'marvinpay' ),
				'successTitle'  => __( 'Payment received', 'marvinpay' ),
				'successBody'   => __( 'Thank you! Your payment was confirmed.', 'marvinpay' ),
				'failedTitle'   => __( 'Payment failed', 'marvinpay' ),
				'tryAgain'      => __( 'Try again', 'marvinpay' ),
				'timeoutBody'   => __( 'Still pending. If you confirmed on your phone, the payment will be recorded automatically — you can check later with your reference.', 'marvinpay' ),
				'reference'     => __( 'Reference', 'marvinpay' ),
				'invalidAmount' => __( 'Enter a whole amount between 100 and 500,000.', 'marvinpay' ),
				'invalidMobile' => __( 'Enter a valid mobile money number.', 'marvinpay' ),
				'genericError'  => __( 'Something went wrong. Please try again.', 'marvinpay' ),
			),
		) );
	}

	private function init_woocommerce() {
		add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
			$gateways[] = 'WC_Gateway_MarvinPay';
			return $gateways;
		} );
		add_action( 'woocommerce_thankyou_marvinpay', array( 'WC_Gateway_MarvinPay', 'render_thankyou_widget' ) );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_wc_blocks_integration' ) );
	}

	/** Block-checkout integration (Task 16 ships the class + JS). */
	public function register_wc_blocks_integration() {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		if ( ! class_exists( 'MarvinPay_WC_Blocks' ) ) {
			return; // arrives in Task 16
		}
		add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
			$registry->register( new MarvinPay_WC_Blocks() );
		} );
	}
}
