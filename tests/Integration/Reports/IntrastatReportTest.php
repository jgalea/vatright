<?php

namespace Vatright\Tests\Integration\Reports;

use Vatright\Domain\Decision;
use Vatright\Domain\Rule;
use Vatright\Domain\Scheme;
use Vatright\Intrastat\ProductData;
use Vatright\Ledger\LedgerWriter;
use Vatright\Ledger\Schema;
use Vatright\Reports\IncompleteReport;
use Vatright\Reports\IntrastatReport;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Simple;

require_once __DIR__ . '/ReportsTestCase.php';

class IntrastatReportTest extends ReportsTestCase {

	/**
	 * @param array<string,string> $meta
	 */
	private function physical( string $name, array $meta = array(), string $weight = '' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '100' );
		$product->set_weight( $weight );
		foreach ( $meta as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}
		$product->save();
		return $product;
	}

	/**
	 * @return array<string,string>
	 */
	private function declared( string $cn8 = '61091000', string $origin = 'CN', string $mass = '0.4' ): array {
		return array(
			ProductData::CN8      => $cn8,
			ProductData::ORIGIN   => $origin,
			ProductData::NET_MASS => $mass,
		);
	}

	/**
	 * A goods sale of $qty units of $product, $net in total, shipped to
	 * $dest. A VAT number makes it an intra-EU supply, none an OSS sale.
	 *
	 * @param array<string,mixed> $order_args
	 */
	private function dispatch( WC_Product_Simple $product, int $qty, float $net, string $dest, ?string $vat = null, array $order_args = array() ): WC_Order {
		$scheme = null === $vat ? Scheme::OSS_DESTINATION : Scheme::INTRA_EU_GOODS;
		$order  = $this->make_order(
			array_merge(
				array(
					'items' => array(
						array(
							'name' => $product->get_name(),
							'net'  => $net,
						),
					),
				),
				$order_args
			)
		);
		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$item->set_product_id( $product->get_id() );
			$item->set_quantity( $qty );
			$item->save();
		}
		$this->seed_decision( $order, $scheme, null === $vat ? Rule::B2C_DESTINATION : Rule::RC_GOODS_ART138, array( $product->get_name() => array( 'goods', $dest, $scheme ) ), $vat );
		$this->ship_to( $order, $dest );
		$this->writer->record_order( $order );
		return $order;
	}

	private function ship_to( WC_Order $order, string $country ): void {
		$data                                    = json_decode( (string) $order->get_meta( LedgerWriter::DECISION_META ), true );
		$data['customer']['shipping']['country'] = $country;
		$data['customer']['billing']['country']  = $country;
		$order->update_meta_data( LedgerWriter::DECISION_META, wp_json_encode( Decision::from_array( $data )->to_array() ) );
		$order->save_meta_data();
	}

	public function test_groups_by_partner_cn8_nature_origin_and_partner_vat(): void {
		$shirt = $this->physical( 'Shirt', $this->declared( '61091000', 'CN', '0.4' ) );
		$mug   = $this->physical( 'Mug', $this->declared( '69120050', 'PT', '0.3' ) );
		$this->dispatch( $shirt, 2, 40.0, 'DE', 'DE123456789' );
		$this->dispatch( $shirt, 3, 60.0, 'DE', 'DE123456789' );
		$this->dispatch( $mug, 1, 12.5, 'DE', 'DE123456789' );
		$this->dispatch( $shirt, 1, 20.0, 'FR', 'FR12345678901' );
		$this->dispatch( $shirt, 1, 20.0, 'DE', 'DE999999999' );

		$report = ( new IntrastatReport() )->build( 2026, 9 );

		$this->assertSame( '2026-09', $report['period'] );
		$this->assertSame(
			array(
				array(
					'partner_country' => 'DE',
					'cn8'             => '61091000',
					'nature'          => '11',
					'origin'          => 'CN',
					'partner_vat'     => 'DE123456789',
					'net_mass'        => 2.0,
					'value_eur'       => 100.0,
					'supp_units'      => null,
				),
				array(
					'partner_country' => 'DE',
					'cn8'             => '61091000',
					'nature'          => '11',
					'origin'          => 'CN',
					'partner_vat'     => 'DE999999999',
					'net_mass'        => 0.4,
					'value_eur'       => 20.0,
					'supp_units'      => null,
				),
				array(
					'partner_country' => 'DE',
					'cn8'             => '69120050',
					'nature'          => '11',
					'origin'          => 'PT',
					'partner_vat'     => 'DE123456789',
					'net_mass'        => 0.3,
					'value_eur'       => 12.5,
					'supp_units'      => null,
				),
				array(
					'partner_country' => 'FR',
					'cn8'             => '61091000',
					'nature'          => '11',
					'origin'          => 'CN',
					'partner_vat'     => 'FR12345678901',
					'net_mass'        => 0.4,
					'value_eur'       => 20.0,
					'supp_units'      => null,
				),
			),
			$report['rows']
		);
		$this->assertSame( array(), $report['missing'] );
	}

	public function test_only_physical_goods_leaving_for_another_member_state_in_the_month_count(): void {
		$shirt = $this->physical( 'Shirt', $this->declared() );
		$this->dispatch( $shirt, 1, 10.0, 'DE', 'DE123456789' );
		$this->dispatch( $shirt, 1, 99.0, 'MT', null );
		$this->dispatch( $shirt, 1, 99.0, 'US', null );
		$this->dispatch( $shirt, 1, 99.0, 'CH', null );
		$this->dispatch( $shirt, 1, 99.0, 'DE', 'DE123456789', array( 'created' => '2026-08-31 23:00:00' ) );
		$this->dispatch( $shirt, 1, 99.0, 'DE', 'DE123456789', array( 'created' => '2026-10-01 00:00:00' ) );
		$this->reverse_charge( 'digital', 500, 'DE123456789' );
		$cancelled = $this->dispatch( $shirt, 1, 99.0, 'DE', 'DE123456789' );
		$this->writer->void_order( $cancelled );

		$rows = ( new IntrastatReport() )->build( 2026, 9 )['rows'];

		$this->assertCount( 1, $rows );
		$this->assertSame( 10.0, $rows[0]['value_eur'] );
	}

	public function test_a_consumer_sale_is_a_dispatch_with_no_partner_vat_number(): void {
		$this->dispatch( $this->physical( 'Shirt', $this->declared() ), 1, 30.0, 'ES', null );

		$rows = ( new IntrastatReport() )->build( 2026, 9 )['rows'];

		$this->assertSame( 'ES', $rows[0]['partner_country'] );
		$this->assertSame( '', $rows[0]['partner_vat'] );
	}

	public function test_consumer_lines_are_direct_trade_with_private_consumers_and_business_lines_outright_sales(): void {
		$shirt = $this->physical( 'Shirt', $this->declared() );
		$this->dispatch( $shirt, 1, 30.0, 'ES', null );
		$this->dispatch( $shirt, 1, 30.0, 'ES', 'ESB12345674' );

		$natures = array_column( ( new IntrastatReport() )->build( 2026, 9 )['rows'], 'nature', 'partner_vat' );
		ksort( $natures );

		$this->assertSame(
			array(
				''            => '12',
				'ESB12345674' => '11',
			),
			$natures
		);
	}

	public function test_nature_filter_receives_the_default_for_the_line_kind_so_a_merchant_can_override_it(): void {
		$this->dispatch( $this->physical( 'Shirt', $this->declared() ), 1, 30.0, 'ES', null );
		$seen = array();
		add_filter(
			'vatright_intrastat_nature_of_transaction',
			static function ( $code, $item, $order ) use ( &$seen ) {
				$seen[] = array( $code, $item instanceof WC_Order_Item_Product, $order instanceof WC_Order );
				return $code;
			},
			10,
			3
		);

		( new IntrastatReport() )->build( 2026, 9 );

		$this->assertSame( array( array( '12', true, true ) ), $seen );
	}

	public function test_greece_uses_the_eu_code(): void {
		$this->dispatch( $this->physical( 'Shirt', $this->declared( '61091000', 'GR' ) ), 1, 30.0, 'GR', 'EL123456789' );

		$row = ( new IntrastatReport() )->build( 2026, 9 )['rows'][0];

		$this->assertSame( 'EL', $row['partner_country'] );
		$this->assertSame( 'EL', $row['origin'] );
	}

	public function test_month_follows_the_site_timezone(): void {
		update_option( 'timezone_string', 'Europe/Malta' );
		$shirt = $this->physical( 'Shirt', $this->declared() );
		$this->dispatch( $shirt, 1, 10.0, 'DE', 'DE123456789', array( 'created' => '2026-09-30 22:30:00' ) );

		$this->assertSame( array(), ( new IntrastatReport() )->build( 2026, 9 )['rows'] );
		$this->assertCount( 1, ( new IntrastatReport() )->build( 2026, 10 )['rows'] );
	}

	public function test_net_mass_defaults_to_the_weight_in_kilograms(): void {
		update_option( 'woocommerce_weight_unit', 'g' );
		$this->dispatch(
			$this->physical(
				'Shirt',
				array(
					ProductData::CN8    => '61091000',
					ProductData::ORIGIN => 'CN',
				),
				'250'
			),
			4,
			80.0,
			'DE',
			'DE123456789'
		);

		$row = ( new IntrastatReport() )->build( 2026, 9 )['rows'][0];

		$this->assertSame( 1.0, $row['net_mass'] );
	}

	public function test_an_explicit_net_mass_beats_the_weight(): void {
		update_option( 'woocommerce_weight_unit', 'kg' );
		$this->dispatch( $this->physical( 'Shirt', $this->declared( '61091000', 'CN', '0.25' ), '2' ), 2, 80.0, 'DE', 'DE123456789' );

		$this->assertSame( 0.5, ( new IntrastatReport() )->build( 2026, 9 )['rows'][0]['net_mass'] );
	}

	public function test_supplementary_units_are_summed_when_set(): void {
		$meta = $this->declared() + array( ProductData::SUPP_UNITS => '2' );
		$this->dispatch( $this->physical( 'Socks', $meta ), 3, 30.0, 'DE', 'DE123456789' );

		$this->assertSame( 6.0, ( new IntrastatReport() )->build( 2026, 9 )['rows'][0]['supp_units'] );
	}

	public function test_nature_of_transaction_can_be_changed_per_line(): void {
		add_filter(
			'vatright_intrastat_nature_of_transaction',
			static function ( $code, $item, $order ) {
				return $item instanceof WC_Order_Item_Product && $order instanceof WC_Order ? '21' : $code;
			},
			10,
			3
		);
		$this->dispatch( $this->physical( 'Shirt', $this->declared() ), 1, 10.0, 'DE', 'DE123456789' );

		$this->assertSame( '21', ( new IntrastatReport() )->build( 2026, 9 )['rows'][0]['nature'] );
	}

	public function test_non_euro_orders_use_the_rate_stored_with_the_order(): void {
		$order = $this->make_order(
			array(
				'currency' => 'USD',
				'items'    => array(
					array(
						'name' => 'Shirt',
						'net'  => 200.0,
					),
				),
			)
		);
		$order->update_meta_data(
			LedgerWriter::FX_META,
			array(
				'currency'  => 'USD',
				'eur_rate'  => 2.0,
				'rate_date' => '2026-09-15',
				'source'    => 'ecb',
			)
		);
		$order->save_meta_data();
		$shirt = $this->physical( 'Shirt', $this->declared() );
		foreach ( $order->get_items() as $item ) {
			$item->set_product_id( $shirt->get_id() );
			$item->save();
		}
		$this->seed_decision( $order, Scheme::INTRA_EU_GOODS, Rule::RC_GOODS_ART138, array( 'Shirt' => array( 'goods', 'DE', Scheme::INTRA_EU_GOODS ) ), 'DE123456789' );
		$this->ship_to( $order, 'DE' );
		$this->writer->record_order( $order );

		$this->assertSame( 100.0, ( new IntrastatReport() )->build( 2026, 9 )['rows'][0]['value_eur'] );
	}

	public function test_a_refund_of_some_units_reduces_value_and_mass(): void {
		$shirt  = $this->physical( 'Shirt', $this->declared( '61091000', 'CN', '0.5' ) );
		$order  = $this->dispatch( $shirt, 4, 100.0, 'DE', 'DE123456789' );
		$item   = array_values( $order->get_items() )[0];
		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => '25',
				'line_items' => array(
					$item->get_id() => array(
						'qty'          => 1,
						'refund_total' => 25,
					),
				),
			)
		);
		$this->assertNotWPError( $refund );
		$refund->set_date_created( strtotime( '2026-10-05 10:00:00 UTC' ) );
		$refund->save();

		$row = ( new IntrastatReport() )->build( 2026, 9 )['rows'][0];

		$this->assertSame( 75.0, $row['value_eur'] );
		$this->assertSame( 1.5, $row['net_mass'] );
	}

	public function test_a_fully_refunded_line_drops_out(): void {
		$shirt  = $this->physical( 'Shirt', $this->declared() );
		$order  = $this->dispatch( $shirt, 1, 50.0, 'DE', 'DE123456789' );
		$item   = array_values( $order->get_items() )[0];
		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => '50',
				'line_items' => array(
					$item->get_id() => array(
						'qty'          => 1,
						'refund_total' => 50,
					),
				),
			)
		);
		$this->assertNotWPError( $refund );

		$this->assertSame( array(), ( new IntrastatReport() )->build( 2026, 9 )['rows'] );
	}

	public function test_products_without_the_filing_fields_are_listed(): void {
		$bare = $this->physical( 'Bare' );
		$part = $this->physical( 'Part', array( ProductData::CN8 => '61091000' ) );
		$this->dispatch( $bare, 1, 10.0, 'DE', 'DE123456789' );
		$this->dispatch( $part, 1, 10.0, 'DE', 'DE123456789' );
		$this->dispatch( $this->physical( 'Full', $this->declared() ), 1, 10.0, 'DE', 'DE123456789' );

		$missing = ( new IntrastatReport() )->build( 2026, 9 )['missing'];

		$this->assertSame(
			array(
				array(
					'product_id' => $bare->get_id(),
					'name'       => 'Bare',
					'fields'     => array( 'cn8', 'origin', 'net_mass' ),
				),
				array(
					'product_id' => $part->get_id(),
					'name'       => 'Part',
					'fields'     => array( 'origin', 'net_mass' ),
				),
			),
			$missing
		);
	}

	public function test_report_is_incomplete_when_an_order_has_no_eur_amount(): void {
		$order = $this->dispatch( $this->physical( 'Shirt', $this->declared() ), 1, 10.0, 'DE', 'DE123456789' );
		global $wpdb;
		$wpdb->update( Schema::ledger_table(), array( 'net_eur' => null ), array( 'order_id' => $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		try {
			( new IntrastatReport() )->build( 2026, 9 );
			$this->fail( 'Expected IncompleteReport.' );
		} catch ( IncompleteReport $e ) {
			$this->assertSame( array( $order->get_id() ), $e->order_ids() );
		}
	}

	public function test_csv_columns_and_formatting(): void {
		$meta = $this->declared( '61091000', 'CN', '0.4' ) + array( ProductData::SUPP_UNITS => '1' );
		$this->dispatch( $this->physical( 'Shirt', $meta ), 2, 40.5, 'DE', 'DE123456789' );
		$this->dispatch( $this->physical( 'Shirt', $this->declared() ), 1, 5.0, 'FR', null );

		list( $header, $lines ) = IntrastatReport::csv( ( new IntrastatReport() )->build( 2026, 9 ) );

		$this->assertSame(
			array( 'Partner member state', 'CN8 commodity code', 'Nature of transaction', 'Country of origin', 'Partner VAT number', 'Net mass (kg)', 'Invoice value (EUR)', 'Supplementary units' ),
			$header
		);
		$this->assertSame(
			array(
				array( 'DE', '61091000', '11', 'CN', 'DE123456789', '0.800', '40.50', '2' ),
				array( 'FR', '61091000', '12', 'CN', '', '0.400', '5.00', '' ),
			),
			$lines
		);
	}
}
