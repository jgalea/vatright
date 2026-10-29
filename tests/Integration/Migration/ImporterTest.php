<?php

namespace Vatright\Tests\Integration\Migration;

use Vatright\Domain\Decision;
use Vatright\Domain\Scheme;
use Vatright\Domain\ValidationResult;
use Vatright\Ledger\Backfill;
use Vatright\Ledger\LedgerWriter;
use Vatright\Migration\Importer;
use Vatright\Migration\LegacyData;
use Vatright\Orders\CompatKeys;
use Vatright\Orders\DecisionStore;
use Vatright\Privacy\Retention;
use Vatright\Settings;
use Vatright\Tests\Integration\Ledger\LedgerTestCase;
use WC_Order;

require_once dirname( __DIR__ ) . '/Ledger/LedgerTestCase.php';

class ImporterTest extends LedgerTestCase {

	const DE = 'DE114103379';
	const ES = 'ESA15075062';
	const NL = 'NL820646660B01';

	/** @var Importer */
	private $importer;

	/** @var string */
	private $recent;

	public function set_up() {
		parent::set_up();
		$this->importer = new Importer();
		$this->recent   = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
		delete_option( Importer::STATE );
		foreach ( array( 'woocommerce_eu_vat_store_id', 'wc_aelia_eu_vat_assistant', 'alg_wc_eu_vat_requester_country_code', 'alg_wc_eu_vat_requester_vat_number' ) as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * An order stored the way another VAT plugin left it.
	 *
	 * @param array<string,mixed> $meta
	 */
	private function legacy( array $meta, string $country = 'DE' ): WC_Order {
		$order = $this->make_order(
			array(
				'billing_country' => $country,
				'created'         => $this->recent,
				'items'           => array(
					array(
						'name' => 'Gadget',
						'net'  => 100,
					),
				),
			)
		);
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save_meta_data();
		return $order;
	}

	/**
	 * @return array<string,mixed> Every meta value on the order that isn't VAT Right's.
	 */
	private function foreign_meta( WC_Order $order ): array {
		$values = array();
		foreach ( wc_get_order( $order->get_id() )->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			if ( 0 !== strpos( $data['key'], '_vatright' ) ) {
				$values[ $data['key'] ] = $data['value'];
			}
		}
		ksort( $values );
		return $values;
	}

	/**
	 * @param array<string,string> $meta
	 */
	private function customer( array $meta ): int {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		foreach ( $meta as $key => $value ) {
			update_user_meta( $user_id, $key, $value );
		}
		return $user_id;
	}

	private function import_all( ?string $only = null ): int {
		$imported = 0;
		$after    = 0;
		do {
			$batch     = $this->importer->import_orders( $after, 2, $only );
			$imported += $batch['imported'];
			$after     = $batch['last'];
		} while ( ! $batch['done'] );
		$after = 0;
		do {
			$batch     = $this->importer->import_customers( $after, 2, $only );
			$imported += $batch['imported'];
			$after     = $batch['last'];
		} while ( ! $batch['done'] );
		return $imported + count( $this->importer->import_settings( $only, true ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function imported( WC_Order $order ): array {
		$record = json_decode( (string) wc_get_order( $order->get_id() )->get_meta( LegacyData::IMPORTED_META ), true );
		return is_array( $record ) ? $record : array();
	}

	private function own_meta_rows(): int {
		global $wpdb;
		$table = $this->storage_is_hpos() ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE meta_key LIKE %s", '_vatright%' ) ) // phpcs:ignore WordPress.DB
			+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", '_vatright%' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * One store's worth of data from every source, plus orders an import
	 * must not touch.
	 *
	 * @return array<string,WC_Order>
	 */
	private function seed(): array {
		$orders = array(
			'eu-vat-number' => $this->legacy(
				array(
					'_billing_vat_number'      => self::DE,
					'_vat_number_is_validated' => 'true',
					'_vat_number_is_valid'     => 'true',
					'is_vat_exempt'            => 'yes',
				)
			),
			'eu-vat-legacy' => $this->legacy(
				array(
					'_vat_number'          => self::ES,
					'_vat_number_is_valid' => 'false',
				),
				'ES'
			),
			'wpfactory'     => $this->legacy(
				array(
					'_billing_eu_vat_number'        => self::NL,
					'billing_eu_vat_number_details' => array( 'business_name' => array( 'data' => 'Acme BV' ) ),
				),
				'NL'
			),
			'aelia'         => $this->legacy(
				array(
					'vat_number'            => self::DE,
					'_vat_country'          => 'DE',
					'_vat_number_validated' => 'not-valid',
				)
			),
			'simba'         => $this->legacy(
				array(
					'VAT Number'           => '114103379',
					'Valid VAT Number'     => 'true',
					'VAT number validated' => 'true',
				)
			),
			'own'           => $this->legacy(
				array(
					'_billing_vat_number'      => self::DE,
					DecisionStore::NUMBER_META => 'FR40303265045',
				)
			),
			'compat'        => $this->legacy(
				array(
					'_billing_vat_number'      => self::DE,
					CompatKeys::OWNED_META     => '_billing_vat_number',
					DecisionStore::NUMBER_META => self::DE,
				)
			),
		);

		$refund = wc_create_refund(
			array(
				'order_id' => $orders['eu-vat-number']->get_id(),
				'amount'   => 0,
			)
		);
		$refund->update_meta_data( '_billing_vat_number', self::DE );
		$refund->save_meta_data();

		return $orders;
	}

	public function test_dry_run_counts_each_source_and_changes_nothing(): void {
		$this->seed();
		$this->customer( array( 'vat_number' => self::DE ) );
		$this->customer( array( 'billing_eu_vat_number' => self::NL ) );
		$this->customer(
			array(
				'billing_vat_number'       => self::ES,
				DecisionStore::NUMBER_META => self::ES,
			)
		);
		update_option(
			'woocommerce_eu_vat_store_id',
			array(
				'eu' => self::NL,
				'uk' => 'GB980780684',
			)
		);
		$before = $this->own_meta_rows();

		$dry = $this->importer->dry_run();

		$this->assertSame(
			array(
				'eu-vat-number' => array(
					'name'             => 'WooCommerce EU VAT Number',
					'active'           => false,
					'orders'           => 3,
					'orders_to_import' => 2,
				),
				'wpfactory'     => array(
					'name'             => 'EU/UK VAT Validation Manager for WooCommerce',
					'active'           => false,
					'orders'           => 1,
					'orders_to_import' => 1,
				),
				'aelia'         => array(
					'name'             => 'Aelia EU VAT Assistant',
					'active'           => false,
					'orders'           => 1,
					'orders_to_import' => 1,
				),
				'simba'         => array(
					'name'             => 'EU/UK VAT Compliance for WooCommerce',
					'active'           => false,
					'orders'           => 1,
					'orders_to_import' => 1,
				),
			),
			$dry['sources'],
			'The refund and the order whose key VAT Right wrote are not counted; the order with a number of ours is not to import'
		);
		$this->assertSame( 6, $dry['orders'] );
		$this->assertSame( 5, $dry['orders_to_import'] );
		$this->assertSame( 3, $dry['customers'] );
		$this->assertSame( 2, $dry['customers_to_import'] );
		$this->assertSame(
			array(
				'store_vat_number'    => self::NL,
				'store_vat_number_gb' => 'GB980780684',
			),
			$dry['settings']
		);
		$this->assertSame( $before, $this->own_meta_rows(), 'A dry run writes nothing' );
		$this->assertSame( '', Settings::get()->store_vat_number() );
	}

	public function test_dry_run_finds_nothing_on_a_store_without_other_plugins_data(): void {
		$this->legacy( array() );

		$dry = $this->importer->dry_run();

		$this->assertSame( array(), $dry['sources'] );
		$this->assertSame( 0, $dry['orders'] + $dry['customers'] );
		$this->assertSame( array(), $dry['settings'] );
	}

	public function test_import_copies_numbers_and_verdicts_and_leaves_their_data_alone(): void {
		$orders  = $this->seed();
		$foreign = array_map( array( $this, 'foreign_meta' ), $orders );
		$aelia   = $this->customer( array( 'vat_number' => self::DE ) );
		$block   = $this->customer( array( '_wc_other/alg_eu_vat/billing_eu_vat_number' => self::NL ) );
		$bare    = $this->customer(
			array(
				'billing_vat_number' => '114103379',
				'billing_country'    => 'DE',
			)
		);
		update_option(
			'wc_aelia_eu_vat_assistant',
			array(
				'vies_requester_vat_country' => 'NL',
				'vies_requester_vat_number'  => '820646660B01',
			)
		);

		$this->assertSame( 9, $this->import_all() );

		$expected = array(
			'eu-vat-number' => array( self::DE, 'eu-vat-number', ValidationResult::VALID ),
			'eu-vat-legacy' => array( self::ES, 'eu-vat-number', ValidationResult::UNCHECKED ),
			'wpfactory'     => array( self::NL, 'wpfactory', ValidationResult::VALID ),
			'aelia'         => array( self::DE, 'aelia', ValidationResult::INVALID ),
			'simba'         => array( self::DE, 'simba', ValidationResult::VALID ),
		);
		foreach ( $expected as $name => list( $number, $source, $status ) ) {
			$this->assertSame( $number, wc_get_order( $orders[ $name ]->get_id() )->get_meta( DecisionStore::NUMBER_META ), $name );
			$this->assertSame( $source, $this->imported( $orders[ $name ] )['source'], $name );
			$this->assertSame( $status, $this->imported( $orders[ $name ] )['status'], $name );
		}
		$this->assertSame( 'FR40303265045', wc_get_order( $orders['own']->get_id() )->get_meta( DecisionStore::NUMBER_META ), 'A number of ours is kept' );
		$this->assertSame( array(), $this->imported( $orders['own'] ) );

		foreach ( $orders as $name => $order ) {
			$this->assertSame( $foreign[ $name ], $this->foreign_meta( $order ), "The other plugin's keys on $name are unchanged" );
		}
		$this->assertSame( self::DE, get_user_meta( $aelia, DecisionStore::NUMBER_META, true ) );
		$this->assertSame( self::NL, get_user_meta( $block, DecisionStore::NUMBER_META, true ) );
		$this->assertSame( self::DE, get_user_meta( $bare, DecisionStore::NUMBER_META, true ), 'A number saved without its prefix gets the billing country' );
		$this->assertSame( self::DE, get_user_meta( $aelia, 'vat_number', true ) );
		Settings::reset();
		$this->assertSame( self::NL, Settings::get()->store_vat_number(), 'Aelia\'s requester number is the store\'s' );
	}

	public function test_a_second_import_changes_nothing(): void {
		$orders = $this->seed();
		$this->customer( array( 'vat_number' => self::DE ) );
		update_option( 'woocommerce_eu_vat_store_id', self::NL );
		$this->assertGreaterThan( 0, $this->import_all() );
		$first = $this->imported( $orders['eu-vat-number'] );
		$rows  = $this->own_meta_rows();

		$this->assertSame( 0, $this->import_all() );

		$dry = $this->importer->dry_run();
		$this->assertSame( 0, $dry['orders_to_import'] );
		$this->assertSame( 0, $dry['customers_to_import'] );
		$this->assertSame( array(), $dry['settings'] );
		$this->assertSame( $first, $this->imported( $orders['eu-vat-number'] ) );
		$this->assertSame( $rows, $this->own_meta_rows() );
	}

	public function test_vatright_data_is_never_replaced(): void {
		$decided  = $this->legacy( array( '_billing_vat_number' => self::DE ) );
		$decision = $this->seed_decision( $decided );
		$cleared  = $this->legacy(
			array(
				'_billing_vat_number'      => self::DE,
				DecisionStore::NUMBER_META => '',
			)
		);
		$scrubbed = $this->legacy(
			array(
				'_billing_vat_number'    => self::DE,
				Retention::SCRUBBED_META => '2026-01-01T00:00:00Z',
			)
		);
		$customer = $this->customer(
			array(
				'vat_number'               => self::DE,
				DecisionStore::NUMBER_META => '',
			)
		);
		update_option( Settings::OPTION, array( 'store_vat_number' => 'MT11679112' ) );
		Settings::reset();
		update_option( 'woocommerce_eu_vat_store_id', self::NL );

		$this->assertSame( 0, $this->importer->dry_run()['orders_to_import'] );
		$this->assertSame( 0, $this->import_all() );

		$this->assertSame( wp_json_encode( $decision->to_array() ), wc_get_order( $decided->get_id() )->get_meta( LedgerWriter::DECISION_META ), 'A decision of ours, without a number, stands' );
		$this->assertSame( '', wc_get_order( $decided->get_id() )->get_meta( DecisionStore::NUMBER_META ) );
		$this->assertSame( '', wc_get_order( $cleared->get_id() )->get_meta( DecisionStore::NUMBER_META ) );
		$this->assertSame( '', wc_get_order( $scrubbed->get_id() )->get_meta( DecisionStore::NUMBER_META ) );
		$this->assertSame( '', get_user_meta( $customer, DecisionStore::NUMBER_META, true ) );
		Settings::reset();
		$this->assertSame( 'MT11679112', Settings::get()->store_vat_number() );
	}

	public function test_batches_resume_after_the_last_order(): void {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->legacy( array( '_billing_vat_number' => self::DE ) )->get_id();
		}

		$first = $this->importer->import_orders( 0, 2 );
		$this->assertSame(
			array(
				'imported' => 2,
				'last'     => $ids[1],
				'done'     => false,
			),
			$first
		);
		$this->assertSame( 3, $this->importer->dry_run()['orders_to_import'] );
		$this->assertSame( 2, $this->importer->import_orders( $first['last'], 2 )['imported'] );
		$last = $this->importer->import_orders( $ids[3], 2 );
		$this->assertSame( 1, $last['imported'] );
		$this->assertTrue( $last['done'] );
		$this->assertSame( 0, $this->importer->dry_run()['orders_to_import'] );
	}

	public function test_a_scheduled_import_runs_orders_then_customers_then_settings_and_starts_the_backfill(): void {
		$order = $this->legacy( array( '_billing_vat_number' => self::DE ) );
		$user  = $this->customer( array( 'vat_number' => self::ES ) );
		update_option( 'woocommerce_eu_vat_store_id', self::NL );
		as_unschedule_all_actions( Backfill::HOOK );

		$this->importer->start();
		$this->assertTrue( (bool) as_has_scheduled_action( Importer::HOOK, array( 'orders', 0 ), Importer::AS_GROUP ) );
		$this->assertSame( 'running', Importer::state()['status'] );

		$this->importer->run_batch( 'orders', 0 );
		$this->assertTrue( (bool) as_has_scheduled_action( Importer::HOOK, array( 'customers', 0 ), Importer::AS_GROUP ) );
		$this->importer->run_batch( 'customers', 0 );

		$state = Importer::state();
		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( 1, $state['orders'] );
		$this->assertSame( 1, $state['customers'] );
		$this->assertSame( array( 'store_vat_number' => self::NL ), $state['settings'] );
		$this->assertSame( self::DE, wc_get_order( $order->get_id() )->get_meta( DecisionStore::NUMBER_META ) );
		$this->assertSame( self::ES, get_user_meta( $user, DecisionStore::NUMBER_META, true ) );
		$this->assertTrue( (bool) as_has_scheduled_action( Backfill::HOOK, array( 0 ), Backfill::AS_GROUP ) );
	}

	public function test_the_backfill_records_the_imported_number_and_verdict(): void {
		$order = $this->legacy(
			array(
				'_billing_vat_number'      => self::DE,
				'_vat_number_is_validated' => 'true',
				'_vat_number_is_valid'     => 'true',
				'is_vat_exempt'            => 'yes',
			)
		);
		$this->import_all();

		( new Backfill( $this->writer ) )->run_batch( 0 );

		$decision = Decision::from_array( json_decode( (string) wc_get_order( $order->get_id() )->get_meta( LedgerWriter::DECISION_META ), true ) )->to_array();
		$this->assertSame( self::DE, $decision['vat_number']['canonical'] );
		$this->assertSame( ValidationResult::VALID, $decision['validation']['status'] );
		$this->assertSame( 'imported:eu-vat-number', $decision['validation']['detail'] );
		$rows = $this->ledger_rows( 'order_id = ' . $order->get_id() );
		$this->assertNotEmpty( $rows );
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $rows[0]['scheme'] );
		$this->assertSame( self::DE, $rows[0]['customer_vat'] );
	}

	public function test_an_order_backfilled_without_a_number_before_the_import_gets_it(): void {
		$order             = $this->legacy(
			array(
				'_billing_vat_number'  => self::DE,
				'_vat_number_is_valid' => 'true',
			)
		);
		$decision          = $this->seed_decision( $order, Scheme::REVERSE_CHARGE_SERVICES, 'RC_SERVICES_ART196' )->to_array();
		$decision['flags'] = array( 'backfilled' );
		$order->update_meta_data( LedgerWriter::DECISION_META, wp_json_encode( $decision ) );
		$order->save_meta_data();
		$this->writer->record_order( wc_get_order( $order->get_id() ) );
		$this->assertNull( $this->ledger_rows( 'order_id = ' . $order->get_id() )[0]['customer_vat'] );

		$this->assertSame( 1, $this->importer->import_orders( 0, 10 )['imported'] );

		$stored = json_decode( (string) wc_get_order( $order->get_id() )->get_meta( LedgerWriter::DECISION_META ), true );
		$this->assertSame( self::DE, $stored['vat_number']['canonical'] );
		$this->assertSame( ValidationResult::VALID, $stored['validation']['status'] );
		$this->assertSame( $decision['id'], $stored['id'], 'The same decision, filled in' );
		$this->assertSame( self::DE, $this->ledger_rows( 'order_id = ' . $order->get_id() )[0]['customer_vat'] );
	}

	public function test_one_source_can_be_imported_on_its_own(): void {
		$orders = $this->seed();

		$this->importer->import_orders( 0, 50, 'aelia' );

		$this->assertSame( self::DE, wc_get_order( $orders['aelia']->get_id() )->get_meta( DecisionStore::NUMBER_META ) );
		$this->assertSame( '', wc_get_order( $orders['eu-vat-number']->get_id() )->get_meta( DecisionStore::NUMBER_META ) );
		$this->assertSame( array( 'aelia' ), array_keys( $this->importer->dry_run( 'aelia' )['sources'] ) );
	}
}
