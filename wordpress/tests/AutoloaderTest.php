<?php

use PHPUnit\Framework\TestCase;

class AutoloaderTest extends TestCase {

	public function test_autoloads_includes_class() {
		$this->assertTrue( class_exists( 'MarvinPay_Plugin' ) );
	}

	public function test_ignores_foreign_classes() {
		$this->assertFalse( class_exists( 'MarvinPay_Does_Not_Exist' ) );
	}
}
