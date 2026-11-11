<?php

namespace Vatright\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Vatright\Checkout\TaxLocation;
use Vatright\Domain\Decision;
use Vatright\Domain\LineDecision;
use Vatright\Domain\Scheme;
use Vatright\Settings;

require_once dirname( __DIR__, 2 ) . '/Support/WpOptionStubs.php';

class TaxLocationExportTest extends TestCase {

	private const UK_RATES = array(
		7 => array(
			'rate'     => 20.0,
			'label'    => 'VAT',
			'shipping' => 'yes',
			'compound' => 'no',
		),
	);

	protected function setUp(): void {
		$GLOBALS['vatright_test_options'] = array();
		Settings::reset();
	}

	private function register( array $countries ): void {
		$GLOBALS['vatright_test_options'][ Settings::OPTION ] = array( 'non_eu_registrations' => $countries );
		Settings::reset();
	}

	private function decision( ?array $vat_number = null, ?array $validation = null ): Decision {
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
					'country'  => 'GB',
					'postcode' => 'SW1A 1AA',
				),
				'shipping'     => array(
					'country'  => 'GB',
					'postcode' => 'SW1A 1AA',
				),
				'local_pickup' => false,
			),
			$vat_number,
			$validation,
			array(
				'billing'        => 'GB',
				'shipping'       => 'GB',
				'ip'             => 'GB',
				'self_certified' => null,
				'resolved'       => 'GB',
				'conflict'       => false,
				'pieces'         => 3,
			),
			array(
				'outage'                   => 'charge',
				'allow_domestic_exemption' => false,
				'fixed_prices'             => false,
			),
			array( new LineDecision( 'k1', 'digital', 'GB', 'GB', Scheme::EXPORT ) ),
			Scheme::EXPORT,
			'EXPORT_NON_EU',
			false,
			array(),
			array(),
			null
		);
	}

	private function rates( Decision $decision, array $found = self::UK_RATES ): array {
		$address = array(
			'country'  => 'GB',
			'state'    => '',
			'postcode' => 'SW1A 1AA',
			'city'     => '',
		);
		return TaxLocation::line_rates( $decision, $decision->lines()[0], '', $address, $address, $found );
	}

	public function test_registered_country_keeps_the_rates_the_merchant_set_for_it(): void {
		$this->register( array( 'GB' ) );

		$this->assertSame( array( self::UK_RATES, false ), $this->rates( $this->decision() ) );
	}

	public function test_unregistered_country_stays_untaxed(): void {
		$this->assertSame( array( array(), false ), $this->rates( $this->decision() ) );
	}

	public function test_registered_country_does_not_tax_a_valid_local_business_number(): void {
		$this->register( array( 'GB' ) );
		$decision = $this->decision(
			array(
				'raw'       => 'GB123456789',
				'canonical' => 'GB123456789',
				'prefix'    => 'GB',
				'country'   => 'GB',
			),
			array(
				'status'       => 'valid',
				'registry'     => 'hmrc',
				'checked_at'   => '2026-10-17T11:59:58Z',
				'from_cache'   => false,
				'name'         => null,
				'address'      => null,
				'consultation' => null,
				'detail'       => '',
			)
		);

		$this->assertSame( array( array(), false ), $this->rates( $decision ) );
	}

	public function test_registered_country_without_a_rate_row_is_flagged_not_charged_at_the_home_rate(): void {
		$this->register( array( 'GB' ) );

		$this->assertSame( array( array(), true ), $this->rates( $this->decision(), array() ) );
	}

	public function test_registered_country_still_taxes_a_valid_number_from_another_country(): void {
		$this->register( array( 'GB' ) );
		$decision = $this->decision(
			array(
				'raw'       => 'DE123456789',
				'canonical' => 'DE123456789',
				'prefix'    => 'DE',
				'country'   => 'DE',
			),
			array(
				'status'       => 'valid',
				'registry'     => 'vies',
				'checked_at'   => '2026-10-17T11:59:58Z',
				'from_cache'   => false,
				'name'         => null,
				'address'      => null,
				'consultation' => null,
				'detail'       => '',
			)
		);

		$this->assertSame( array( self::UK_RATES, false ), $this->rates( $decision ) );
	}
}
