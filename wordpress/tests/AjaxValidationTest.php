<?php

use PHPUnit\Framework\TestCase;

class AjaxValidationTest extends TestCase {

	private $methods = array( 'mtn_cm', 'orange_cm' );

	public function test_valid_payer_input_is_normalized() {
		$out = MarvinPay_Ajax::validate_payer_input( array(
			'payment_method' => 'mtn_cm',
			'mobile_number'  => '+237 620-29-70-91',
			'payer_name'     => '  Ada Ada ',
			'payer_email'    => 'ada@example.com',
		), $this->methods );
		$this->assertIsArray( $out );
		$this->assertSame( '237620297091', $out['mobile_number'] );
		$this->assertSame( 'mtn_cm', $out['payment_method'] );
		$this->assertSame( 'Ada Ada', $out['payer_name'] );
		$this->assertSame( 'ada@example.com', $out['payer_email'] );
	}

	public function test_unknown_method_rejected() {
		$out = MarvinPay_Ajax::validate_payer_input( array( 'payment_method' => 'card_visa', 'mobile_number' => '237620297091' ), $this->methods );
		$this->assertInstanceOf( 'WP_Error', $out );
	}

	public function test_bad_mobile_rejected() {
		foreach ( array( '', '12345', '12345678901234567890', 'not-a-number' ) as $bad ) {
			$out = MarvinPay_Ajax::validate_payer_input( array( 'payment_method' => 'mtn_cm', 'mobile_number' => $bad ), $this->methods );
			$this->assertInstanceOf( 'WP_Error', $out, "mobile '$bad' should be rejected" );
		}
	}

	public function test_invalid_email_becomes_empty_not_error() {
		$out = MarvinPay_Ajax::validate_payer_input( array( 'payment_method' => 'mtn_cm', 'mobile_number' => '237620297091', 'payer_email' => 'nope' ), $this->methods );
		$this->assertSame( '', $out['payer_email'] );
	}

	public function test_fixed_amount_must_match_exactly() {
		$config = array( 'type' => 'button', 'amount' => 5000, 'min' => 0, 'max' => 0 );
		$this->assertSame( 5000, MarvinPay_Ajax::resolve_amount( $config, '5000' ) );
		$this->assertInstanceOf( 'WP_Error', MarvinPay_Ajax::resolve_amount( $config, 100 ) );
		$this->assertInstanceOf( 'WP_Error', MarvinPay_Ajax::resolve_amount( $config, 4999 ) );
	}

	public function test_open_amount_bounded_by_config_and_global_limits() {
		$config = array( 'type' => 'donation', 'amount' => 0, 'min' => 500, 'max' => 10000 );
		$this->assertSame( 500, MarvinPay_Ajax::resolve_amount( $config, 500 ) );
		$this->assertSame( 10000, MarvinPay_Ajax::resolve_amount( $config, 10000 ) );
		$this->assertInstanceOf( 'WP_Error', MarvinPay_Ajax::resolve_amount( $config, 499 ) );
		$this->assertInstanceOf( 'WP_Error', MarvinPay_Ajax::resolve_amount( $config, 10001 ) );
	}

	public function test_open_amount_with_no_config_bounds_uses_global_limits() {
		$config = array( 'type' => 'inline', 'amount' => 0, 'min' => 0, 'max' => 0 );
		$this->assertSame( 100, MarvinPay_Ajax::resolve_amount( $config, 100 ) );
		$this->assertSame( 500000, MarvinPay_Ajax::resolve_amount( $config, 500000 ) );
		$this->assertInstanceOf( 'WP_Error', MarvinPay_Ajax::resolve_amount( $config, 99 ) );
		$this->assertInstanceOf( 'WP_Error', MarvinPay_Ajax::resolve_amount( $config, 500001 ) );
	}

	public function test_non_integer_amount_rejected() {
		$config = array( 'type' => 'donation', 'amount' => 0, 'min' => 0, 'max' => 0 );
		$this->assertInstanceOf( 'WP_Error', MarvinPay_Ajax::resolve_amount( $config, '50.5' ) );
		$this->assertInstanceOf( 'WP_Error', MarvinPay_Ajax::resolve_amount( $config, '' ) );
	}
}
