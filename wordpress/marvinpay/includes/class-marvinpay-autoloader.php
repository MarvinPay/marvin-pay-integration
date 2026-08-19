<?php
/**
 * Maps MarvinPay_* (and WC_Gateway_MarvinPay) class names to plugin files.
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_Autoloader {

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	public static function load( $class ) {
		if ( 0 !== strpos( $class, 'MarvinPay_' ) && 'WC_Gateway_MarvinPay' !== $class ) {
			return;
		}
		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		foreach ( array( 'includes/', 'includes/sdk/', 'woocommerce/' ) as $dir ) {
			$path = MARVINPAY_DIR . $dir . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
