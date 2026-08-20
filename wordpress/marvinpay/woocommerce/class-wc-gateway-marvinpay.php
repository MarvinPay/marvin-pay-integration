<?php
/**
 * WooCommerce payment gateway. Order placement stays standard; the actual
 * mobile-money charge happens on the order-received page through the same
 * signed-config widget flow every other surface uses.
 */

defined( 'ABSPATH' ) || exit;

class WC_Gateway_MarvinPay extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'marvinpay';
		$this->method_title       = 'Marvin Pay';
		$this->method_description = __( 'Mobile-money payments via Marvin Pay. API credentials are configured under Settings → Marvin Pay.', 'marvinpay' );
		$this->has_fields         = false;

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'marvinpay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Marvin Pay mobile money', 'marvinpay' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'   => __( 'Title', 'marvinpay' ),
				'type'    => 'text',
				'default' => __( 'Mobile Money (Marvin Pay)', 'marvinpay' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'marvinpay' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with MTN, Orange, Moov and other mobile-money providers. You will confirm the payment on your phone.', 'marvinpay' ),
			),
		);
	}

	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( ! MarvinPay_Settings::is_configured() ) {
			return false;
		}
		if ( get_woocommerce_currency() !== MarvinPay_Settings::currency() ) {
			return false; // store currency must match the account country's currency
		}
		return parent::is_available();
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// The ORDER is the amount authority; it must be a valid whole amount.
		$total  = (string) $order->get_total();
		$amount = MarvinPay_Rules::validate_amount( $total );
		if ( false === $amount ) {
			wc_add_notice( __( 'The order total must be a whole amount between 100 and 500,000 to pay with Marvin Pay.', 'marvinpay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_status( 'pending', __( 'Awaiting Marvin Pay mobile-money confirmation.', 'marvinpay' ) );
		wc_maybe_reduce_stock_levels( $order_id );

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_order_received_url(),
		);
	}

	/**
	 * Order-received page: auto-open the payment widget while the order
	 * still needs payment. Hooked on woocommerce_thankyou_marvinpay.
	 */
	public static function render_thankyou_widget( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'marvinpay' !== $order->get_payment_method() ) {
			return;
		}
		if ( ! $order->needs_payment() ) {
			echo '<p>' . esc_html__( 'Payment received — thank you!', 'marvinpay' ) . '</p>';
			return;
		}

		$total  = (string) $order->get_total();
		$amount = MarvinPay_Rules::validate_amount( $total );
		if ( false === $amount ) {
			return;
		}

		wp_enqueue_style( 'marvinpay' );
		wp_enqueue_script( 'marvinpay' );

		$config = array(
			'type'        => 'woocommerce',
			'amount'      => $amount,
			'min'         => 0,
			'max'         => 0,
			'presets'     => array(),
			'description' => sprintf( 'Order #%s', $order->get_order_number() ),
			'show_name'   => 0,
			'show_email'  => 0,
			'source_ref'  => (string) $order->get_id(),
			'order_key'   => (string) $order->get_order_key(),
		);
		$json = MarvinPay_Widget_Config::encode( $config );
		$sig  = MarvinPay_Widget_Config::sign( $json );

		echo '<div class="marvinpay-widget" data-widget="auto"'
			. ' data-config="' . esc_attr( $json ) . '"'
			. ' data-sig="' . esc_attr( $sig ) . '">'
			. '<button type="button" class="marvinpay-open marvinpay-btn marvinpay-btn-primary">'
			. esc_html__( 'Pay now with mobile money', 'marvinpay' )
			. '</button></div>';
	}
}
