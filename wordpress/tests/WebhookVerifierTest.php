<?php

use PHPUnit\Framework\TestCase;

class WebhookVerifierTest extends TestCase {

	private $secret = 'whsec_test_123';
	private $body   = '{"event":"transaction.success","transactionId":"wpmp_abc","status":"SUCCESS"}';

	private function sign( $body, $secret ) {
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	public function test_valid_signature_passes() {
		$this->assertTrue( MarvinPay_Webhook_Verifier::verify( $this->body, $this->sign( $this->body, $this->secret ), $this->secret ) );
	}

	public function test_bare_hex_without_prefix_passes() {
		$sig = hash_hmac( 'sha256', $this->body, $this->secret );
		$this->assertTrue( MarvinPay_Webhook_Verifier::verify( $this->body, $sig, $this->secret ) );
	}

	public function test_uppercase_prefix_and_hex_pass() {
		$sig = 'SHA256=' . strtoupper( hash_hmac( 'sha256', $this->body, $this->secret ) );
		$this->assertTrue( MarvinPay_Webhook_Verifier::verify( $this->body, $sig, $this->secret ) );
	}

	public function test_wrong_secret_fails() {
		$this->assertFalse( MarvinPay_Webhook_Verifier::verify( $this->body, $this->sign( $this->body, 'other' ), $this->secret ) );
	}

	public function test_tampered_body_fails() {
		$sig = $this->sign( $this->body, $this->secret );
		$this->assertFalse( MarvinPay_Webhook_Verifier::verify( $this->body . ' ', $sig, $this->secret ) );
	}

	public function test_missing_or_malformed_signature_fails() {
		$this->assertFalse( MarvinPay_Webhook_Verifier::verify( $this->body, null, $this->secret ) );
		$this->assertFalse( MarvinPay_Webhook_Verifier::verify( $this->body, '', $this->secret ) );
		$this->assertFalse( MarvinPay_Webhook_Verifier::verify( $this->body, 'sha256=nothex', $this->secret ) );
		$this->assertFalse( MarvinPay_Webhook_Verifier::verify( $this->body, 'sha256=abcd', $this->secret ) );
	}

	public function test_empty_secret_always_fails() {
		$this->assertFalse( MarvinPay_Webhook_Verifier::verify( $this->body, $this->sign( $this->body, '' ), '' ) );
	}
}
