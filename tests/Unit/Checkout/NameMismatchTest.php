<?php

namespace Vatright\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Vatright\Checkout\NameMismatch;
use Vatright\Domain\ValidationResult;
use Vatright\Settings;

require_once dirname( __DIR__, 2 ) . '/Support/WpOptionStubs.php';

class NameMismatchTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['vatright_test_options'] = array( Settings::OPTION => array( 'refuse_name_mismatch' => true ) );
		Settings::reset();
	}

	private function valid( string $registry = 'vies', string $detail = '' ): ValidationResult {
		return new ValidationResult( ValidationResult::VALID, $registry, '2026-10-17T11:59:58Z', 'ACME GMBH', null, null, false, $detail );
	}

	public function test_a_valid_number_with_no_company_name_is_held_to_the_registered_name(): void {
		$this->assertTrue( NameMismatch::requires_name( 'checkout', $this->valid() ) );
	}

	public function test_the_name_is_not_required_when_the_setting_is_off(): void {
		$GLOBALS['vatright_test_options'] = array();
		Settings::reset();

		$this->assertFalse( NameMismatch::requires_name( 'checkout', $this->valid() ) );
	}

	public function test_the_name_is_not_required_of_registries_that_do_not_compare_names(): void {
		$this->assertFalse( NameMismatch::requires_name( 'checkout', $this->valid( 'hmrc' ) ) );
	}

	public function test_an_order_recheck_does_not_ask_for_a_name(): void {
		$this->assertFalse( NameMismatch::requires_name( 'recheck', $this->valid() ) );
	}
}
