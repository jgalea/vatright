<?php

namespace Vatright\Tests\Unit\Reports;

use PHPUnit\Framework\TestCase;
use Vatright\Reports\Csv;

class CsvGuardTest extends TestCase {

	/**
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public function cells(): array {
		return array(
			'leading space'     => array( ' =SUM(A1)', "' =SUM(A1)" ),
			'full-width equals' => array( "\u{FF1D}1+1", "'\u{FF1D}1+1" ),
			'full-width at'     => array( "\u{FF20}cmd", "'\u{FF20}cmd" ),
			'tab'               => array( "\tx", "'\tx" ),
			'plain'             => array( 'DE123456789', 'DE123456789' ),
			'negative number'   => array( '-12.50', '-12.50' ),
			'spaced number'     => array( ' 12.50', ' 12.50' ),
		);
	}

	/**
	 * @dataProvider cells
	 * @param mixed $input
	 */
	public function test_formula_lookalikes_are_guarded( $input, string $expected ): void {
		$this->assertSame( $expected, Csv::cell( $input ) );
	}
}
