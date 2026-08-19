<?php
/**
 * Plugin Name:       Marvin Pay
 * Plugin URI:        https://app.marvincorporate.co/plugins
 * Description:       Accept mobile-money payments through Marvin Pay — WooCommerce gateway plus pay-button, donation, inline-form and payment-status widgets.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Marvin Corporate
 * Author URI:        https://app.marvincorporate.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       marvinpay
 * Domain Path:       /languages
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'MARVINPAY_VERSION', '1.0.0' );
define( 'MARVINPAY_FILE', __FILE__ );
define( 'MARVINPAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'MARVINPAY_URL', plugin_dir_url( __FILE__ ) );

require_once MARVINPAY_DIR . 'includes/class-marvinpay-autoloader.php';
MarvinPay_Autoloader::register();

register_activation_hook( __FILE__, array( 'MarvinPay_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MarvinPay_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'MarvinPay_Plugin', 'instance' ) );

// WooCommerce High-Performance Order Storage compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
