<?php

namespace Vatright\Tests\Integration\Ledger;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Vatright\Checkout\TaxLocation;
use Vatright\Domain\Rule;
use Vatright\Domain\Scheme;
use Vatright\Ledger\LedgerWriter;
use WC_Order;
use WC_Tax;

require_once __DIR__ . '/LedgerTestCase.php';

class LedgerWriterTest extends LedgerTestCase {

	public function test_order_storage_matches_the_mode_under_test(): void {
		$this->assertSame( $this->storage_is_hpos(), OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	public function test_sale_rows_match_order_tax_lines(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$reduced  = $this->tax_rate( 'DE', '7.0000' );
		$order    = $this->make_order(
			array(
				'items'    => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
					array(
						'name'    => 'Book',
						'net'     => 50,
						'rate_id' => $reduced,
						'percent' => 7,
					),
				),
				'shipping' => array(
					array(
						'name'    => 'Post',
						'net'     => 10,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
				'fees'     => array(
					array(
						'name'    => 'Handling',
						'net'     => 5,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );

		$this->writer->record_order( $order );

		$rows = $this->ledger_rows();
		$this->assertCount( 2, $rows );
		$by_rate = array_column( $rows, null, 'rate_percent' );
		$this->assertSame( '115.0000', $by_rate['19.0000']['net'] );
		$this->assertSame( '21.8500', $by_rate['19.0000']['vat'] );
		$this->assertSame( '50.0000', $by_rate['7.0000']['net'] );
		$this->assertSame( '3.5000', $by_rate['7.0000']['vat'] );
		$this->assertEqualsWithDelta( 165.0, array_sum( array_column( $rows, 'net' ) ), 0.0001 );
		$this->assertEqualsWithDelta( (float) $order->get_total(), array_sum( array_column( $rows, 'net' ) ) + array_sum( array_column( $rows, 'vat' ) ), 0.0001 );
		$this->assertSame( 'sale', $rows[0]['entry_type'] );
		$this->assertSame( Scheme::OSS_DESTINATION, $rows[0]['scheme'] );
		$this->assertSame( Rule::B2C_DESTINATION, $rows[0]['rule'] );
		$this->assertSame( 'DE', $rows[0]['customer_country'] );
		$this->assertSame( 'EUR', $rows[0]['currency'] );
		$this->assertSame( '2026-Q3', $rows[0]['period'] );
		$this->assertSame( '115.0000', $by_rate['19.0000']['net_eur'] );
	}

	public function test_mixed_cart_lines_keep_their_own_supply_type(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'items' => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
					array(
						'name'    => 'Ebook',
						'net'     => 20,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order, Scheme::MIXED, Rule::B2C_DESTINATION, array( 'Ebook' => array( 'digital', 'DE', Scheme::OSS_DESTINATION ) ) );

		$this->writer->record_order( $order );

		$by_supply = array_column( $this->ledger_rows(), null, 'supply_type' );
		$this->assertSame( '100.0000', $by_supply['goods']['net'] );
		$this->assertSame( '20.0000', $by_supply['digital']['net'] );
		$this->assertSame( Scheme::OSS_DESTINATION, $by_supply['digital']['scheme'] );
	}

	/**
	 * KG-9: rows of a rate_missing decision are marked only where the VAT was
	 * charged at another country's rate than the line's tax country.
	 */
	public function test_rows_charged_at_the_store_rate_are_marked_rate_missing(): void {
		$mt       = $this->tax_rate( 'MT', '18' );
		$fr       = $this->tax_rate( 'FR', '20' );
		$order    = $this->make_order(
			array(
				'items' => array(
					array(
						'name'    => 'Book',
						'net'     => 100,
						'rate_id' => $mt,
						'percent' => 18.0,
					),
					array(
						'name'    => 'Ebook',
						'net'     => 10,
						'rate_id' => $fr,
						'percent' => 20.0,
					),
				),
			)
		);
		$decision = $this->seed_decision(
			$order,
			Scheme::OSS_DESTINATION,
			Rule::B2C_DESTINATION,
			array(
				'Book'  => array( 'goods', 'IT', Scheme::OSS_DESTINATION ),
				'Ebook' => array( 'digital', 'FR', Scheme::OSS_DESTINATION ),
			)
		);

		$this->writer->record_order( $order );
		$this->assertSame( array( '0', '0' ), array_column( $this->ledger_rows(), 'rate_missing' ), 'Control: without the flag nothing is marked' );

		$order->update_meta_data( LedgerWriter::DECISION_META, wp_json_encode( $decision->with_flag( TaxLocation::MISSING_FLAG )->to_array() ) );
		$order->save_meta_data();
		$this->writer->record_order( $order );

		$marked = array_column( $this->ledger_rows(), 'rate_missing', 'place_of_supply' );
		$this->assertSame(
			array(
				'IT' => '1',
				'FR' => '0',
			),
			$marked
		);
	}

	public function test_rate_missing_keeps_the_rate_country_from_the_first_write(): void {
		$mt       = $this->tax_rate( 'MT', '18' );
		$order    = $this->make_order(
			array(
				'items' => array(
					array(
						'name'    => 'Book',
						'net'     => 100,
						'rate_id' => $mt,
						'percent' => 18.0,
					),
				),
			)
		);
		$decision = $this->seed_decision(
			$order,
			Scheme::OSS_DESTINATION,
			Rule::B2C_DESTINATION,
			array( 'Book' => array( 'goods', 'IT', Scheme::OSS_DESTINATION ) )
		);
		$order->update_meta_data( LedgerWriter::DECISION_META, wp_json_encode( $decision->with_flag( TaxLocation::MISSING_FLAG )->to_array() ) );
		$order->save_meta_data();

		$this->writer->record_order( $order );
		$this->assertSame( array( '1' ), array_column( $this->ledger_rows(), 'rate_missing' ) );

		WC_Tax::_update_tax_rate( $mt, array( 'tax_rate_country' => 'IT' ) );
		$this->writer->record_order( wc_get_order( $order->get_id() ) );
		$this->assertSame( array( '1' ), array_column( $this->ledger_rows(), 'rate_missing' ), 'Editing the rate later leaves the order as it was charged.' );

		WC_Tax::_delete_tax_rate( $mt );
		$this->writer->record_order( wc_get_order( $order->get_id() ) );
		$this->assertSame( array( '1' ), array_column( $this->ledger_rows(), 'rate_missing' ), 'Deleting the rate later leaves the order as it was charged.' );
	}

	public function test_record_is_idempotent(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'items' => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );

		$this->writer->record_order( $order );
		$this->writer->record_order( $order );

		$this->assertCount( 1, $this->ledger_rows() );
	}

	public function test_order_without_a_decision_writes_nothing(): void {
		$order = $this->make_order(
			array(
				'items' => array(
					array(
						'name' => 'Gadget',
						'net'  => 10,
					),
				),
			)
		);

		$this->writer->record_order( $order );

		$this->assertSame( array(), $this->ledger_rows() );
	}

	public function test_exempt_order_writes_one_zero_rate_row_with_the_vat_number(): void {
		$order = $this->make_order(
			array(
				'items' => array(
					array(
						'name' => 'Consulting',
						'net'  => 200,
					),
				),
			)
		);
		$this->seed_decision( $order, Scheme::REVERSE_CHARGE_SERVICES, Rule::RC_SERVICES_ART196, array( 'Consulting' => array( 'service', 'DE', Scheme::REVERSE_CHARGE_SERVICES ) ), 'DE123456789' );

		$this->writer->record_order( $order );

		$rows = $this->ledger_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( '0.0000', $rows[0]['rate_percent'] );
		$this->assertSame( '200.0000', $rows[0]['net'] );
		$this->assertSame( '0.0000', $rows[0]['vat'] );
		$this->assertSame( 'DE123456789', $rows[0]['customer_vat'] );
	}

	public function test_vat_number_is_not_recorded_on_charged_rows(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'items' => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order, Scheme::OSS_DESTINATION, Rule::INVALID_NUMBER, array(), 'DE123456789' );

		$this->writer->record_order( $order );

		$this->assertNull( $this->ledger_rows()[0]['customer_vat'] );
	}

	public function test_refund_writes_negative_rows_in_refund_period_with_relates_to(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'items' => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );
		$this->writer->record_order( $order );
		$item_id = array_key_first( $order->get_items() );

		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => '119',
				'line_items' => array(
					$item_id => array(
						'qty'          => 1,
						'refund_total' => 100,
						'refund_tax'   => array( $standard => 19 ),
					),
				),
			)
		);
		$this->assertNotWPError( $refund );
		$refund->set_date_created( strtotime( '2026-10-05 10:00:00 UTC' ) );
		$refund->save();

		$this->writer->record_refund( $order->get_id(), $refund->get_id() );

		$rows = $this->ledger_rows( "entry_type = 'refund'" );
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $refund->get_id(), $rows[0]['refund_id'] );
		$this->assertSame( '-100.0000', $rows[0]['net'] );
		$this->assertSame( '-19.0000', $rows[0]['vat'] );
		$this->assertSame( '2026-Q4', $rows[0]['period'] );
		$this->assertSame( '2026-Q3', $rows[0]['relates_to_period'] );
		$this->assertSame( '2026-Q3', $this->ledger_rows( "entry_type = 'sale'" )[0]['period'] );
	}

