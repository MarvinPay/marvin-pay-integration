<?php
/**
 * The four widget renderers. Shortcodes and Gutenberg blocks share these —
 * blocks call the same render_* methods, so markup can never drift.
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_Shortcodes {

	public static function init() {
		add_shortcode( 'marvinpay_button', array( __CLASS__, 'render_button' ) );
		add_shortcode( 'marvinpay_donation', array( __CLASS__, 'render_donation' ) );
		add_shortcode( 'marvinpay_form', array( __CLASS__, 'render_form' ) );
		add_shortcode( 'marvinpay_status', array( __CLASS__, 'render_status' ) );
	}

	// ── widgets ─────────────────────────────────────────────────────────

	public static function render_button( $atts ) {
		$atts = shortcode_atts( array(
			'amount'      => '',
			'description' => '',
			'button_text' => __( 'Pay with Marvin Pay', 'marvinpay' ),
		), $atts, 'marvinpay_button' );

		if ( ! MarvinPay_Settings::is_configured() ) {
			return self::config_error( __( 'Marvin Pay is not configured — set the API key under Settings → Marvin Pay.', 'marvinpay' ) );
		}
		$amount = MarvinPay_Rules::validate_amount( $atts['amount'] );
		if ( false === $amount ) {
			return self::config_error( __( 'marvinpay_button needs a whole-number amount between 100 and 500,000.', 'marvinpay' ) );
		}

		$config = array(
			'type'        => 'button',
			'amount'      => $amount,
			'min'         => 0,
			'max'         => 0,
			'presets'     => array(),
			'description' => sanitize_text_field( $atts['description'] ),
			'show_name'   => 0,
			'show_email'  => 1,
		);
		return self::widget_div( 'modal', $config, sanitize_text_field( $atts['button_text'] ) );
	}

	public static function render_donation( $atts ) {
		$atts = shortcode_atts( array(
			'presets'     => '',
			'min'         => '',
			'max'         => '',
			'description' => '',
			'button_text' => __( 'Donate with Marvin Pay', 'marvinpay' ),
		), $atts, 'marvinpay_donation' );

		if ( ! MarvinPay_Settings::is_configured() ) {
			return self::config_error( __( 'Marvin Pay is not configured — set the API key under Settings → Marvin Pay.', 'marvinpay' ) );
		}

		$presets = array();
		foreach ( explode( ',', (string) $atts['presets'] ) as $p ) {
			$valid = MarvinPay_Rules::validate_amount( trim( $p ) );
			if ( false !== $valid ) {
				$presets[] = $valid;
			}
		}

		$config = array(
			'type'        => 'donation',
			'amount'      => 0,
			'min'         => false !== MarvinPay_Rules::validate_amount( trim( (string) $atts['min'] ) ) ? (int) trim( $atts['min'] ) : 0,
			'max'         => false !== MarvinPay_Rules::validate_amount( trim( (string) $atts['max'] ) ) ? (int) trim( $atts['max'] ) : 0,
			'presets'     => $presets,
			'description' => sanitize_text_field( $atts['description'] ),
			'show_name'   => 1,
			'show_email'  => 1,
		);
		return self::widget_div( 'modal', $config, sanitize_text_field( $atts['button_text'] ) );
	}

	public static function render_form( $atts ) {
		$atts = shortcode_atts( array(
			'amount'      => '',
			'description' => '',
			'show_name'   => '1',
			'show_email'  => '1',
		), $atts, 'marvinpay_form' );

		if ( ! MarvinPay_Settings::is_configured() ) {
			return self::config_error( __( 'Marvin Pay is not configured — set the API key under Settings → Marvin Pay.', 'marvinpay' ) );
		}

		$amount = 0;
		if ( '' !== trim( (string) $atts['amount'] ) ) {
			$amount = MarvinPay_Rules::validate_amount( $atts['amount'] );
			if ( false === $amount ) {
				return self::config_error( __( 'marvinpay_form amount must be a whole number between 100 and 500,000 (or empty for payer-entered).', 'marvinpay' ) );
			}
		}

		$config = array(
			'type'        => 'inline',
			'amount'      => $amount,
			'min'         => 0,
			'max'         => 0,
			'presets'     => array(),
			'description' => sanitize_text_field( $atts['description'] ),
			'show_name'   => empty( $atts['show_name'] ) || '0' === (string) $atts['show_name'] ? 0 : 1,
			'show_email'  => empty( $atts['show_email'] ) || '0' === (string) $atts['show_email'] ? 0 : 1,
		);
		return self::widget_div( 'inline', $config, '' );
	}

	public static function render_status( $atts ) {
		self::enqueue();
		$ref = isset( $_GET['mp_ref'] ) ? sanitize_text_field( (string) $_GET['mp_ref'] ) : '';
		if ( ! preg_match( '/^wpmp_[0-9a-f]{20}$/', $ref ) ) {
			return '<div class="marvinpay-receipt">' . esc_html__( 'No payment reference provided.', 'marvinpay' ) . '</div>';
		}
		$row = MarvinPay_Transactions::find( $ref );
		if ( ! $row ) {
			return '<div class="marvinpay-receipt">' . esc_html__( 'Payment reference not found.', 'marvinpay' ) . '</div>';
		}
		return self::render_status_row( $row );
	}

	/**
	 * Receipt for one row. Deliberately excludes payer name/email —
	 * knowing the link is not authentication.
	 */
	public static function render_status_row( $row ) {
		$status = strtoupper( (string) $row->status );
		$labels = array(
			'SUCCESSFUL' => __( 'Successful', 'marvinpay' ),
			'FAILED'     => __( 'Failed', 'marvinpay' ),
			'PENDING'    => __( 'Pending', 'marvinpay' ),
			'ABANDONED'  => __( 'Unconfirmed', 'marvinpay' ),
		);
		$label = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;

		$html  = '<div class="marvinpay-receipt">';
		$html .= '<span class="marvinpay-badge marvinpay-badge-' . esc_attr( strtolower( $status ) ) . '">' . esc_html( $label ) . '</span>';
		$html .= '<dl>';
		$html .= '<dt>' . esc_html__( 'Amount', 'marvinpay' ) . '</dt><dd>' . esc_html( number_format_i18n( (int) $row->amount ) . ' ' . $row->currency ) . '</dd>';
		$html .= '<dt>' . esc_html__( 'Method', 'marvinpay' ) . '</dt><dd>' . esc_html( strtoupper( str_replace( '_', ' ', (string) $row->payment_method ) ) ) . '</dd>';
		$html .= '<dt>' . esc_html__( 'Mobile', 'marvinpay' ) . '</dt><dd>' . esc_html( MarvinPay_Rules::mask_mobile( $row->mobile_number ) ) . '</dd>';
		$html .= '<dt>' . esc_html__( 'Reference', 'marvinpay' ) . '</dt><dd><code>' . esc_html( $row->transaction_id ) . '</code></dd>';
		$html .= '<dt>' . esc_html__( 'Date', 'marvinpay' ) . '</dt><dd>' . esc_html( (string) $row->created_at ) . ' UTC</dd>';
		$html .= '</dl></div>';
		return $html;
	}

	// ── shared plumbing ─────────────────────────────────────────────────

	private static function widget_div( $mode, array $config, $button_text ) {
		self::enqueue();
		$json = MarvinPay_Widget_Config::encode( $config );
		$sig  = MarvinPay_Widget_Config::sign( $json );

		$html = '<div class="marvinpay-widget" data-widget="' . esc_attr( $mode ) . '"'
			. ' data-config="' . esc_attr( $json ) . '"'
			. ' data-sig="' . esc_attr( $sig ) . '">';
		if ( '' !== $button_text ) {
			$html .= '<button type="button" class="marvinpay-open marvinpay-btn marvinpay-btn-primary">' . esc_html( $button_text ) . '</button>';
		}
		$html .= '</div>';
		return $html;
	}

	private static function config_error( $message ) {
		// the error box should be styled even when it's the only widget on the page
		self::enqueue();
		return '<div class="marvinpay-config-error">' . esc_html( $message ) . '</div>';
	}

	private static function enqueue() {
		wp_enqueue_style( 'marvinpay' );
		wp_enqueue_script( 'marvinpay' );
	}
}
