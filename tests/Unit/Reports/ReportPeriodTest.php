<?php

namespace Vatright\Tests\Unit\Reports;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vatright\Reports\ReportPeriod;

class ReportPeriodTest extends TestCase {

	public function test_a_quarter_spans_its_three_months_and_keeps_the_stored_label(): void {
		$p = ReportPeriod::quarter( 2026, 3 );

		$this->assertSame( ReportPeriod::QUARTER, $p->kind() );
		$this->assertSame( '2026-Q3', $p->label() );
		$this->assertSame( '2026-q3', $p->slug() );
		$this->assertSame( '2026-07-01', $p->start() );
		$this->assertSame( '2026-09-30', $p->end() );
		$this->assertSame( 3, $p->quarter_number() );
	}

	public function test_a_month_knows_its_last_day_including_a_leap_february(): void {
		$this->assertSame( '2028-02-29', ReportPeriod::month( 2028, 2 )->end() );
		$this->assertSame( '2027-02-28', ReportPeriod::month( 2027, 2 )->end() );
		$this->assertSame( '2026-09', ReportPeriod::month( 2026, 9 )->label() );
		$this->assertNull( ReportPeriod::month( 2026, 9 )->quarter_number() );
	}

	public function test_a_range_is_labelled_by_its_two_dates(): void {
		$p = ReportPeriod::range( '2026-07-20', '2026-08-25' );

		$this->assertNotNull( $p );
		$this->assertSame( ReportPeriod::RANGE, $p->kind() );
		$this->assertSame( '2026-07-20_2026-08-25', $p->label() );
		$this->assertSame( '2026-07-20-to-2026-08-25', $p->slug() );
		$this->assertSame( '2026-08-25', $p->end() );
	}

	public function test_a_range_must_be_real_dates_in_order(): void {
		$this->assertNull( ReportPeriod::range( '2026-08-25', '2026-07-20' ) );
		$this->assertNull( ReportPeriod::range( '2026-02-30', '2026-03-05' ) );
		$this->assertNull( ReportPeriod::range( 'yesterday', '2026-03-05' ) );
		$this->assertNull( ReportPeriod::range( '2026-3-1', '2026-03-05' ) );
		$this->assertNotNull( ReportPeriod::range( '2026-03-05', '2026-03-05' ), 'One day is a range.' );
	}

	public function test_labels_round_trip(): void {
		foreach ( array( '2026-Q3', '2026-09', '2026-07-20_2026-08-25' ) as $label ) {
			$p = ReportPeriod::from_label( $label );
			$this->assertNotNull( $p, $label );
			$this->assertSame( $label, $p->label() );
		}
		$this->assertNull( ReportPeriod::from_label( '2026-Q5' ) );
		$this->assertNull( ReportPeriod::from_label( '2026-13' ) );
		$this->assertNull( ReportPeriod::from_label( 'nonsense' ) );
	}

	public function test_request_arguments_pick_a_period_in_a_fixed_order(): void {
		$range = ReportPeriod::from_args(
			array(
				'year'    => 2026,
				'quarter' => 3,
				'month'   => 8,
				'from'    => '2026-08-01',
				'to'      => '2026-08-10',
			)
		);
		$this->assertNotNull( $range );
		$this->assertSame( '2026-08-01_2026-08-10', $range->label() );

		$month = ReportPeriod::from_args(
			array(
				'year'    => 2026,
				'quarter' => 3,
				'month'   => 8,
			)
		);
		$this->assertNotNull( $month );
		$this->assertSame( '2026-08', $month->label() );

		$quarter = ReportPeriod::from_args(
			array(
				'year'    => '2026',
				'quarter' => '3',
			)
		);
		$this->assertNotNull( $quarter );
		$this->assertSame( '2026-Q3', $quarter->label() );
	}

	public function test_incomplete_or_invalid_arguments_give_no_period(): void {
		$this->assertNull( ReportPeriod::from_args( array() ) );
		$this->assertNull( ReportPeriod::from_args( array( 'year' => 2026 ) ) );
		$this->assertNull( ReportPeriod::from_args( array( 'from' => '2026-08-01' ) ) );
		$this->assertNull(
			ReportPeriod::from_args(
				array(
					'from' => '2026-08-10',
					'to'   => '2026-08-01',
				)
			)
		);
		$this->assertNull(
			ReportPeriod::from_args(
				array(
					'year'    => 2026,
					'quarter' => 5,
				)
			)
		);
		$this->assertNull(
			ReportPeriod::from_args(
				array(
					'year'  => 2026,
					'month' => 0,
				)
			)
		);
	}

	public function test_utc_bounds_follow_the_site_timezone_and_end_exclusive(): void {
		$p = ReportPeriod::month( 2026, 8 );

		$this->assertSame( array( '2026-08-01 00:00:00', '2026-09-01 00:00:00' ), $p->utc_bounds( new DateTimeZone( 'UTC' ) ) );
		// Malta is UTC+2 in summer: local midnight is 22:00 the evening before.
		$this->assertSame( array( '2026-07-31 22:00:00', '2026-08-31 22:00:00' ), $p->utc_bounds( new DateTimeZone( 'Europe/Malta' ) ) );
	}
}
