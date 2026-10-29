<?php

namespace Vatright\Tests\Integration\Migration;

use Vatright\Checkout\InputGatherer;
use Vatright\Checkout\VatField;
use Vatright\Domain\ValidationResult;
use Vatright\Migration\LegacyData;
use Vatright\Orders\DecisionStore;
use Vatright\Privacy\Privacy;
use Vatright\Privacy\Retention;
use Vatright\Registry\ValidationCache;
use Vatright\Settings;
use Vatright\Tests\Integration\Orders\OrderTestCase;
use WC_Customer;
use WC_Order;

require_once dirname( __DIR__ ) . '/Orders/OrderTestCase.php';

class LegacyDataTest extends OrderTestCase {

	const DE = 'DE114103379';

	/**
	 * @param array<string,mixed> $meta
	 */
	private function stored( array $meta ): WC_Order {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	/**
	 * @dataProvider orders
	 * @param array<string,mixed> $meta
	 */
	public function test_each_plugins_order_record_is_read( array $meta, string $source, string $number, string $status ): void {
		$order = $this->stored( $meta );

		$this->assertSame(
			array(
				'source' => $source,
				'number' => $number,
				'status' => $status,
			),
			LegacyData::order( $order )
		);
		$this->assertSame( $number, LegacyData::order_number( $order ) );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:string,2:string,3:string}>
	 */
	public function orders(): array {
		return array(
			'WooCommerce EU VAT Number'        => array(
				array(
					'_billing_vat_number'      => 'de114103379',
					'_vat_number_is_validated' => 'true',
					'_vat_number_is_valid'     => 'true',
				),
				'eu-vat-number',
				self::DE,
				ValidationResult::VALID,
			),
			'WooCommerce EU VAT Number legacy' => array( array( '_vat_number' => self::DE ), 'eu-vat-number', self::DE, ValidationResult::UNCHECKED ),
			'WPFactory'                        => array(
				array(
					'_billing_eu_vat_number'        => self::DE,
					'billing_eu_vat_number_details' => array( 'vat_number' => array( 'data' => '114103379' ) ),
				),
				'wpfactory',
				self::DE,
				ValidationResult::VALID,
			),
			'Aelia'                            => array(
				array(
					'vat_number'            => self::DE,
					'_vat_number_validated' => 'not-valid',
				),
				'aelia',
				self::DE,
				ValidationResult::INVALID,
			),
			'Simba'                            => array(
				array(
					'VAT Number'           => '114103379',
					'Valid VAT Number'     => 'true',
					'VAT number validated' => 'true',
				),
				'simba',
				'114103379',
				ValidationResult::VALID,
			),
		);
	}

	public function test_an_order_without_any_number_has_none(): void {
		$order = $this->stored( array( 'is_vat_exempt' => 'yes' ) );

		$this->assertNull( LegacyData::order( $order ) );
		$this->assertSame( '', LegacyData::order_number( $order ) );
	}

	public function test_vatright_records_win_over_older_keys(): void {
		$own = $this->stored(
			array(
				'_billing_vat_number'      => self::DE,
				DecisionStore::NUMBER_META => 'FR40303265045',
			)
		);
		$this->assertSame( 'FR40303265045', LegacyData::order_number( $own ) );

		$cleared = $this->stored(
			array(
				'_billing_vat_number'      => self::DE,
				DecisionStore::NUMBER_META => '',
			)
		);
		$this->assertSame( '', LegacyData::order_number( $cleared ), 'A number cleared in VAT Right stays cleared' );

		$scrubbed = $this->stored(
			array(
				'_billing_vat_number'    => self::DE,
				Retention::SCRUBBED_META => '2026-01-01T00:00:00Z',
			)
		);
		$this->assertSame( '', LegacyData::order_number( $scrubbed ), 'Removed personal data does not come back' );

		$decided = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		DecisionStore::apply( $decided, $this->decide( 'DE', 'DE', '10115', array( 'digital' ) ), 'checkout' );
		$decided->update_meta_data( '_billing_vat_number', self::DE );
		$decided->save();
		$this->assertSame( '', LegacyData::order_number( wc_get_order( $decided->get_id() ) ), 'A decision made without a number stands' );
	}

	public function test_a_decision_inferred_without_a_number_defers_to_older_keys(): void {
		$order  = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		$record = $this->decide( 'DE', 'DE', '10115', array( 'digital' ) )->to_array();

		$record['flags'] = array( 'backfilled' );
		$order->update_meta_data( DecisionStore::META, wp_json_encode( $record ) );
		$order->update_meta_data( '_billing_vat_number', self::DE );
		$order->save();

		$this->assertSame( self::DE, LegacyData::order_number( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * @dataProvider customers
	 */
	public function test_each_plugins_saved_customer_number_is_read( string $key, string $source ): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, $key, self::DE );

		$this->assertSame(
			array(
				'source' => $source,
				'number' => self::DE,
			),
			LegacyData::customer( $user_id )
		);
		$this->assertSame( self::DE, LegacyData::customer_number( new WC_Customer( $user_id ) ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function customers(): array {
		return array(
			'WooCommerce EU VAT Number'        => array( 'vat_number', 'eu-vat-number' ),
			'WooCommerce EU VAT Number legacy' => array( 'billing_vat_number', 'eu-vat-number' ),
			'WPFactory classic'                => array( 'billing_eu_vat_number', 'wpfactory' ),
			'WPFactory block'                  => array( '_wc_other/alg_eu_vat/billing_eu_vat_number', 'wpfactory' ),
		);
	}

	public function test_a_number_the_customer_saved_or_cleared_with_vatright_wins(): void {
		$saved = self::factory()->user->create();
		update_user_meta( $saved, 'vat_number', self::DE );
		update_user_meta( $saved, InputGatherer::NUMBER_META, 'FR40303265045' );
		$this->assertSame( 'FR40303265045', LegacyData::customer_number( new WC_Customer( $saved ) ) );

		$cleared = self::factory()->user->create();
		update_user_meta( $cleared, 'vat_number', self::DE );
		update_user_meta( $cleared, InputGatherer::NUMBER_META, '' );
		$this->assertSame( '', LegacyData::customer_number( new WC_Customer( $cleared ) ) );

		$this->assertSame( '', LegacyData::customer_number( new WC_Customer() ), 'A guest has no saved number' );
	}

	public function test_checkout_and_my_account_fill_in_the_older_number(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'billing_vat_number', self::DE );
		$customer = new WC_Customer( $user_id );
		$field    = new VatField( $this->createMock( \Vatright\Registry\Validator::class ) );

		$this->assertSame( self::DE, $field->block_default( null, 'other', $customer ), 'Block checkout and My Account' );
		$this->assertSame( 'typed', $field->block_default( 'typed', 'other', $customer ), 'A stored value is left alone' );
		$this->assertNull( $field->block_default( null, 'other', new WC_Customer() ) );

		WC()->customer = $customer;
		$this->assertSame( self::DE, $field->classic_default( '', VatField::CLASSIC_FIELD ), 'Classic checkout' );
	}

	public function test_admin_order_field_shows_the_older_number(): void {
		$order = $this->stored( array( '_billing_vat_number' => self::DE ) );

		$fields = ( new VatField( $this->createMock( \Vatright\Registry\Validator::class ) ) )->add_admin_field( array(), $order );

		$this->assertSame( self::DE, $fields['vatright_number']['value'] );
	}

	public function test_privacy_export_includes_older_numbers(): void {
		$this->install_schema();
		$user_id = self::factory()->user->create( array( 'user_email' => 'legacy@example.com' ) );
		update_user_meta( $user_id, 'vat_number', self::DE );
		$order = $this->stored( array( '_billing_vat_number' => self::DE ) );
		$order->set_billing_email( 'legacy@example.com' );
		$order->save();

		$export = ( new Privacy( new ValidationCache( Settings::get() ) ) )->export( 'legacy@example.com' );

		$values = array();
		foreach ( $export['data'] as $item ) {
			foreach ( $item['data'] as $row ) {
				$values[] = $row['name'] . ': ' . $row['value'];
			}
		}
		$this->assertContains( 'Saved VAT number: ' . self::DE, $values );
		$this->assertContains( 'VAT number: ' . self::DE, $values );
	}
}
