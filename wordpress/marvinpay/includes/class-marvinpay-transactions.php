<?php
/**
 * Custom table for widget/checkout transactions. Rows only move forward:
 * PENDING → SUCCESSFUL | FAILED | ABANDONED (ABANDONED may later be corrected).
 * Allowed moves live in MarvinPay_Rules::allowed_sources() — the UPDATE here
 * enforces them atomically via its WHERE clause.
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_Transactions {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'marvinpay_transactions';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			transaction_id VARCHAR(64) NOT NULL,
			source VARCHAR(20) NOT NULL,
			source_ref VARCHAR(64) NULL,
			amount BIGINT UNSIGNED NOT NULL,
			currency CHAR(3) NOT NULL,
			country CHAR(2) NOT NULL,
			payment_method VARCHAR(32) NOT NULL,
			mobile_number VARCHAR(24) NOT NULL,
			payer_name VARCHAR(120) NULL,
			payer_email VARCHAR(190) NULL,
			description VARCHAR(255) NULL,
			status VARCHAR(12) NOT NULL DEFAULT 'PENDING',
			mode VARCHAR(4) NOT NULL DEFAULT 'live',
			partner_transaction_id VARCHAR(64) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY transaction_id (transaction_id),
			KEY status_updated (status, updated_at)
		) {$charset};" );
	}

	public static function insert( array $data ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return false !== $wpdb->insert(
			self::table(),
			array(
				'transaction_id' => (string) $data['transaction_id'],
				'source'         => (string) $data['source'],
				'source_ref'     => isset( $data['source_ref'] ) ? (string) $data['source_ref'] : '',
				'amount'         => (int) $data['amount'],
				'currency'       => (string) $data['currency'],
				'country'        => (string) $data['country'],
				'payment_method' => (string) $data['payment_method'],
				'mobile_number'  => (string) $data['mobile_number'],
				'payer_name'     => isset( $data['payer_name'] ) ? (string) $data['payer_name'] : '',
				'payer_email'    => isset( $data['payer_email'] ) ? (string) $data['payer_email'] : '',
				'description'    => isset( $data['description'] ) ? (string) $data['description'] : '',
				'status'         => 'PENDING',
				'mode'           => (string) $data['mode'],
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public static function find( $transaction_id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE transaction_id = %s", $transaction_id ) );
		return $row ? $row : null;
	}

	public static function transition( $transaction_id, $to, array $extra = array() ) {
		global $wpdb;
		$allowed = MarvinPay_Rules::allowed_sources( $to );
		if ( empty( $allowed ) ) {
			return false;
		}
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
		$set          = 'status = %s, updated_at = %s';
		$params       = array( $to, current_time( 'mysql', true ) );
		if ( ! empty( $extra['partner_transaction_id'] ) ) {
			$set     .= ', partner_transaction_id = %s';
			$params[] = (string) $extra['partner_transaction_id'];
		}
		$params[] = $transaction_id;
		$params   = array_merge( $params, $allowed );
		$sql      = "UPDATE {$table} SET {$set} WHERE transaction_id = %s AND status IN ({$placeholders})";
		return (bool) $wpdb->query( $wpdb->prepare( $sql, $params ) );
	}

	public static function pending_older_than( $seconds, $limit ) {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $seconds );
		$rows   = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = 'PENDING' AND updated_at < %s ORDER BY updated_at ASC LIMIT %d",
			$cutoff,
			(int) $limit
		) );
		return is_array( $rows ) ? $rows : array();
	}
}
