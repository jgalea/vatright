<?php

namespace Vatright\Tests\Integration\Reports;

use Vatright\Domain\Scheme;
use Vatright\Ledger\Fx;
use Vatright\Ledger\Schema;
use Vatright\Reports\EcSalesList;
use Vatright\Reports\IntrastatReport;
use Vatright\Reports\OssReport;
use Vatright\Reports\RestController;
use WP_REST_Request;

require_once __DIR__ . '/ReportsTestCase.php';

class RestControllerTest extends ReportsTestCase {

	public function set_up() {
		parent::set_up();
		$this->reset_rest_server();
		( new RestController( new OssReport( new Fx( $this->transport ) ), new EcSalesList(), new IntrastatReport() ) )->register();
		rest_get_server();
	}

	public function tear_down() {
		$this->reset_rest_server();
		parent::tear_down();
	}

	private function reset_rest_server(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function get( string $route, array $params = array() ) {
		$request = new WP_REST_Request( 'GET', '/vatright/v1' . $route );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}

	private function act_as( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	public function test_rest_requires_capability(): void {
		$order  = $this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 100, 19 );
		$routes = array(
			'/reports/oss'                         => array(
				'year'    => 2026,
				'quarter' => 3,
			),
			'/reports/ec-sales-list'               => array(
				'year'    => 2026,
				'quarter' => 3,
			),
			'/reports/intrastat'                   => array(
				'year'  => 2026,
				'month' => 9,
			),
			'/orders/' . $order->get_id() . '/vat' => array(),
		);

		$this->act_as( 'customer' );
		foreach ( $routes as $route => $params ) {
			$this->assertSame( 403, $this->get( $route, $params )->get_status(), $route );
		}

		wp_set_current_user( 0 );
		foreach ( $routes as $route => $params ) {
			$this->assertSame( 401, $this->get( $route, $params )->get_status(), $route );
		}

		$this->act_as( 'shop_manager' );
		foreach ( $routes as $route => $params ) {
			$this->assertSame( 200, $this->get( $route, $params )->get_status(), $route );
		}
	}

	public function test_rest_shapes(): void {
		$order = $this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 100, 19 );
		$this->reverse_charge( 'digital', 40, 'DE123456789' );
		$fallback = $this->sell_at_store_rate( 'IT', 50 );
		$order->add_meta_data(
			'_vatright_log',
			wp_json_encode(
				array(
					'trigger' => 'checkout',
					'scheme'  => 'oss_destination',
				)
			)
		);
		$order->add_meta_data(
			'_vatright_log',
			wp_json_encode(
				array(
					'trigger' => 'admin',
					'scheme'  => 'origin',
				)
			)
		);
		$order->save_meta_data();
		$this->act_as( 'shop_manager' );

		$oss = $this->get(
			'/reports/oss',
			array(
				'year'    => '2026',
				'quarter' => '3',
			)
		)->get_data();
		$this->assertSame( array( 'kind', 'year', 'quarter', 'period', 'from', 'to', 'currency', 'rate_rule', 'rows', 'corrections', 'needs_correction', 'rates' ), array_keys( $oss ) );
		$this->assertCount( 1, $oss['rows'] );
		$this->assertSame( 'DE', $oss['rows'][0]['member_state'] );
		$this->assertSame( 100.0, $oss['rows'][0]['taxable'] );
		$this->assertSame( array( $fallback->get_id() ), array_column( $oss['needs_correction'], 'order_id' ) );

		$esl = $this->get(
			'/reports/ec-sales-list',
			array(
				'year'    => '2026',
				'quarter' => '3',
			)
		)->get_data();
		$this->assertSame( '2026-Q3', $esl['period'] );
		$this->assertSame( 'DE123456789', $esl['rows'][0]['customer_vat'] );

		$vat = $this->get( '/orders/' . $order->get_id() . '/vat' )->get_data();
		$this->assertSame( $order->get_id(), $vat['order_id'] );
		$this->assertSame( 'oss_destination', $vat['decision']['scheme'] );
		$this->assertSame( array( 'checkout', 'admin' ), array_column( $vat['log'], 'trigger' ) );
	}

	public function test_rest_rejects_bad_arguments_and_unknown_orders(): void {
		$this->act_as( 'shop_manager' );

		$this->assertSame(
			400,
			$this->get(
				'/reports/oss',
				array(
					'year'    => 2026,
					'quarter' => 5,
				)
			)->get_status()
		);
		$this->assertSame( 400, $this->get( '/reports/oss', array( 'year' => 2026 ) )->get_status() );
		$this->assertSame(
			400,
			$this->get(
				'/reports/oss',
				array(
					'year'     => 2026,
					'quarter'  => 3,
					'currency' => 'EURO',
				)
			)->get_status()
		);
		$this->assertSame( 404, $this->get( '/orders/99999999/vat' )->get_status() );
	}

	public function test_rest_esl_incomplete_returns_502_with_order_ids(): void {
		$order = $this->reverse_charge( 'digital', 100, 'DE123456789' );
		global $wpdb;
		$wpdb->update( Schema::ledger_table(), array( 'net_eur' => null ), array( 'order_id' => $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->act_as( 'shop_manager' );

		$response = $this->get(
			'/reports/ec-sales-list',
			array(
				'year'    => 2026,
				'quarter' => 3,
			)
		);

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( array( $order->get_id() ), $response->get_data()['data']['order_ids'] );
	}

	public function test_rest_reports_unavailable_rate(): void {
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 100, 19, array( 'currency' => 'GBP' ) );
		$this->act_as( 'shop_manager' );

		$response = $this->get(
			'/reports/oss',
			array(
				'year'    => 2026,
				'quarter' => 3,
			)
		);

		$this->assertSame( 502, $response->get_status() );
	}

	public function test_intrastat_shape_and_validation(): void {
		$this->act_as( 'shop_manager' );

		$data = $this->get(
			'/reports/intrastat',
			array(
				'year'  => 2026,
				'month' => 9,
			)
		)->get_data();

		$this->assertSame( 2026, $data['year'] );
		$this->assertSame( 9, $data['month'] );
		$this->assertSame( '2026-09', $data['period'] );
		$this->assertSame( array(), $data['rows'] );
		$this->assertSame( array(), $data['missing'] );

		foreach ( array( 0, 13 ) as $month ) {
			$this->assertSame(
				400,
				$this->get(
					'/reports/intrastat',
					array(
						'year'  => 2026,
						'month' => $month,
					)
				)->get_status()
			);
		}
		$this->assertSame( 400, $this->get( '/reports/intrastat', array( 'year' => 2026 ) )->get_status() );
	}
}
