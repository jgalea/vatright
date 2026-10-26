<?php

namespace Vatright\Tests\Integration\Reports;

use Vatright\Domain\Scheme;
use Vatright\Ledger\Fx;
use Vatright\Ledger\Schema;
use Vatright\Reports\Csv;
use Vatright\Reports\EcSalesList;
use Vatright\Reports\IncompleteReport;
use Vatright\Reports\IntrastatReport;
use Vatright\Reports\OssReport;
use Vatright\Reports\ReportPeriod;
use Vatright\Reports\ReportsScreen;
use Vatright\Reports\RestController;
use WP_REST_Request;

require_once __DIR__ . '/ReportsTestCase.php';

class ReportPeriodsTest extends ReportsTestCase {

	/** @var OssReport */
	private $oss;

	/** @var EcSalesList */
	private $esl;

	public function set_up() {
		parent::set_up();
		$this->oss = new OssReport( new Fx( $this->transport ) );
		$this->esl = new EcSalesList();
	}

	public function tear_down() {
		$_GET                      = array();
		$_POST                     = array();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		parent::tear_down();
	}

	private function oss_quarter(): void {
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 100, 19, array( 'created' => '2026-07-15 10:00:00' ) );
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'FR', 200, 20, array( 'created' => '2026-08-02 10:00:00' ) );
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 50, 19, array( 'created' => '2026-08-20 09:00:00' ) );
		$this->sell(
			Scheme::OSS_DESTINATION,
			'goods',
			'DE',
			113.55,
			19,
			array(
				'currency' => 'USD',
				'created'  => '2026-09-25 10:00:00',
			)
		);
	}

	private function esl_quarter(): void {
		$this->reverse_charge( 'digital', 100, 'DE123456789', '2026-07-15 10:00:00' );
		$this->reverse_charge( 'digital', 50, 'DE123456789', '2026-08-20 09:00:00' );
		$this->reverse_charge( 'goods', 30, 'FR12345678901', '2026-09-25 10:00:00' );
	}

	private function range( string $from, string $to ): ReportPeriod {
		$period = ReportPeriod::range( $from, $to );
		$this->assertNotNull( $period );
		return $period;
	}

	public function test_a_month_report_holds_only_that_months_sales(): void {
		$this->oss_quarter();

		$august = $this->oss->build_for( ReportPeriod::month( 2026, 8 ) );

		$this->assertSame( '2026-08', $august['period'] );
		$this->assertSame( 'month', $august['kind'] );
		$this->assertNull( $august['quarter'] );
		$this->assertSame( '2026-08-31', $august['to'] );
		$this->assertSame(
			array(
				array(
					'member_state' => 'DE',
					'rate_percent' => 19.0,
					'supply_type'  => 'goods',
					'taxable'      => 50.0,
					'vat'          => 9.5,
				),
				array(
					'member_state' => 'FR',
					'rate_percent' => 20.0,
					'supply_type'  => 'goods',
					'taxable'      => 200.0,
					'vat'          => 40.0,
				),
			),
			$august['rows']
		);
		$this->assertSame( array(), $august['rates'], 'August has only euro sales.' );
		$this->assertSame( array(), $this->oss->build_for( ReportPeriod::month( 2026, 6 ) )['rows'] );
	}

	public function test_a_non_euro_month_converts_at_the_rate_of_the_months_last_day(): void {
		$this->oss_quarter();

		$september = $this->oss->build_for( ReportPeriod::month( 2026, 9 ) );

		$this->assertSame( 100.0, $september['rows'][0]['taxable'] );
		$this->assertSame( 19.0, $september['rows'][0]['vat'] );
		$this->assertSame(
			array(
				array(
					'currency'  => 'USD',
					'period'    => '2026-09',
					'rate_date' => '2026-09-30',
					'rate'      => 1.1355,
				),
			),
			$september['rates']
		);
	}

	public function test_a_custom_range_includes_whole_days_and_converts_at_the_range_end(): void {
		$this->oss_quarter();

		$report = $this->oss->build_for( $this->range( '2026-08-01', '2026-09-30' ) );

		$this->assertSame( 150.0, $report['rows'][0]['taxable'] );
		$this->assertSame( 28.5, $report['rows'][0]['vat'] );
		$this->assertSame( 200.0, $report['rows'][1]['taxable'] );
		$this->assertSame( '2026-09-30', $report['rates'][0]['rate_date'] );
		$this->assertSame( '2026-08-01_2026-09-30', $report['period'] );

		$edge = $this->oss->build_for( $this->range( '2026-08-20', '2026-08-20' ) );
		$this->assertSame( 50.0, $edge['rows'][0]['taxable'], 'An order at 09:00 on the last day is in the range.' );
		$this->assertCount( 1, $edge['rows'] );
	}

	public function test_a_quarter_built_the_old_way_is_the_quarter_period(): void {
		$this->oss_quarter();

		$this->assertSame( $this->oss->build( 2026, 3 ), $this->oss->build_for( ReportPeriod::quarter( 2026, 3 ) ) );
		$this->assertSame( '2026-Q3', $this->oss->build( 2026, 3 )['period'] );
	}

	public function test_a_later_refund_nets_into_the_sale_month_and_is_a_correction_in_the_month_it_was_booked(): void {
		$order = $this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 100, 19, array( 'created' => '2026-07-15 10:00:00' ) );
		$this->refund_item( $order, 40, 7.6, '2026-10-05 10:00:00' );

		$july    = $this->oss->build_for( ReportPeriod::month( 2026, 7 ) );
		$october = $this->oss->build_for( ReportPeriod::month( 2026, 10 ) );
		$range   = $this->oss->build_for( $this->range( '2026-10-01', '2026-10-31' ) );
		$whole   = $this->oss->build_for( $this->range( '2026-07-01', '2026-10-31' ) );

		$this->assertSame( 60.0, $july['rows'][0]['taxable'] );
		$this->assertSame( array(), $july['corrections'] );
		$this->assertSame( array(), $october['rows'], 'A correction is not a sale of the later month.' );
		$this->assertCount( 1, $october['corrections'] );
		$this->assertSame( '2026-07', $october['corrections'][0]['relates_to_period'] );
		$this->assertSame( -40.0, $october['corrections'][0]['taxable'] );
		$this->assertSame( -7.6, $october['corrections'][0]['vat'] );
		$this->assertSame( $october['corrections'], $range['corrections'] );
		$this->assertSame( 60.0, $whole['rows'][0]['taxable'] );
		$this->assertSame( array(), $whole['corrections'], 'Sale and refund both inside the range need no correction.' );
	}

	public function test_a_refund_of_a_non_euro_sale_is_converted_at_the_rate_of_the_sale_month(): void {
		$order = $this->sell(
			Scheme::OSS_DESTINATION,
			'goods',
			'DE',
			113.55,
			19,
			array(
				'currency' => 'USD',
				'created'  => '2026-09-25 10:00:00',
			)
		);
		$this->refund_item( $order, 56.775, 10.79, '2026-10-05 10:00:00' );

		$october = $this->oss->build_for( ReportPeriod::month( 2026, 10 ) );

		$this->assertSame( -50.0, $october['corrections'][0]['taxable'] );
		$this->assertSame( '2026-09', $october['corrections'][0]['relates_to_period'] );
		$this->assertSame( '2026-09-30', $october['rates'][0]['rate_date'] );
	}

	public function test_a_store_rate_sale_is_listed_for_correction_in_its_month_only(): void {
		$fallback = $this->sell_at_store_rate( 'IT', 50 );

		$this->assertSame( array( $fallback->get_id() ), array_column( $this->oss->build_for( ReportPeriod::month( 2026, 9 ) )['needs_correction'], 'order_id' ) );
		$this->assertSame( array(), $this->oss->build_for( ReportPeriod::month( 2026, 8 ) )['needs_correction'] );
	}

	public function test_a_range_oss_csv_matches_the_golden_file(): void {
		$this->oss_quarter();

		$csv    = OssReport::csv( $this->oss->build_for( $this->range( '2026-08-01', '2026-09-30' ) ) );
		$golden = (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/reports/oss-2026-08-01-to-2026-09-30.csv' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertSame( $golden, Csv::render( $csv[0], $csv[1] ) );
	}

	public function test_the_ec_sales_list_for_a_month_and_a_range(): void {
		$this->esl_quarter();

		$august = $this->esl->build_for( ReportPeriod::month( 2026, 8 ) );
		$this->assertCount( 1, $august );
		$this->assertSame( 50.0, $august[0]['net_eur'] );
		$this->assertSame( 'FR12345678901', $this->esl->build_for( ReportPeriod::month( 2026, 9 ) )[0]['customer_vat'] );

		$whole = $this->esl->build_for( $this->range( '2026-08-01', '2026-09-30' ) );
		$this->assertSame( array( 'DE123456789', 'FR12345678901' ), array_column( $whole, 'customer_vat' ) );
		$this->assertSame( array(), $this->esl->build_for( ReportPeriod::month( 2026, 6 ) ) );
		$this->assertSame( $this->esl->build( 2026, 3 ), $this->esl->build_for( ReportPeriod::quarter( 2026, 3 ) ) );
		$this->assertCount( 2, $this->esl->build( 2026, 3 ) );
	}

	public function test_a_refund_reduces_the_month_of_the_sale_it_changes(): void {
		$order = $this->reverse_charge( 'digital', 100, 'DE123456789', '2026-07-15 10:00:00' );
		$this->refund_item( $order, 30, 0, '2026-10-05 10:00:00' );

		$this->assertSame( 70.0, $this->esl->build_for( ReportPeriod::month( 2026, 7 ) )[0]['net_eur'] );
		$this->assertSame( array(), $this->esl->build_for( ReportPeriod::month( 2026, 10 ) ) );
	}

	public function test_a_missing_eur_amount_only_blocks_the_periods_that_contain_the_order(): void {
		$this->esl_quarter();
		global $wpdb;
		$wpdb->query( 'UPDATE ' . Schema::ledger_table() . " SET net_eur = NULL WHERE customer_vat = 'FR12345678901'" ); // phpcs:ignore WordPress.DB

		$this->assertSame( 50.0, $this->esl->build_for( ReportPeriod::month( 2026, 8 ) )[0]['net_eur'] );
		$this->expectException( IncompleteReport::class );
		$this->esl->build_for( ReportPeriod::month( 2026, 9 ) );
	}

	public function test_the_ec_sales_list_csv_for_a_month_is_the_months_list(): void {
		$this->esl_quarter();

		$csv = EcSalesList::csv( $this->esl->build_for( ReportPeriod::month( 2026, 8 ) ) );

		$this->assertSame(
			"\"VAT number\",Country,\"Goods or services\",\"Net amount (EUR)\"\nDE123456789,DE,services,50.00\n",
			Csv::render( $csv[0], $csv[1] )
		);
	}

	public function test_the_site_timezone_decides_which_day_a_sale_belongs_to(): void {
		update_option( 'timezone_string', 'Europe/Malta' );
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 100, 19, array( 'created' => '2026-08-31 22:30:00' ) );

		$this->assertSame( array(), $this->oss->build_for( ReportPeriod::month( 2026, 8 ) )['rows'], '22:30 UTC on 31 August is 00:30 on 1 September in Malta.' );
		$this->assertSame( 100.0, $this->oss->build_for( ReportPeriod::month( 2026, 9 ) )['rows'][0]['taxable'] );
	}

	private function rest_get( string $route, array $params ) {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		( new RestController( $this->oss, $this->esl, new IntrastatReport() ) )->register();
		rest_get_server();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$request = new WP_REST_Request( 'GET', '/vatright/v1' . $route );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}

	public function test_rest_reports_take_a_month_or_a_date_range(): void {
		$this->oss_quarter();
		$this->esl_quarter();

		$month = $this->rest_get(
			'/reports/oss',
			array(
				'year'  => 2026,
				'month' => 8,
			)
		);
		$this->assertSame( 200, $month->get_status() );
		$this->assertSame( '2026-08', $month->get_data()['period'] );
		$this->assertSame( 50.0, $month->get_data()['rows'][0]['taxable'] );

		$range = $this->rest_get(
			'/reports/oss',
			array(
				'from' => '2026-08-01',
				'to'   => '2026-08-10',
			)
		);
		$this->assertSame( 200, $range->get_status() );
		$this->assertSame( array( 'FR' ), array_column( $range->get_data()['rows'], 'member_state' ) );

		$esl = $this->rest_get(
			'/reports/ec-sales-list',
			array(
				'from' => '2026-08-15',
				'to'   => '2026-09-30',
			)
		)->get_data();
		$this->assertSame( '2026-08-15_2026-09-30', $esl['period'] );
		$this->assertSame( array( 'DE123456789', 'FR12345678901' ), array_column( $esl['rows'], 'customer_vat' ) );
	}

	public function test_rest_keeps_the_quarter_response_shape(): void {
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 100, 19, array( 'created' => '2026-07-15 10:00:00' ) );

		$data = $this->rest_get(
			'/reports/oss',
			array(
				'year'    => 2026,
				'quarter' => 3,
			)
		)->get_data();
		$esl  = $this->rest_get(
			'/reports/ec-sales-list',
			array(
				'year'    => 2026,
				'quarter' => 3,
			)
		)->get_data();

		$this->assertSame( 2026, $data['year'] );
		$this->assertSame( 3, $data['quarter'] );
		$this->assertSame( '2026-Q3', $data['period'] );
		$this->assertSame( array( 'year', 'quarter', 'period' ), array_slice( array_keys( $esl ), 0, 3 ) );
		$this->assertSame( '2026-Q3', $esl['period'] );
	}

	public function test_rest_rejects_an_unusable_period(): void {
		$cases = array(
			'nothing'         => array(),
			'year only'       => array( 'year' => 2026 ),
			'bad month'       => array(
				'year'  => 2026,
				'month' => 13,
			),
			'from without to' => array( 'from' => '2026-08-01' ),
			'reversed range'  => array(
				'from' => '2026-08-10',
				'to'   => '2026-08-01',
			),
			'impossible date' => array(
				'from' => '2026-02-30',
				'to'   => '2026-03-05',
			),
		);
		foreach ( $cases as $name => $params ) {
			foreach ( array( '/reports/oss', '/reports/ec-sales-list' ) as $route ) {
				$this->assertSame( 400, $this->rest_get( $route, $params )->get_status(), $name . ' ' . $route );
			}
		}
	}

	private function render_screen( array $get ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = $get;
		ob_start();
		( new ReportsScreen( $this->oss, $this->esl, new IntrastatReport() ) )->render();
		return (string) ob_get_clean();
	}

	public function test_the_screen_shows_the_chosen_month_and_posts_it_to_the_exports(): void {
		$this->oss_quarter();

		$html = $this->render_screen(
			array(
				'period' => 'month',
				'year'   => '2026',
				'month'  => '8',
			)
		);

		$this->assertStringContainsString( 'name="period"', $html );
		$this->assertMatchesRegularExpression( '/<option value="month"\s+selected=\'selected\'/', $html );
		$this->assertStringContainsString( 'name="from"', $html );
		$this->assertStringContainsString( 'name="to"', $html );
		$this->assertMatchesRegularExpression( '/<input type="hidden" name="period" value="month"/', $html );
		$this->assertStringContainsString( '200.00', $html );
		$this->assertStringNotContainsString( '>100.00<', $html, 'The July sale of 100 is not in August.' );
	}

	public function test_the_screen_shows_a_custom_range_and_posts_its_dates_to_the_exports(): void {
		$this->oss_quarter();

		$html = $this->render_screen(
			array(
				'period' => 'range',
				'from'   => '2026-08-15',
				'to'     => '2026-09-30',
			)
		);

		$this->assertStringContainsString( 'Sales made from 2026-08-15 to 2026-09-30.', $html );
		$this->assertMatchesRegularExpression( '/<input type="hidden" name="from" value="2026-08-15"/', $html );
		$this->assertMatchesRegularExpression( '/<input type="hidden" name="to" value="2026-09-30"/', $html );
		$this->assertStringNotContainsString( '>200.00<', $html, 'The FR sale was on 2 August.' );
	}

	public function test_the_screen_defaults_to_the_quarter_as_before(): void {
		$this->oss_quarter();

		$html = $this->render_screen(
			array(
				'year'    => '2026',
				'quarter' => '3',
			)
		);

		$this->assertMatchesRegularExpression( '/<option value="quarter"\s+selected=\'selected\'/', $html );
		$this->assertMatchesRegularExpression( '/<input type="hidden" name="quarter" value="3"/', $html );
		$this->assertStringContainsString( '>200.00<', $html );
		$this->assertStringContainsString( '>250.00<', $html );
	}

	public function test_an_unusable_range_falls_back_to_the_current_quarter(): void {
		$html = $this->render_screen(
			array(
				'period' => 'range',
				'from'   => '2026-09-30',
				'to'     => '2026-08-01',
			)
		);

		$this->assertMatchesRegularExpression( '/<option value="quarter"\s+selected=\'selected\'/', $html );
	}
}