	public function test_partial_refund_proportional(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$reduced  = $this->tax_rate( 'DE', '7.0000' );
		$order    = $this->make_order(
			array(
				'items' => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
					array(
						'name'    => 'Book',
						'net'     => 100,
						'rate_id' => $reduced,
						'percent' => 7,
					),
				),
			)
		);
		$this->seed_decision( $order );
		$this->writer->record_order( $order );
		$half = (string) ( (float) $order->get_total() / 2 );

		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $half,
			)
		);
		$this->assertNotWPError( $refund );

		$this->writer->record_refund( $order->get_id(), $refund->get_id() );

		$rows = array_column( $this->ledger_rows( "entry_type = 'refund'" ), null, 'rate_percent' );
		$this->assertCount( 2, $rows );
		$this->assertSame( '-50.0000', $rows['19.0000']['net'] );
		$this->assertSame( '-9.5000', $rows['19.0000']['vat'] );
		$this->assertSame( '-50.0000', $rows['7.0000']['net'] );
		$this->assertSame( '-3.5000', $rows['7.0000']['vat'] );
	}

	public function test_refund_hook_records_through_wc_create_refund(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'items' => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );
		$this->writer->register();
		$this->writer->record_order( $order );

		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '59.5',
			)
		);

		$this->assertCount( 1, $this->ledger_rows( "entry_type = 'refund'" ) );
	}

	public function test_cancelled_unpaid_order_has_no_rows(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'status' => 'pending',
				'items'  => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );
		$this->writer->register();

		$order->update_status( 'cancelled' );

		$this->assertSame( array(), $this->ledger_rows() );
	}

	public function test_paid_then_cancelled_order_is_voided(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'status' => 'pending',
				'items'  => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );
		$this->writer->register();

		$order->update_status( 'processing' );
		$this->assertCount( 1, $this->ledger_rows() );

		$order->update_status( 'cancelled' );
		$this->assertSame( array(), $this->ledger_rows() );
	}

	public function test_cancelling_an_order_with_refunds_keeps_its_rows(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'items' => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );
		$this->writer->register();
		$this->writer->record_order( $order );
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '10',
			)
		);

		$this->writer->void_order( wc_get_order( $order->get_id() ) );

		$this->assertNotEmpty( $this->ledger_rows( "entry_type = 'sale'" ) );
	}

	public function test_payment_complete_records_the_order(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'status' => 'pending',
				'items'  => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );
		$this->writer->register();

		do_action( 'woocommerce_payment_complete', $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

		$this->assertCount( 1, $this->ledger_rows() );
	}

	public function test_period_uses_store_timezone(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		update_option( 'timezone_string', 'Europe/Malta' );
		$order = $this->make_order(
			array(
				'created' => '2026-12-31 20:00:00',
				'paid'    => '2026-12-31 23:30:00',
				'items'   => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );

		$this->writer->record_order( $order );

		$row = $this->ledger_rows()[0];
		$this->assertSame( '2027-Q1', $row['period'] );
		$this->assertSame( '2027-Q1', $row['relates_to_period'] );
		$this->assertSame( '2026-12-31 23:30:00', $row['supply_at'] );
	}

	public function test_period_in_utc_store_stays_in_the_old_quarter(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'created' => '2026-12-31 20:00:00',
				'paid'    => '2026-12-31 23:30:00',
				'items'   => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );

		$this->writer->record_order( $order );

		$this->assertSame( '2026-Q4', $this->ledger_rows()[0]['period'] );
	}

	public function test_non_eur_order_converted_at_order_date_rate(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'currency' => 'USD',
				'created'  => '2026-10-11 09:00:00',
				'paid'     => '2026-10-13 09:00:00',
				'items'    => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );

		$this->writer->record_order( $order );

		$row = $this->ledger_rows()[0];
		$this->assertSame( 'USD', $row['currency'] );
		$this->assertSame( '1.12060000', $row['eur_rate'] );
		$this->assertSame( '89.2379', $row['net_eur'] );
		$this->assertSame( '16.9552', $row['vat_eur'] );
		$this->assertSame( '100.0000', $row['net'] );

		$stored = wc_get_order( $order->get_id() )->get_meta( LedgerWriter::FX_META );
		$this->assertSame( 'USD', $stored['currency'] );
		$this->assertSame( 1.1206, $stored['eur_rate'] );
		$this->assertSame( '2026-10-11', $stored['rate_date'] );
		$this->assertSame( 'ecb', $stored['source'] );
	}

	public function test_second_record_reuses_the_stored_rate(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'currency' => 'USD',
				'created'  => '2026-10-11 09:00:00',
				'items'    => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );
		$this->writer->record_order( $order );
		$calls = count( $this->transport->calls );

		$this->writer->record_order( wc_get_order( $order->get_id() ) );

		$this->assertSame( $calls, count( $this->transport->calls ) );
	}

	public function test_missing_rate_leaves_eur_null_and_schedules_a_retry(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'currency' => 'USD',
				'created'  => gmdate( 'Y-m-d H:i:s' ),
				'items'    => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );
		$this->transport->fail = true;

		$this->writer->record_order( $order );

		$row = $this->ledger_rows()[0];
		$this->assertNull( $row['eur_rate'] );
		$this->assertNull( $row['net_eur'] );
		$this->assertTrue( as_has_scheduled_action( LedgerWriter::FX_RETRY_HOOK, array( $order->get_id() ), LedgerWriter::AS_GROUP ) );
	}

	public function test_retry_fills_in_the_rate(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'currency' => 'USD',
				'created'  => '2026-10-13 09:00:00',
				'items'    => array(
					array(
						'name'    => 'Gadget',
						'net'     => 100,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );
		$this->writer->register();
		$this->transport->fail = true;
		$this->writer->record_order( $order );
		$this->assertNull( $this->ledger_rows()[0]['net_eur'] );

		$this->transport->fail = false;
		delete_transient( 'vatright_fx_' . md5( $this->transport->calls[0] ) );
		do_action( LedgerWriter::FX_RETRY_HOOK, $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

		$rows = $this->ledger_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( '89.3815', $rows[0]['net_eur'] );
	}

	public function test_period_for_quarter_edges(): void {
		$this->assertSame( '2026-Q1', LedgerWriter::period_for( new \DateTimeImmutable( '2026-03-31 23:59:59', new \DateTimeZone( 'UTC' ) ) ) );
		$this->assertSame( '2026-Q2', LedgerWriter::period_for( new \DateTimeImmutable( '2026-04-01 00:00:00', new \DateTimeZone( 'UTC' ) ) ) );
		$this->assertSame( '2026-Q4', LedgerWriter::period_for( new \DateTimeImmutable( '2026-10-01 00:00:00', new \DateTimeZone( 'UTC' ) ) ) );
	}
}
