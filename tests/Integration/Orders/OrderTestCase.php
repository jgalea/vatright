<?php

namespace Vatright\Tests\Integration\Orders;

use Vatright\Checkout\InputGatherer;
use Vatright\Domain\Decision;
use Vatright\Domain\DecisionInput;
use Vatright\Domain\Evidence;
use Vatright\Domain\Line;
use Vatright\Domain\Rules;
use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use Vatright\Settings;
use Vatright\Tests\Support\IntegrationTestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product_Simple;
use WC_Tax;

abstract class OrderTestCase extends IntegrationTestCase {

	/** @var array<string,int> */
	protected $rates = array();

	public function set_up() {
		parent::set_up();
		update_option( 'woocommerce_default_country', 'MT' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'shipping' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_shipping_tax_class', 'inherit' );
		delete_option( Settings::OPTION );
		Settings::reset();
		$this->rates = array();
		foreach ( array(
			'MT' => '18',
			'DE' => '19',
			'FR' => '20',
			'ES' => '21',
		) as $country => $percent ) {
			$this->rates[ $country ] = (int) WC_Tax::_insert_tax_rate(
				array(
					'tax_rate_country'  => $country,
					'tax_rate'          => $percent,
					'tax_rate_name'     => 'VAT',
					'tax_rate_priority' => 1,
					'tax_rate_compound' => 0,
					'tax_rate_shipping' => 1,
					'tax_rate_order'    => 1,
					'tax_rate_class'    => '',
				)
			);
		}
	}

	/**
	 * An order with one 100.00 line per supply type, keyed like checkout keys
	 * them, and a 10.00 shipping line when goods are in it.
	 *
	 * @param string[] $supplies
	 */
	protected function order( string $billing, string $shipping, string $postcode, array $supplies ): WC_Order {
		$order = new WC_Order();
		$order->set_billing_country( $billing );
		$order->set_billing_postcode( $billing === $shipping ? $postcode : '' );
		$order->set_shipping_country( $shipping );
		$order->set_shipping_postcode( $postcode );
		$order->set_shipping_address_1( '1 Street' );
		foreach ( $supplies as $supply ) {
			$product = new WC_Product_Simple();
			$product->set_regular_price( '100' );
			$product->set_virtual( 'goods' !== $supply );
			$product->save();
			$item = new WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_quantity( 1 );
			$item->set_subtotal( '100' );
			$item->set_total( '100' );
			$item->add_meta_data( InputGatherer::LINE_KEY_META, 'line-' . $supply, true );
			$order->add_item( $item );
		}
		if ( in_array( 'goods', $supplies, true ) ) {
			$shipping_item = new WC_Order_Item_Shipping();
			$shipping_item->set_method_id( 'flat_rate' );
			$shipping_item->set_total( '10' );
			$order->add_item( $shipping_item );
		}
		$order->save();
		return $order;
	}

	/**
	 * Runs the real rules over a hand-built input whose lines match order().
	 *
	 * @param string[] $supplies
	 * @param string[] $exempt_roles
	 */
	protected function decide( string $billing, string $shipping, string $postcode, array $supplies, bool $oss = false, string $number = '', string $status = '', string $ip = '', string $name = '', array $exempt_roles = array() ): Decision {
		$vat = '' === $number ? null : VatNumber::parse( $number, $billing );
		return ( new Rules() )->decide(
			new DecisionInput(
				'MT',
				$oss,
				false,
				array(
					'current_eur'  => '0.00',
					'previous_eur' => '0.00',
					'exceeded'     => false,
					'forced'       => $oss,
				),
				'charge',
				false,
				false,
				$billing,
				$billing === $shipping ? $postcode : '',
				$shipping,
				$postcode,
				false,
				$vat,
				'' === $number ? null : $number,
				null === $vat ? null : new ValidationResult( $status, 'vies', '2026-10-17T12:00:00Z', $name, "Line 1\nLine \"2\" \\ ü", 'WAPIAAAA' ),
				new Evidence( $billing, in_array( 'goods', $supplies, true ) ? $shipping : null, '' === $ip ? null : $ip, null ),
				array_map(
					static function ( string $supply ): Line {
						return new Line( 'line-' . $supply, $supply, 'goods' === $supply );
					},
					$supplies
				),
				'checkout',
				'2026-10-17T12:00:00Z',
				$exempt_roles
			)
		);
	}

	/**
	 * @return array<int,float> Tax rate id => item and shipping tax.
	 */
	protected function order_taxes( WC_Order $order ): array {
		$taxes = array();
		foreach ( $order->get_taxes() as $tax ) {
			$taxes[ (int) $tax->get_rate_id() ] = round( (float) $tax->get_tax_total() + (float) $tax->get_shipping_tax_total(), 2 );
		}
		return $taxes;
	}
}
