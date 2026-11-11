<?php

namespace Vatright\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Vatright\Checkout\CheckoutDecider;
use Vatright\Domain\Decision;
use Vatright\Domain\LineDecision;
use Vatright\Domain\Scheme;
use Vatright\Settings;

require_once dirname( __DIR__, 2 ) . '/Support/WpOptionStubs.php';

class BlockedTerritoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['vatright_test_options'] = array( Settings::OPTION => array( 'blocked_vat_territories' => array( 'DE' ) ) );
		Settings::reset();
	}

	/**
	 * @param LineDecision[] $lines
	 */
	private function decision( array $lines, bool $exempt = false ): Decision {
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
			null,
			null,
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
			$lines,
			Scheme::MIXED,
			'B2C_DESTINATION',
			$exempt,
			array(),
			array(),
			null
		);
	}

	public function test_a_charged_line_in_a_blocked_territory_stops_checkout(): void {
		$decision = $this->decision( array( new LineDecision( 'a', 'goods', 'DE', 'DE', Scheme::OSS_DESTINATION ) ) );

		$this->assertTrue( CheckoutDecider::sells_into_blocked_territory( $decision ) );
	}

	public function test_a_reverse_charged_line_in_the_same_territory_goes_through(): void {
		$decision = $this->decision( array( new LineDecision( 'a', 'services', 'DE', 'DE', Scheme::REVERSE_CHARGE_SERVICES ) ) );

		$this->assertFalse( CheckoutDecider::sells_into_blocked_territory( $decision ) );
	}

	public function test_a_charged_line_elsewhere_goes_through(): void {
		$decision = $this->decision( array( new LineDecision( 'a', 'goods', 'FR', 'FR', Scheme::OSS_DESTINATION ) ) );

		$this->assertFalse( CheckoutDecider::sells_into_blocked_territory( $decision ) );
	}

	public function test_nothing_is_blocked_when_no_territory_is_chosen(): void {
		$GLOBALS['vatright_test_options'] = array();
		Settings::reset();
		$decision = $this->decision( array( new LineDecision( 'a', 'goods', 'DE', 'DE', Scheme::OSS_DESTINATION ) ) );

		$this->assertFalse( CheckoutDecider::sells_into_blocked_territory( $decision ) );
	}
}
