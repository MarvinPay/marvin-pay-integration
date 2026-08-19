<?php
/**
 * WooCommerce Blocks (checkout block) payment method registration.
 * The block only needs to offer the choice — the charge happens on the
 * order-received page exactly like classic checkout.
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class MarvinPay_WC_Blocks extends AbstractPaymentMethodType {

	protected $name = 'marvinpay';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_marvinpay_settings', array() );
	}

	public function is_active() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['marvinpay'] ) && $gateways['marvinpay']->is_available();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'marvinpay-wc-blocks',
			MARVINPAY_URL . 'assets/js/wc-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
			MARVINPAY_VERSION,
			true
		);
		return array( 'marvinpay-wc-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => isset( $this->settings['title'] ) && '' !== $this->settings['title']
				? $this->settings['title']
				: __( 'Mobile Money (Marvin Pay)', 'marvinpay' ),
			'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
			'supports'    => array( 'products' ),
		);
	}
}
