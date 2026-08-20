<?php
/**
 * Minimal WordPress shims so the plugin's pure logic runs under plain PHPUnit.
 * NOT a WordPress environment — only what the tested code paths touch.
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MARVINPAY_VERSION', '1.0.0-test' );
define( 'MARVINPAY_FILE', dirname( __DIR__ ) . '/marvinpay/marvinpay.php' );
define( 'MARVINPAY_DIR', dirname( __DIR__ ) . '/marvinpay/' );
define( 'MARVINPAY_URL', 'https://example.test/wp-content/plugins/marvinpay/' );

// ── options / transients ────────────────────────────────────────────────
$GLOBALS['__mp_options']    = array();
$GLOBALS['__mp_transients'] = array();
$GLOBALS['__mp_actions']    = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__mp_options'] ) ? $GLOBALS['__mp_options'][ $key ] : $default;
}
function update_option( $key, $value ) {
	$GLOBALS['__mp_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['__mp_options'][ $key ] );
	return true;
}
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['__mp_transients'] ) ? $GLOBALS['__mp_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['__mp_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['__mp_transients'][ $key ] );
	return true;
}

// ── i18n / escaping / sanitization ──────────────────────────────────────
function __( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_html__( $text, $domain = null ) { return esc_html( $text ); }
function esc_attr__( $text, $domain = null ) { return esc_attr( $text ); }
function esc_url( $url ) { return (string) $url; }
function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
function sanitize_email( $email ) { return filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? (string) $email : ''; }
function absint( $n ) { return abs( (int) $n ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }

function shortcode_atts( $defaults, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $defaults as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}

function apply_filters( $tag, $value ) { return $value; }
function do_action( ...$args ) { $GLOBALS['__mp_actions'][] = $args; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function wp_create_nonce( $action = -1 ) { return 'test-nonce'; }

// ── WP_Error ────────────────────────────────────────────────────────────
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

// ── fake HTTP transport ─────────────────────────────────────────────────
$GLOBALS['__mp_http_queue'] = array();
$GLOBALS['__mp_http_log']   = array();

/** Queue the next wp_remote_request() response: array('response'=>array('code'=>200),'body'=>'…') or a WP_Error. */
function mp_http_queue( $response ) { $GLOBALS['__mp_http_queue'][] = $response; }
function mp_http_reset() {
	$GLOBALS['__mp_http_queue'] = array();
	$GLOBALS['__mp_http_log']   = array();
}
function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['__mp_http_log'][] = array( 'url' => $url, 'args' => $args );
	if ( empty( $GLOBALS['__mp_http_queue'] ) ) {
		return new WP_Error( 'http_error', 'no queued response' );
	}
	return array_shift( $GLOBALS['__mp_http_queue'] );
}
function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) && isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}
function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) && isset( $response['body'] ) ? (string) $response['body'] : '';
}

// ── load plugin classes through the real autoloader ─────────────────────
require_once MARVINPAY_DIR . 'includes/class-marvinpay-autoloader.php';
MarvinPay_Autoloader::register();

function wp_enqueue_style( $handle ) {}
function wp_enqueue_script( $handle ) {}
function add_shortcode( $tag, $callback ) {}
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }

// ── fake WooCommerce order for Fulfillment tests ────────────────────────
class MP_Fake_WC_Order {
	public $paid = false;
	public $needs_payment = true;
	public $status_updates = array();
	public $notes = array();
	public $payment_complete_refs = array();
	public function is_paid() { return $this->paid; }
	public function needs_payment() { return $this->needs_payment; }
	public function payment_complete( $ref = '' ) {
		$this->paid = true;
		$this->needs_payment = false;
		$this->payment_complete_refs[] = $ref;
	}
	public function update_status( $status, $note = '' ) { $this->status_updates[] = $status; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
}
$GLOBALS['__mp_wc_orders'] = array();
function wc_get_order( $id ) {
	return isset( $GLOBALS['__mp_wc_orders'][ (int) $id ] ) ? $GLOBALS['__mp_wc_orders'][ (int) $id ] : false;
}
