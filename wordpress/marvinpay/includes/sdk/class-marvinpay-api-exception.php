<?php
/**
 * Thrown for transport failures and non-2xx API responses.
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_API_Exception extends Exception {

	/** @var int HTTP status (0 for transport errors). */
	public $http_status;

	/** @var array|null decoded JSON error body when available. */
	public $body;

	public function __construct( $message, $http_status = 0, $body = null ) {
		parent::__construct( (string) $message );
		$this->http_status = (int) $http_status;
		$this->body        = is_array( $body ) ? $body : null;
	}
}
