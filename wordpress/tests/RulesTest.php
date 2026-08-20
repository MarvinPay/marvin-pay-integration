<?php

use PHPUnit\Framework\TestCase;

class RulesTest extends TestCase {

	public function test_currency_for_country() {
		$this->assertSame( 'XAF', MarvinPay_Rules::currency_for_country( 'CM' ) );
		$this->assertSame( 'XAF', MarvinPay_Rules::currency_for_country( 'ga' ) );
		$this->assertSame( 'XOF', MarvinPay_Rules::currency_for_country( 'SN' ) );
		$this->assertSame( 'XOF', MarvinPay_Rules::currency_for_country( 'TG' ) );
		$this->assertNull( MarvinPay_Rules::currency_for_country( 'US' ) );
		$this->assertNull( MarvinPay_Rules::currency_for_country( '' ) );
	}

	public function test_validate_amount_accepts_whole_numbers_in_range() {
		$this->assertSame( 100, MarvinPay_Rules::validate_amount( 100 ) );
		$this->assertSame( 500000, MarvinPay_Rules::validate_amount( '500000' ) );
		$this->assertSame( 5000, MarvinPay_Rules::validate_amount( ' 5000 ' ) );
		$this->assertSame( 5000, MarvinPay_Rules::validate_amount( 5000.0 ) );
		$this->assertSame( 5000, MarvinPay_Rules::validate_amount( '5000.00' ) ); // WooCommerce decimal-string totals
		$this->assertSame( 5000, MarvinPay_Rules::validate_amount( '5000.0' ) );
	}

	public function test_validate_amount_rejects_bad_values() {
		$this->assertFalse( MarvinPay_Rules::validate_amount( 99 ) );
		$this->assertFalse( MarvinPay_Rules::validate_amount( 500001 ) );
		$this->assertFalse( MarvinPay_Rules::validate_amount( 50.5 ) );      // decimals: XAF/XOF have no minor units
		$this->assertFalse( MarvinPay_Rules::validate_amount( '50.5' ) );
		$this->assertFalse( MarvinPay_Rules::validate_amount( '5,000' ) );
		$this->assertFalse( MarvinPay_Rules::validate_amount( '-5000' ) );
		$this->assertFalse( MarvinPay_Rules::validate_amount( '' ) );
		$this->assertFalse( MarvinPay_Rules::validate_amount( null ) );
		$this->assertFalse( MarvinPay_Rules::validate_amount( true ) );
		$this->assertFalse( MarvinPay_Rules::validate_amount( array( 5000 ) ) );
	}

	public function test_normalize_status() {
		$this->assertSame( 'SUCCESSFUL', MarvinPay_Rules::normalize_status( 'SUCCESSFUL' ) ); // REST wording
		$this->assertSame( 'SUCCESSFUL', MarvinPay_Rules::normalize_status( 'SUCCESS' ) );    // webhook wording
		$this->assertSame( 'SUCCESSFUL', MarvinPay_Rules::normalize_status( ' success ' ) );
		$this->assertSame( 'FAILED', MarvinPay_Rules::normalize_status( 'FAILED' ) );
		$this->assertSame( 'FAILED', MarvinPay_Rules::normalize_status( 'CANCEL' ) );         // webhook cancel → failed
		$this->assertSame( 'PENDING', MarvinPay_Rules::normalize_status( 'PENDING' ) );
		$this->assertSame( 'PENDING', MarvinPay_Rules::normalize_status( 'anything-else' ) );
		$this->assertSame( 'PENDING', MarvinPay_Rules::normalize_status( '' ) );
	}

	public function test_state_machine() {
		// PENDING can go anywhere terminal-ish.
		$this->assertTrue( MarvinPay_Rules::can_transition( 'PENDING', 'SUCCESSFUL' ) );
		$this->assertTrue( MarvinPay_Rules::can_transition( 'PENDING', 'FAILED' ) );
		$this->assertTrue( MarvinPay_Rules::can_transition( 'PENDING', 'ABANDONED' ) );
		// ABANDONED can still be corrected by money truth.
		$this->assertTrue( MarvinPay_Rules::can_transition( 'ABANDONED', 'SUCCESSFUL' ) );
		$this->assertTrue( MarvinPay_Rules::can_transition( 'ABANDONED', 'FAILED' ) );
		// Terminal rows are immutable.
		$this->assertFalse( MarvinPay_Rules::can_transition( 'SUCCESSFUL', 'FAILED' ) );
		$this->assertFalse( MarvinPay_Rules::can_transition( 'SUCCESSFUL', 'PENDING' ) );
		$this->assertFalse( MarvinPay_Rules::can_transition( 'FAILED', 'SUCCESSFUL' ) );
		// No self-transitions, nothing returns to PENDING.
		$this->assertFalse( MarvinPay_Rules::can_transition( 'PENDING', 'PENDING' ) );
		$this->assertFalse( MarvinPay_Rules::can_transition( 'ABANDONED', 'PENDING' ) );
	}

	public function test_allowed_sources_matches_can_transition() {
		$this->assertEqualsCanonicalizing( array( 'PENDING', 'ABANDONED' ), MarvinPay_Rules::allowed_sources( 'SUCCESSFUL' ) );
		$this->assertEqualsCanonicalizing( array( 'PENDING', 'ABANDONED' ), MarvinPay_Rules::allowed_sources( 'FAILED' ) );
		$this->assertSame( array( 'PENDING' ), MarvinPay_Rules::allowed_sources( 'ABANDONED' ) );
		$this->assertSame( array(), MarvinPay_Rules::allowed_sources( 'PENDING' ) );
	}

	public function test_mask_mobile() {
		$this->assertSame( '••••••••7091', MarvinPay_Rules::mask_mobile( '237620297091' ) );
		$this->assertSame( '••••••••7091', MarvinPay_Rules::mask_mobile( '+237 620-29-70-91' ) );
		$this->assertSame( '123', MarvinPay_Rules::mask_mobile( '123' ) );
	}

	public function test_new_transaction_id_format() {
		$id = MarvinPay_Rules::new_transaction_id();
		$this->assertMatchesRegularExpression( '/^wpmp_[0-9a-f]{20}$/', $id );
		$this->assertNotSame( $id, MarvinPay_Rules::new_transaction_id() );
	}
}
