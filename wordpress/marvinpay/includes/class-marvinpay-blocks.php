<?php
/**
 * Dynamic Gutenberg blocks. Rendering is delegated to MarvinPay_Shortcodes
 * so block and shortcode output can never drift. Editor scripts are plain JS
 * (no build step) registered with explicit dependencies.
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_Blocks {

	const BLOCKS = array(
		'pay-button'     => 'render_button_block',
		'donation-form'  => 'render_donation_block',
		'inline-form'    => 'render_form_block',
		'payment-status' => 'render_status_block',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$deps = array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' );
		foreach ( self::BLOCKS as $dir => $callback ) {
			$handle = 'marvinpay-block-' . $dir;
			wp_register_script( $handle, MARVINPAY_URL . 'blocks/' . $dir . '/edit.js', $deps, MARVINPAY_VERSION, true );
			register_block_type( MARVINPAY_DIR . 'blocks/' . $dir, array(
				'render_callback' => array( __CLASS__, $callback ),
			) );
		}
	}

	public static function render_button_block( $attributes ) {
		return MarvinPay_Shortcodes::render_button( array(
			'amount'      => isset( $attributes['amount'] ) ? $attributes['amount'] : '',
			'description' => isset( $attributes['description'] ) ? $attributes['description'] : '',
			'button_text' => ! empty( $attributes['buttonText'] ) ? $attributes['buttonText'] : __( 'Pay with Marvin Pay', 'marvinpay' ),
		) );
	}

	public static function render_donation_block( $attributes ) {
		return MarvinPay_Shortcodes::render_donation( array(
			'presets'     => isset( $attributes['presets'] ) ? $attributes['presets'] : '',
			'min'         => isset( $attributes['min'] ) ? $attributes['min'] : '',
			'max'         => isset( $attributes['max'] ) ? $attributes['max'] : '',
			'description' => isset( $attributes['description'] ) ? $attributes['description'] : '',
			'button_text' => ! empty( $attributes['buttonText'] ) ? $attributes['buttonText'] : __( 'Donate with Marvin Pay', 'marvinpay' ),
		) );
	}

	public static function render_form_block( $attributes ) {
		return MarvinPay_Shortcodes::render_form( array(
			'amount'      => isset( $attributes['amount'] ) ? $attributes['amount'] : '',
			'description' => isset( $attributes['description'] ) ? $attributes['description'] : '',
			'show_name'   => empty( $attributes['showName'] ) ? '0' : '1',
			'show_email'  => empty( $attributes['showEmail'] ) ? '0' : '1',
		) );
	}

	public static function render_status_block( $attributes ) {
		return MarvinPay_Shortcodes::render_status( array() );
	}
}
