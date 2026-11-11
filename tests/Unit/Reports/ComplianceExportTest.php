<?php

namespace Vatright\Tests\Unit\Reports;

require_once dirname( __DIR__, 2 ) . '/Support/WpOptionStubs.php';

use PHPUnit\Framework\TestCase;
use Vatright\Reports\ComplianceExport;

class ComplianceExportTest extends TestCase {

	public function test_a_row_carries_the_evidence_the_treatment_rests_on(): void {
		$record = array(
			'customer'   => array(
				'billing'  => array( 'country' => 'DE' ),
				'shipping' => array( 'country' => 'AT' ),
			),
			'evidence'   => array(
				'ip'             => 'DE',
				'self_certified' => null,
			),
			'vat_number' => array( 'canonical' => 'DE123456789' ),
			'validation' => array(
				'registry'     => 'vies',
				'status'       => 'valid',
				'checked_at'   => '2026-10-17T11:59:58Z',
				'consultation' => 'WAPIAAAA',
			),
			'scheme'     => 'reverse_charge_services',
			'rule'       => 'RC_SERVICES_ART196',
			'flags'      => array( 'validation_pending', 'single_evidence' ),
		);
		$totals = array(
			'order_id'  => '41',
			'supply_at' => '2026-10-17 10:00:00',
			'currency'  => 'EUR',
			'net'       => '100.5',
			'vat'       => '0',
		);

		$this->assertSame(
			array( 41, '2026-10-17 10:00:00', 'DE', 'AT', 'DE', '', 'DE123456789', 'vies', 'valid', '2026-10-17T11:59:58Z', 'WAPIAAAA', 'reverse_charge_services', 'RC_SERVICES_ART196', 'validation_pending single_evidence', 'EUR', '100.50', '0.00' ),
			ComplianceExport::row( $totals, $record )
		);
	}

	public function test_an_order_without_a_decision_still_gets_a_row_with_its_totals(): void {
		$row = ComplianceExport::row(
			array(
				'order_id'  => 9,
				'supply_at' => '2026-10-17 10:00:00',
				'currency'  => 'EUR',
				'net'       => '10',
				'vat'       => '1.9',
			),
			null
		);

		$this->assertSame( array( 9, '2026-10-17 10:00:00', '', '', '', '', '', '', '', '', '', '', '', '', 'EUR', '10.00', '1.90' ), $row );
		$this->assertCount( count( ComplianceExport::csv( array() )[0] ), $row );
	}
}
