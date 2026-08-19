<?php

use PHPUnit\Framework\TestCase;

class WidgetConfigTest extends TestCase {

	public function test_sign_verify_roundtrip() {
		$json = MarvinPay_Widget_Config::encode( array( 'type' => 'button', 'amount' => 5000, 'description' => 'Consultation' ) );
		$sig  = MarvinPay_Widget_Config::sign( $json );
		$this->assertTrue( MarvinPay_Widget_Config::verify( $json, $sig ) );
	}

	public function test_tampered_config_fails() {
		$json = MarvinPay_Widget_Config::encode( array( 'type' => 'button', 'amount' => 5000 ) );
		$sig  = MarvinPay_Widget_Config::sign( $json );
		$evil = str_replace( '5000', '100', $json );
		$this->assertFalse( MarvinPay_Widget_Config::verify( $evil, $sig ) );
	}

	public function test_missing_or_garbage_signature_fails() {
		$json = MarvinPay_Widget_Config::encode( array( 'type' => 'button', 'amount' => 5000 ) );
		$this->assertFalse( MarvinPay_Widget_Config::verify( $json, null ) );
		$this->assertFalse( MarvinPay_Widget_Config::verify( $json, '' ) );
		$this->assertFalse( MarvinPay_Widget_Config::verify( $json, 'not-a-signature' ) );
	}

	public function test_decode_returns_null_for_invalid_json() {
		$this->assertNull( MarvinPay_Widget_Config::decode( '{broken' ) );
		$this->assertNull( MarvinPay_Widget_Config::decode( '"a string"' ) );
		$this->assertSame( array( 'type' => 'button' ), MarvinPay_Widget_Config::decode( '{"type":"button"}' ) );
	}
}
