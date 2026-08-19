<?php

use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase {

	protected function setUp(): void {
		mp_http_reset();
	}

	private function client() {
		return new MarvinPay_Client( 'sk_test_key', 'https://api.example.test/api/' ); // trailing slash on purpose
	}

	private function ok( $body_array, $code = 200 ) {
		return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body_array ) );
	}

	public function test_collect_posts_snake_case_body_with_idempotency_default() {
		mp_http_queue( $this->ok( array( 'transaction_id' => 'wpmp_x', 'transaction_status' => 'PENDING' ), 202 ) );
		$result = $this->client()->collect( array(
			'country_code'   => 'CM',
			'currency'       => 'XAF',
			'amount'         => '5000',
			'mobile_number'  => '237600000001',
			'payment_method' => 'mtn_cm',
			'transaction_id' => 'wpmp_x',
		) );
		$this->assertSame( 'PENDING', $result['transaction_status'] );

		$call = $GLOBALS['__mp_http_log'][0];
		$this->assertSame( 'https://api.example.test/api/v1/payment/collect', $call['url'] );
		$this->assertSame( 'POST', $call['args']['method'] );
		$this->assertSame( 'wpmp_x', $call['args']['headers']['X-Idempotency-Key'] ); // defaults to transaction_id
		$this->assertSame( 'sk_test_key', $call['args']['headers']['X-API-KEY'] );
		$body = json_decode( $call['args']['body'], true );
		$this->assertSame( 5000, $body['amount'] ); // int, not "5000"
	}

	public function test_collect_uses_explicit_idempotency_key_when_given() {
		mp_http_queue( $this->ok( array( 'transaction_status' => 'PENDING' ), 202 ) );
		$this->client()->collect( array( 'transaction_id' => 'wpmp_x', 'amount' => 100 ), 'retry-key-9' );
		$this->assertSame( 'retry-key-9', $GLOBALS['__mp_http_log'][0]['args']['headers']['X-Idempotency-Key'] );
	}

	public function test_get_status_builds_escaped_url() {
		mp_http_queue( $this->ok( array( 'transaction_status' => 'SUCCESSFUL' ) ) );
		$out = $this->client()->get_status( 'wpmp_abc' );
		$this->assertSame( 'SUCCESSFUL', $out['transaction_status'] );
		$this->assertSame( 'https://api.example.test/api/v1/payment/status/wpmp_abc', $GLOBALS['__mp_http_log'][0]['url'] );
		$this->assertSame( 'GET', $GLOBALS['__mp_http_log'][0]['args']['method'] );
	}

	public function test_get_retries_once_on_5xx_then_succeeds() {
		mp_http_queue( array( 'response' => array( 'code' => 503 ), 'body' => 'oops' ) );
		mp_http_queue( $this->ok( array( 'transaction_status' => 'PENDING' ) ) );
		$out = $this->client()->get_status( 'wpmp_abc' );
		$this->assertSame( 'PENDING', $out['transaction_status'] );
		$this->assertCount( 2, $GLOBALS['__mp_http_log'] );
	}

	public function test_get_retries_once_on_transport_error() {
		mp_http_queue( new WP_Error( 'http_error', 'timed out' ) );
		mp_http_queue( $this->ok( array( 'transaction_status' => 'PENDING' ) ) );
		$out = $this->client()->get_status( 'wpmp_abc' );
		$this->assertSame( 'PENDING', $out['transaction_status'] );
	}

	public function test_post_never_retries() {
		mp_http_queue( array( 'response' => array( 'code' => 503 ), 'body' => '{"message":"upstream down"}' ) );
		mp_http_queue( $this->ok( array( 'transaction_status' => 'PENDING' ), 202 ) ); // must NOT be consumed
		try {
			$this->client()->collect( array( 'transaction_id' => 'wpmp_x', 'amount' => 100 ) );
			$this->fail( 'expected exception' );
		} catch ( MarvinPay_API_Exception $e ) {
			$this->assertSame( 'upstream down', $e->getMessage() );
			$this->assertSame( 503, $e->http_status );
		}
		$this->assertCount( 1, $GLOBALS['__mp_http_log'] );
	}

	public function test_error_message_extraction_falls_back_to_raw() {
		mp_http_queue( array( 'response' => array( 'code' => 400 ), 'body' => 'plain text failure' ) );
		try {
			$this->client()->get_status( 'wpmp_x' );
			$this->fail( 'expected exception' );
		} catch ( MarvinPay_API_Exception $e ) {
			$this->assertSame( 'HTTP 400: plain text failure', $e->getMessage() );
		}
	}

	public function test_2xx_non_array_body_returns_empty_array() {
		mp_http_queue( array( 'response' => array( 'code' => 200 ), 'body' => '' ) );
		$this->assertSame( array(), $this->client()->get_status( 'wpmp_x' ) );
	}

	public function test_collect_without_any_idempotency_source_throws_and_sends_nothing() {
		try {
			$this->client()->collect( array( 'amount' => 100 ) );
			$this->fail( 'expected exception' );
		} catch ( MarvinPay_API_Exception $e ) {
			$this->assertStringContainsString( 'idempotency', $e->getMessage() );
		}
		$this->assertCount( 0, $GLOBALS['__mp_http_log'] ); // nothing reached the wire
	}

	public function test_get_payment_methods_uppercases_country_in_url() {
		mp_http_queue( $this->ok( array( 'mtn_cm', 'orange_cm' ) ) );
		$out = $this->client()->get_payment_methods( 'cm' );
		$this->assertSame( array( 'mtn_cm', 'orange_cm' ), $out );
		$this->assertSame( 'https://api.example.test/api/v1/payment/payment-methods/CM', $GLOBALS['__mp_http_log'][0]['url'] );
	}

	public function test_get_retries_once_on_429() {
		mp_http_queue( array( 'response' => array( 'code' => 429 ), 'body' => '' ) );
		mp_http_queue( $this->ok( array( 'transaction_status' => 'PENDING' ) ) );
		$out = $this->client()->get_status( 'wpmp_abc' );
		$this->assertSame( 'PENDING', $out['transaction_status'] );
		$this->assertCount( 2, $GLOBALS['__mp_http_log'] );
	}
}
