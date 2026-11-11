<?php

namespace Vatright\Tests\Integration\Reports;

use Vatright\Domain\Scheme;
use Vatright\Reports\ComplianceExport;
use Vatright\Reports\ReportPeriod;

require_once __DIR__ . '/ReportsTestCase.php';

class ComplianceExportTest extends ReportsTestCase {

	public function test_rows_come_in_order_across_pages(): void {
		$ids = array();
		foreach ( array( 100, 110, 120, 130, 140 ) as $net ) {
			$ids[] = $this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', $net, 19, array( 'created' => '2026-08-02 10:00:00' ) )->get_id();
		}
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 999, 19, array( 'created' => '2026-05-02 10:00:00' ) );
		$export = new ComplianceExport();

		$paged = iterator_to_array( $export->rows_for( ReportPeriod::month( 2026, 8 ), 2 ), false );
		$whole = iterator_to_array( $export->rows_for( ReportPeriod::month( 2026, 8 ) ), false );

		$this->assertCount( 5, $paged );
		$this->assertSame( $ids, array_column( $paged, 0 ) );
		$this->assertSame( $whole, $paged );
		$this->assertSame( array( '100.00', '110.00', '120.00', '130.00', '140.00' ), array_column( $paged, 15 ) );
	}

	public function test_a_period_without_sales_gives_no_rows(): void {
		$this->assertSame( array(), iterator_to_array( ( new ComplianceExport() )->rows_for( ReportPeriod::month( 2026, 8 ), 2 ), false ) );
	}
}
