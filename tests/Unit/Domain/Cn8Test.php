<?php

namespace Vatright\Tests\Unit\Domain;

use Vatright\Domain\Cn8;
use PHPUnit\Framework\TestCase;

class Cn8Test extends TestCase {

	/**
	 * @return array<string,array{string,string}>
	 */
	public function accepted(): array {
		return array(
			'plain'              => array( '61091000', '61091000' ),
			'with spaces'        => array( ' 6109 1000 ', '61091000' ),
			'with dots'          => array( '6109.10.00', '61091000' ),
			'leading zeros kept' => array( '01012100', '01012100' ),
		);
	}

	/**
	 * @dataProvider accepted
	 */
	public function test_normalises_to_eight_digits( string $input, string $expected ): void {
		$this->assertSame( $expected, Cn8::normalise( $input ) );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function rejected(): array {
		return array(
			'empty'       => array( '' ),
			'six digits'  => array( '610910' ),
			'ten digits'  => array( '6109100000' ),
			'letters'     => array( '6109100A' ),
			'only spaces' => array( '   ' ),
		);
	}

	/**
	 * @dataProvider rejected
	 */
	public function test_rejects_anything_but_eight_digits( string $input ): void {
		$this->assertNull( Cn8::normalise( $input ) );
	}
}
