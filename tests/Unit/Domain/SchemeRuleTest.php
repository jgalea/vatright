<?php

namespace Vatright\Tests\Unit\Domain;

use Vatright\Domain\Rule;
use Vatright\Domain\Scheme;
use PHPUnit\Framework\TestCase;

class SchemeRuleTest extends TestCase {

	public function test_schemes_match_the_documented_list(): void {
		$expected = array( 'reverse_charge_services', 'intra_eu_goods', 'oss_destination', 'origin', 'domestic', 'export', 'outside_territory', 'role_exempt', 'mixed' );
		$actual   = array_values( ( new \ReflectionClass( Scheme::class ) )->getConstants() );
		$this->assertEqualsCanonicalizing( $expected, $actual );
	}

	public function test_rules_match_the_documented_list(): void {
		$expected = array( 'EXPORT_NON_EU', 'OUTSIDE_TERRITORY', 'DOMESTIC', 'DOMESTIC_B2B_GUARD', 'DOMESTIC_B2B_ALLOWED', 'RC_GOODS_ART138', 'RC_SERVICES_ART196', 'B2C_ORIGIN_BELOW_THRESHOLD', 'B2C_DESTINATION', 'B2C_SERVICE_ORIGIN', 'PREFIX_MISMATCH_CHARGED', 'LOCAL_PICKUP_DOMESTIC', 'PENDING_CHARGED', 'PENDING_EXEMPT', 'PENDING_REJECTED', 'INVALID_NUMBER', 'NON_EU_NUMBER_RECORDED', 'ROLE_EXEMPT' );
		$actual   = array_values( ( new \ReflectionClass( Rule::class ) )->getConstants() );
		$this->assertEqualsCanonicalizing( $expected, $actual );
	}
}
