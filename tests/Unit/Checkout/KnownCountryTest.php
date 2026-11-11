<?php

namespace Vatright\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Vatright\Checkout\InputGatherer;

require_once dirname( __DIR__, 2 ) . '/Support/WcOrderFakes.php';
require_once dirname( __DIR__, 2 ) . '/Support/WcFunctionStubs.php';

class KnownCountryTest extends TestCase {

	public function test_a_listed_country_is_kept_in_upper_case(): void {
		$this->assertSame( 'DE', InputGatherer::known_country( 'de' ) );
	}

	public function test_a_made_up_code_counts_as_no_country(): void {
		$this->assertSame( '', InputGatherer::known_country( 'ZZ' ) );
		$this->assertTrue( InputGatherer::is_unknown_country( 'ZZ' ) );
		$this->assertFalse( InputGatherer::is_unknown_country( '' ) );
	}
}
