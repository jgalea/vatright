<?php

namespace Vatright\Tests\Unit\Domain;

use Vatright\Domain\B2bScope;
use PHPUnit\Framework\TestCase;

class B2bScopeTest extends TestCase {

	/**
	 * @return array<string,array{string,string[],string,bool}>
	 */
	public function cases(): array {
		return array(
			'off covers nobody'                    => array( 'off', array(), 'DE', false ),
			'everywhere covers an EU country'      => array( 'everywhere', array(), 'DE', true ),
			'everywhere covers a non-EU country'   => array( 'everywhere', array(), 'US', true ),
			'everywhere covers an unknown country' => array( 'everywhere', array(), '', true ),
			'eu covers an EU country'              => array( 'eu', array(), 'FR', true ),
			'eu covers the shop country too'       => array( 'eu', array(), 'MT', true ),
			'eu leaves the US out'                 => array( 'eu', array(), 'US', false ),
			'eu leaves Switzerland out'            => array( 'eu', array(), 'CH', false ),
			'eu leaves an unknown country out'     => array( 'eu', array(), '', false ),
			'list covers a listed country'         => array( 'countries', array( 'DE', 'US' ), 'US', true ),
			'list leaves an unlisted one out'      => array( 'countries', array( 'DE', 'US' ), 'FR', false ),
			'list matches case-insensitively'      => array( 'countries', array( 'de' ), 'DE', true ),
			'an empty list covers nobody'          => array( 'countries', array(), 'DE', false ),
			'an unknown mode covers nobody'        => array( 'sometimes', array( 'DE' ), 'DE', false ),
		);
	}

	/**
	 * @dataProvider cases
	 * @param string[] $countries
	 */
	public function test_scope( string $mode, array $countries, string $country, bool $expected ): void {
		$this->assertSame( $expected, B2bScope::applies( $mode, $countries, $country ) );
	}

	public function test_modes_are_the_documented_four(): void {
		$this->assertSame( array( 'off', 'everywhere', 'eu', 'countries' ), B2bScope::MODES );
	}
}
