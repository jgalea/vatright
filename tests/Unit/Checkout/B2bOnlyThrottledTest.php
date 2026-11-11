<?php

namespace Vatright\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Vatright\Checkout\B2bOnly;
use Vatright\Domain\Decision;
use Vatright\Domain\ValidationResult;
use Vatright\Settings;

require_once dirname( __DIR__, 2 ) . '/Support/WpOptionStubs.php';

class B2bOnlyThrottledTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['vatright_test_options'] = array( Settings::OPTION => array( 'b2b_only' => 'everywhere' ) );
		Settings::reset();
	}

	private function decision( string $detail ): Decision {
		return new Decision(
			'id-1',
			'2026-10-17T12:00:00Z',
			'checkout',
			array(
				'country'        => 'MT',
				'oss_registered' => false,
				'threshold'      => array(
					'current_eur'  => '0.00',
					'previous_eur' => '0.00',
					'exceeded'     => false,
					'forced'       => false,
				),
			),
			array(
				'billing'      => array(
					'country'  => 'DE',
					'postcode' => '10115',
				),
				'shipping'     => array(
					'country'  => 'DE',
					'postcode' => '10115',
				),
				'local_pickup' => false,
			),
			array(
				'raw'       => 'DE123456789',
				'canonical' => 'DE123456789',
				'prefix'    => 'DE',
				'country'   => 'DE',
			),
			( new ValidationResult( ValidationResult::UNCHECKED, 'vies', '2026-10-17T11:59:58Z', null, null, null, false, $detail ) )->to_array(),
			array(
				'billing'        => 'DE',
				'shipping'       => 'DE',
				'ip'             => 'DE',
				'self_certified' => null,
				'resolved'       => 'DE',
				'conflict'       => false,
				'pieces'         => 3,
			),
			array(
				'outage'                   => 'charge',
				'allow_domestic_exemption' => false,
				'fixed_prices'             => false,
			),
			array(),
			'domestic',
			'DOMESTIC',
			false,
			array(),
			array(),
			null
		);
	}

	public function test_a_throttled_lookup_asks_the_shopper_to_retry_not_to_fix_the_number(): void {
		$this->assertSame( 'b2b_only_throttled', B2bOnly::error( $this->decision( 'throttled' ) ) );
	}

	public function test_another_unchecked_number_keeps_the_unverified_error(): void {
		$this->assertSame( 'b2b_only_unverified', B2bOnly::error( $this->decision( 'no_registry' ) ) );
	}
}
