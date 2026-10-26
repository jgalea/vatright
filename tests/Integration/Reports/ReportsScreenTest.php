<?php

namespace Vatright\Tests\Integration\Reports;

use Vatright\Ledger\Fx;
use Vatright\Ledger\Schema;
use Vatright\Domain\Rule;
use Vatright\Domain\Scheme;
use Vatright\Reports\EcSalesList;
use Vatright\Reports\IntrastatReport;
use Vatright\Reports\OssReport;
use Vatright\Reports\ReportsScreen;
use RuntimeException;
use WC_Order;

require_once __DIR__ . '/ReportsTestCase.php';

class ReportsScreenTest extends ReportsTestCase {

	/** @var ReportsScreen */
	private $screen;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->screen = new ReportsScreen( new OssReport( new Fx( $this->transport ) ), new EcSalesList(), new IntrastatReport() );
		$_GET         = array(
			'year'    => '2026',
			'quarter' => '3',
		);
	}

	public function tear_down() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	private function without_eur_amount( WC_Order $order ): void {
		global $wpdb;
		$wpdb->update( Schema::ledger_table(), array( 'net_eur' => null ), array( 'order_id' => $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function render(): string {
		ob_start();
		$this->screen->render();
		return (string) ob_get_clean();
	}

	public function test_screen_lists_the_ec_sales_list_with_its_download_button(): void {
		$this->reverse_charge( 'digital', 100, 'DE123456789' );

		$html = $this->render();

		$this->assertStringContainsString( 'DE123456789', $html );
		$this->assertStringContainsString( 'vatright_export_esl', $html );
		$this->assertStringNotContainsString( 'notice-error', $html );
	}

	public function test_screen_warns_instead_of_listing_when_an_order_has_no_eur_amount(): void {
		$this->reverse_charge( 'digital', 100, 'DE123456789' );
		$incomplete = $this->reverse_charge( 'digital', 50, 'FR12345678901' );
		$this->without_eur_amount( $incomplete );

		$html = $this->render();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'No ECB rate was available for these orders, so the list is not shown: ' . $incomplete->get_id(), $html );
		$this->assertStringNotContainsString( 'DE123456789', $html );
		$this->assertStringNotContainsString( 'vatright_export_esl', $html );
		$this->assertStringContainsString( 'vatright_export_oss', $html );
	}

	public function test_esl_export_dies_naming_the_orders_when_the_report_is_incomplete(): void {
		$incomplete = $this->reverse_charge( 'digital', 50, 'FR12345678901' );
		$this->without_eur_amount( $incomplete );
		$_POST                = array(
			'year'    => '2026',
			'quarter' => '3',
		);
		$_REQUEST['_wpnonce'] = wp_create_nonce( ReportsScreen::NONCE );
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ) {
					throw new RuntimeException( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput
				};
			}
		);

		try {
			$this->screen->export_esl();
			$this->fail( 'Expected the export to stop.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'The EC Sales List cannot be exported: no ECB rate was available for orders ' . $incomplete->get_id() . '.', $e->getMessage() );
		}
	}

	private function intrastat_order(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Shirt' );
		$product->update_meta_data( \Vatright\Intrastat\ProductData::CN8, '61091000' );
		$product->update_meta_data( \Vatright\Intrastat\ProductData::ORIGIN, 'CN' );
		$product->update_meta_data( \Vatright\Intrastat\ProductData::NET_MASS, '0.4' );
		$product->save();
		$order = $this->make_order(
			array(
				'items' => array(
					array(
						'name' => 'Shirt',
						'net'  => 40,
					),
				),
			)
		);
		foreach ( $order->get_items() as $item ) {
			$item->set_product_id( $product->get_id() );
			$item->save();
		}
		$this->seed_decision( $order, Scheme::INTRA_EU_GOODS, Rule::RC_GOODS_ART138, array( 'Shirt' => array( 'goods', 'DE', Scheme::INTRA_EU_GOODS ) ), 'DE123456789' );
		$this->writer->record_order( $order );
	}

	public function test_screen_lists_intrastat_dispatches_for_the_chosen_month_with_a_download_button(): void {
		$this->intrastat_order();
		$_GET['month'] = '9';

		$html = $this->render();

		$this->assertStringContainsString( 'INTRASTAT', $html );
		$this->assertStringContainsString( '61091000', $html );
		$this->assertStringContainsString( 'DE123456789', $html );
		$this->assertStringContainsString( 'vatright_export_intrastat', $html );
		$this->assertStringContainsString( 'name="month"', $html );
		$this->assertStringContainsString( 'thresholds', $html );
		$this->assertStringContainsString( 'not the filing itself', $html );
	}

	public function test_screen_shows_no_dispatches_for_another_month(): void {
		$this->intrastat_order();
		$_GET['month'] = '8';

		$this->assertStringContainsString( 'No dispatches to other member states in this month.', $this->render() );
	}

	public function test_intrastat_export_dies_naming_the_orders_when_the_report_is_incomplete(): void {
		$this->intrastat_order();
		global $wpdb;
		$wpdb->query( 'UPDATE ' . \Vatright\Ledger\Schema::ledger_table() . ' SET net_eur = NULL' ); // phpcs:ignore WordPress.DB
		$_POST                = array(
			'year'  => '2026',
			'month' => '9',
		);
		$_REQUEST['_wpnonce'] = wp_create_nonce( ReportsScreen::NONCE );
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ) {
					throw new RuntimeException( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput
				};
			}
		);

		try {
			$this->screen->export_intrastat();
			$this->fail( 'Expected the export to stop.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringStartsWith( 'The INTRASTAT report cannot be exported: no ECB rate was available for orders ', $e->getMessage() );
		}
	}

	public function test_intrastat_export_needs_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ) {
					throw new RuntimeException( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput
				};
			}
		);

		$this->expectException( RuntimeException::class );
		$this->screen->export_intrastat();
	}
}
