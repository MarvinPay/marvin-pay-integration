<?php

use PHPUnit\Framework\TestCase;

class FulfillmentTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__mp_wc_orders'] = array();
	}

	private function row( $status ) {
		return (object) array(
			'transaction_id' => 'wpmp_aabbccddeeff00112233',
			'source'         => 'woocommerce',
			'source_ref'     => '42',
			'status'         => $status,
		);
	}

	private function order( $paid ) {
		$order                = new MP_Fake_WC_Order();
		$order->paid          = $paid;
		$order->needs_payment = ! $paid;
		$GLOBALS['__mp_wc_orders'][42] = $order;
		return $order;
	}

	public function test_success_pays_an_unpaid_order() {
		$order = $this->order( false );
		MarvinPay_Fulfillment::sync_woo_order( $this->row( 'SUCCESSFUL' ) );
		$this->assertSame( array( 'wpmp_aabbccddeeff00112233' ), $order->payment_complete_refs );
		$this->assertSame( array(), $order->status_updates );
	}

	public function test_second_success_on_a_paid_order_only_notes_a_refund() {
		$order = $this->order( true );
		MarvinPay_Fulfillment::sync_woo_order( $this->row( 'SUCCESSFUL' ) );
		$this->assertSame( array(), $order->payment_complete_refs );
		$this->assertStringContainsString( 'refund', $order->notes[0] );
	}

	public function test_failure_downgrades_an_unpaid_order() {
		$order = $this->order( false );
		MarvinPay_Fulfillment::sync_woo_order( $this->row( 'FAILED' ) );
		$this->assertSame( array( 'failed' ), $order->status_updates );
	}

	public function test_stale_failure_never_downgrades_a_paid_order() {
		$order = $this->order( true );
		MarvinPay_Fulfillment::sync_woo_order( $this->row( 'FAILED' ) );
		$this->assertSame( array(), $order->status_updates );
		$this->assertStringContainsString( 'no status change', $order->notes[0] );
	}
}
