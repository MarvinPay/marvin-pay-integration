<?php

use PHPUnit\Framework\TestCase;

class ShortcodesTest extends TestCase {

	protected function setUp(): void {
		// configure the plugin so renderers do not bail
		update_option( MarvinPay_Settings::OPTION, array(
			'mode'          => 'live',
			'live_api_key'  => 'sk_live_x',
			'live_base_url' => 'https://api.example.test/api',
			'country'       => 'CM',
		) );
	}

	public function test_button_renders_signed_config() {
		$html = MarvinPay_Shortcodes::render_button( array( 'amount' => '5000', 'description' => 'Consultation' ) );
		$this->assertStringContainsString( 'data-widget="modal"', $html );
		$this->assertStringContainsString( 'marvinpay-open', $html );

		// extract config + sig and verify the signature covers the exact attribute string
		preg_match( '/data-config="([^"]*)"/', $html, $cm );
		preg_match( '/data-sig="([^"]*)"/', $html, $sm );
		$config_json = html_entity_decode( $cm[1], ENT_QUOTES );
		$this->assertTrue( MarvinPay_Widget_Config::verify( $config_json, $sm[1] ) );

		$config = MarvinPay_Widget_Config::decode( $config_json );
		$this->assertSame( 'button', $config['type'] );
		$this->assertSame( 5000, $config['amount'] );
		$this->assertSame( 'Consultation', $config['description'] );
	}

	public function test_button_with_bad_amount_renders_config_error() {
		$html = MarvinPay_Shortcodes::render_button( array( 'amount' => '50' ) );
		$this->assertStringContainsString( 'marvinpay-config-error', $html );
		$this->assertStringNotContainsString( 'data-sig', $html );
	}

	public function test_unconfigured_plugin_renders_config_error() {
		update_option( MarvinPay_Settings::OPTION, array() );
		$html = MarvinPay_Shortcodes::render_button( array( 'amount' => '5000' ) );
		$this->assertStringContainsString( 'marvinpay-config-error', $html );
	}

	public function test_donation_parses_presets_and_bounds() {
		$html = MarvinPay_Shortcodes::render_donation( array( 'presets' => '1000, 5000, junk, 250000', 'min' => '500', 'max' => '250000' ) );
		preg_match( '/data-config="([^"]*)"/', $html, $cm );
		$config = MarvinPay_Widget_Config::decode( html_entity_decode( $cm[1], ENT_QUOTES ) );
		$this->assertSame( 'donation', $config['type'] );
		$this->assertSame( 0, $config['amount'] );
		$this->assertSame( array( 1000, 5000, 250000 ), $config['presets'] ); // junk dropped
		$this->assertSame( 500, $config['min'] );
		$this->assertSame( 250000, $config['max'] );
	}

	public function test_inline_form_renders_inline_widget() {
		$html = MarvinPay_Shortcodes::render_form( array( 'description' => 'Pay here' ) );
		$this->assertStringContainsString( 'data-widget="inline"', $html );
		preg_match( '/data-config="([^"]*)"/', $html, $cm );
		$config = MarvinPay_Widget_Config::decode( html_entity_decode( $cm[1], ENT_QUOTES ) );
		$this->assertSame( 'inline', $config['type'] );
		$this->assertSame( 1, $config['show_name'] );
		$this->assertSame( 1, $config['show_email'] );
	}

	public function test_status_row_masks_mobile_and_omits_pii() {
		$row = (object) array(
			'transaction_id' => 'wpmp_aabbccddeeff00112233',
			'amount'         => '5000',
			'currency'       => 'XAF',
			'payment_method' => 'mtn_cm',
			'mobile_number'  => '237620297091',
			'payer_name'     => 'Secret Name',
			'payer_email'    => 'secret@example.com',
			'status'         => 'SUCCESSFUL',
			'created_at'     => '2026-08-18 10:00:00',
		);
		$html = MarvinPay_Shortcodes::render_status_row( $row );
		$this->assertStringContainsString( '7091', $html );
		$this->assertStringNotContainsString( '237620297091', $html );  // masked
		$this->assertStringNotContainsString( 'Secret Name', $html );   // no PII
		$this->assertStringNotContainsString( 'secret@example.com', $html );
		$this->assertStringContainsString( 'marvinpay-badge-successful', $html );
		$this->assertStringContainsString( 'wpmp_aabbccddeeff00112233', $html );
	}
}
