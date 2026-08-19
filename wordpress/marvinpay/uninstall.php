<?php
/**
 * Uninstall cleanup. Destructive parts run ONLY when the merchant opted in
 * via the "delete on uninstall" setting.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'marvinpay_settings', array() );

if ( is_array( $settings ) && ! empty( $settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	$table = $wpdb->prefix . 'marvinpay_transactions';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	delete_option( 'marvinpay_settings' );
	delete_option( 'woocommerce_marvinpay_settings' );
}

// Transients are safe to clear unconditionally.
if ( function_exists( 'delete_transient' ) ) {
	foreach ( array( 'live', 'test' ) as $mode ) {
		foreach ( array( 'CM', 'GA', 'CI', 'SN', 'BJ', 'TG', 'ML' ) as $cc ) {
			delete_transient( 'marvinpay_methods_' . $mode . '_' . $cc );
		}
	}
}
