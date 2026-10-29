<?php

namespace Vatright\Tests\Integration\Orders;

use Vatright\Orders\CompatKeys;
use Vatright\Orders\DecisionStore;
use Vatright\Privacy\Retention;
use Vatright\Settings;
use WC_Order;

require_once __DIR__ . '/LifecycleTestCase.php';

class CompatKeysTest extends LifecycleTestCase {

	/**
	 * @param array<string,string> $meta
	 */
	private function decided( string $status, array $meta = array(), string $number = self::NUMBER ): WC_Order {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		DecisionStore::apply( $order, $this->decide( 'DE', 'DE', '10115', array( 'digital' ), false, $number, $status ), 'checkout' );
		$order->save();
		return $this->fresh( $order );
	}

	public function test_a_zero_rated_order_gets_the_keys_invoice_plugins_read(): void {
		$order = $this->decided( 'valid' );

		$this->assertSame( 'yes', $order->get_meta( 'is_vat_exempt' ) );
		$this->assertSame( self::NUMBER, $order->get_meta( '_billing_vat_number' ) );
		$this->assertSame( 'true', $order->get_meta( '_vat_number_is_valid' ) );
	}

	public function test_a_charged_order_is_not_marked_valid(): void {
		$order = $this->decided( 'invalid' );

		$this->assertSame( self::NUMBER, $order->get_meta( '_billing_vat_number' ) );
		$this->assertSame( 'false', $order->get_meta( '_vat_number_is_valid' ) );
	}

	public function test_an_order_without_a_number_gets_no_keys(): void {
		$order = $this->decided( '', array(), '' );

		$this->assertFalse( $order->meta_exists( '_billing_vat_number' ) );
		$this->assertFalse( $order->meta_exists( '_vat_number_is_valid' ) );
		$this->assertFalse( $order->meta_exists( CompatKeys::OWNED_META ) );
	}

	public function test_the_setting_turns_it_off(): void {
		update_option( Settings::OPTION, array( 'write_compat_keys' => false ) );
		Settings::reset();

		$order = $this->decided( 'valid' );

		$this->assertFalse( $order->meta_exists( '_billing_vat_number' ) );
		$this->assertFalse( $order->meta_exists( '_vat_number_is_valid' ) );
	}

	public function test_it_is_on_by_default(): void {
		$this->assertTrue( Settings::get()->write_compat_keys() );
	}

	public function test_another_plugins_value_is_never_replaced(): void {
		$order = $this->decided(
			'valid',
			array(
				'_billing_vat_number'  => 'de 114 103 379',
				'_vat_number_is_valid' => 'false',
			)
		);

		$this->assertSame( 'de 114 103 379', $order->get_meta( '_billing_vat_number' ) );
		$this->assertSame( 'false', $order->get_meta( '_vat_number_is_valid' ) );
		$this->assertFalse( $order->meta_exists( CompatKeys::OWNED_META ) );
	}

	public function test_nothing_is_written_while_the_owning_plugin_is_active(): void {
		$active = static function ( $is_active, $source ) {
			return 'eu-vat-number' === $source ? true : $is_active;
		};
		add_filter( 'vatright_other_plugin_active', $active, 10, 2 );

		$order = $this->decided( 'valid' );

		$this->assertFalse( $order->meta_exists( '_billing_vat_number' ) );
		$this->assertFalse( $order->meta_exists( '_vat_number_is_valid' ) );
	}

	public function test_keys_of_ours_follow_a_changed_decision_and_go_with_the_number(): void {
		$order = $this->decided( 'valid' );

		DecisionStore::apply( $order, $this->decide( 'DE', 'DE', '10115', array( 'digital' ), false, self::NUMBER, 'invalid' ), 'admin' );
		$order->save();
		$this->assertSame( 'false', $this->fresh( $order )->get_meta( '_vat_number_is_valid' ) );

		DecisionStore::apply( $order, $this->decide( 'DE', 'DE', '10115', array( 'digital' ) ), 'admin' );
		$order->save();
		$order = $this->fresh( $order );
		$this->assertFalse( $order->meta_exists( '_billing_vat_number' ) );
		$this->assertFalse( $order->meta_exists( '_vat_number_is_valid' ) );
	}

	public function test_removing_personal_data_removes_the_copies(): void {
		$order = $this->decided( 'valid' );

		Retention::scrub( $order );

		$order = $this->fresh( $order );
		$this->assertFalse( $order->meta_exists( '_billing_vat_number' ) );
		$this->assertFalse( $order->meta_exists( '_vat_number_is_valid' ) );
	}
}
