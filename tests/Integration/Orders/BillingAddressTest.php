<?php

namespace Vatright\Tests\Integration\Orders;

use Vatright\Orders\DecisionStore;
use WC_Order;

require_once __DIR__ . '/LifecycleTestCase.php';

class BillingAddressTest extends LifecycleTestCase {

	public function set_up() {
		parent::set_up();
		WC()->countries->address_formats = array();
	}

	public function tear_down() {
		WC()->countries->address_formats = array();
		parent::tear_down();
	}

	private function address( WC_Order $order ): string {
		return (string) wc_get_order( $order->get_id() )->get_formatted_billing_address();
	}

	private function named_order(): WC_Order {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->save();
		return $order;
	}

	public function test_the_decided_number_is_the_last_line_of_the_billing_address(): void {
		$order = $this->named_order();
		DecisionStore::apply( $order, $this->decide( 'DE', 'DE', '10115', array( 'digital' ), false, self::NUMBER, 'valid' ), 'checkout' );
		$order->save();

		$lines = explode( '<br/>', $this->address( $order ) );

		$this->assertSame( 'VAT number: ' . self::NUMBER, end( $lines ) );
		$this->assertSame( 1, substr_count( $this->address( $order ), self::NUMBER ) );
	}

	public function test_another_plugins_number_shows_on_an_order_vatright_never_decided(): void {
		$order = $this->named_order();
		$order->update_meta_data( '_billing_vat_number', self::NUMBER );
		$order->save();

		$this->assertStringContainsString( 'VAT number: ' . self::NUMBER, $this->address( $order ) );
	}

	public function test_no_line_without_a_number(): void {
		$order = $this->named_order();

		$this->assertStringNotContainsString( 'VAT number', $this->address( $order ) );
		$this->assertStringNotContainsString( '{vatright', $this->address( $order ) );
	}

	public function test_no_second_line_when_another_vat_plugin_prints_the_number(): void {
		$order = $this->named_order();
		$order->update_meta_data( '_billing_vat_number', self::NUMBER );
		$order->save();
		// What WooCommerce EU VAT Number does in formatted_billing_address(), output_company_vat_number() and localisation_address_formats().
		$add_field  = static function ( $address ) {
			$address['vat_id'] = self::NUMBER;
			return $address;
		};
		$replace    = static function ( $replacements, $args ) {
			$replacements['{vat_id}'] = isset( $args['vat_id'] ) ? 'VAT Number: ' . $args['vat_id'] : '';
			return $replacements;
		};
		$add_format = static function ( $formats ) {
			foreach ( $formats as $key => $format ) {
				$formats[ $key ] = $format . "\n{vat_id}";
			}
			return $formats;
		};
		add_filter( 'woocommerce_order_formatted_billing_address', $add_field );
		add_filter( 'woocommerce_formatted_address_replacements', $replace, 10, 2 );
		add_filter( 'woocommerce_localisation_address_formats', $add_format );

		$address = $this->address( $order );

		$this->assertStringContainsString( 'VAT Number: ' . self::NUMBER, $address );
		$this->assertSame( 1, substr_count( $address, self::NUMBER ) );
	}
}
