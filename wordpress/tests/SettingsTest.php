<?php

use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

	protected function setUp(): void {
		mp_http_reset();
		$GLOBALS['__mp_transients'] = array();
		update_option( MarvinPay_Settings::OPTION, array(
			'mode'          => 'live',
			'live_api_key'  => 'sk_live_x',
			'live_base_url' => 'https://api.example.test/api',
			'country'       => 'CM',
		) );
	}

	public function test_live_list_is_cached_and_reused_without_http() {
		mp_http_queue( array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'mtn_cm', 'orange_cm' ) ) ) );
		$this->assertSame( array( 'mtn_cm', 'orange_cm' ), MarvinPay_Settings::payment_methods() );
		$this->assertCount( 1, $GLOBALS['__mp_http_log'] );
		$this->assertSame( array( 'mtn_cm', 'orange_cm' ), MarvinPay_Settings::payment_methods() );
		$this->assertCount( 1, $GLOBALS['__mp_http_log'] ); // cache hit — no second call
	}

	public function test_api_failure_returns_fallback_and_negative_caches() {
		mp_http_queue( array( 'response' => array( 'code' => 500 ), 'body' => 'down' ) );
		mp_http_queue( array( 'response' => array( 'code' => 500 ), 'body' => 'down' ) ); // GET retries once
		$this->assertSame( MarvinPay_Rules::FALLBACK_METHODS['CM'], MarvinPay_Settings::payment_methods() );
		$calls = count( $GLOBALS['__mp_http_log'] );
		$this->assertSame( MarvinPay_Rules::FALLBACK_METHODS['CM'], MarvinPay_Settings::payment_methods() );
		$this->assertCount( $calls, $GLOBALS['__mp_http_log'] ); // negative cache — no new HTTP
	}
}
