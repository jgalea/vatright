<?php

namespace Vatright\Orders;

use Vatright\Checkout\CartSteering;
use Vatright\Checkout\InputGatherer;
use Vatright\Checkout\TaxLocation;
use Vatright\Domain\Decision;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Tax;

/**
 * Steers WC_Order::calculate_totals() the way CartSteering steers the cart.
 * Block checkout recalculates its draft order before the decision is stored
 * on it, so an unpaid order uses this request's checkout decision when there
 * is one.
 *
 * @phpstan-import-type Address from TaxLocation
 */
class OrderSteering {

	const UNPAID_STATUSES = array( 'checkout-draft', 'pending', 'failed' );

	/** @var CartSteering */
	private $cart;

	/**
	 * The order whose taxes are being calculated, set when Woo asks for its tax
	 * location and read by the per-item hooks that follow.
	 *
	 * @var array{order_id: int, decision: Decision, customer: Address, missing: bool}|null
	 */
	private $calculating = null;

	public function __construct( CartSteering $cart ) {
		$this->cart = $cart;
	}

	public function register(): void {
		add_filter( 'woocommerce_order_is_vat_exempt', array( $this, 'filter_is_vat_exempt' ), 10, 2 );
		add_filter( 'woocommerce_order_get_tax_location', array( $this, 'filter_tax_location' ), 10, 2 );
		add_action( 'woocommerce_order_item_after_calculate_taxes', array( $this, 'steer_item' ), 10, 2 );
		add_action( 'woocommerce_order_item_shipping_after_calculate_taxes', array( $this, 'steer_shipping' ), 10, 2 );
		add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'finish' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'tag_line_item' ), 10, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_line_key' ) );
	}

	public function decision_for( WC_Order $order ): ?Decision {
		$live = $this->cart->checkout_decision();
		if ( null !== $live && in_array( $order->get_status(), self::UNPAID_STATUSES, true ) ) {
			return $live;
		}
		return DecisionStore::get( $order );
	}

	/**
	 * @param bool     $exempt
	 * @param mixed $order
	 * @return bool
	 */
	public function filter_is_vat_exempt( $exempt, $order ) {
		$decision = $order instanceof WC_Order ? $this->decision_for( $order ) : null;
		return null === $decision ? $exempt : $decision->is_vat_exempt();
	}

	/**
	 * @param array<string,string> $args
	 * @param mixed                $order
	 * @return array<string,string>
	 */
	public function filter_tax_location( $args, $order ) {
		$decision = $order instanceof WC_Order ? $this->decision_for( $order ) : null;
		if ( null === $decision ) {
			$this->calculating = null;
			return $args;
		}

		$customer                   = array(
			'country'  => (string) ( $args['country'] ?? '' ),
			'state'    => (string) ( $args['state'] ?? '' ),
			'postcode' => (string) ( $args['postcode'] ?? '' ),
			'city'     => (string) ( $args['city'] ?? '' ),
		);
		list( $location, $missing ) = TaxLocation::address( $decision, $customer );

		$this->calculating = array(
			'order_id' => $order->get_id(),
			'decision' => $decision,
			'customer' => $customer,
			'missing'  => $missing,
		);
		return array_merge( $args, $location );
	}

	/**
	 * @param WC_Order_Item        $item
	 * @param array<string,string> $calculate_tax_for
	 */
	public function steer_item( $item, $calculate_tax_for ): void {
		if ( ! $item instanceof WC_Order_Item_Product || null === $this->calculating || $item->get_order_id() !== $this->calculating['order_id'] ) {
			return;
		}
		$decision = $this->calculating['decision'];
		if ( $decision->is_vat_exempt() || '0' === $item->get_tax_class() || 'taxable' !== $item->get_tax_status() ) {
			return;
		}
		$key  = (string) $item->get_meta( InputGatherer::LINE_KEY_META );
		$line = $decision->line( '' === $key ? (string) $item->get_id() : $key );
		if ( null === $line ) {
			return;
		}

		$class    = $item->get_tax_class();
		$location = array(
			'country'  => (string) $calculate_tax_for['country'],
			'state'    => (string) $calculate_tax_for['state'],
			'postcode' => (string) $calculate_tax_for['postcode'],
			'city'     => (string) $calculate_tax_for['city'],
		);
		$rates    = (array) WC_Tax::find_rates( array_merge( $location, array( 'tax_class' => $class ) ) );

		list( $found, $missing ) = TaxLocation::line_rates( $decision, $line, $class, $location, $this->calculating['customer'], $rates );
		if ( $missing ) {
			$this->calculating['missing'] = true;
		}
		if ( $found === $rates ) {
			return;
		}
		$item->set_taxes(
			array(
				'total'    => WC_Tax::calc_tax( (float) $item->get_total(), $found, false ),
				'subtotal' => WC_Tax::calc_tax( (float) $item->get_subtotal(), $found, false ),
			)
		);
	}

	/**
	 * @param WC_Order_Item_Shipping $item
	 * @param array<string,string>   $calculate_tax_for
	 */
	public function steer_shipping( $item, $calculate_tax_for ): void {
		if ( null === $this->calculating || $item->get_order_id() !== $this->calculating['order_id'] ) {
			return;
		}
		$decision = $this->calculating['decision'];
		if ( $decision->is_vat_exempt() ) {
			return;
		}
		if ( ! TaxLocation::charges_shipping( $decision ) ) {
			$item->set_taxes( array() );
			return;
		}
		if ( 'taxable' !== $item->get_tax_status() ) {
			return;
		}

		$location = array(
			'country'  => (string) $calculate_tax_for['country'],
			'state'    => (string) $calculate_tax_for['state'],
			'postcode' => (string) $calculate_tax_for['postcode'],
			'city'     => (string) $calculate_tax_for['city'],
		);
		$class    = (string) $calculate_tax_for['tax_class'];
		$woo      = (array) WC_Tax::find_shipping_rates( array_merge( $location, array( 'tax_class' => $class ) ) );

		list( $found, $missing ) = TaxLocation::shipping_rates( $decision, $class, $location, $this->calculating['customer'], $woo );
		if ( $missing ) {
			$this->calculating['missing'] = true;
		}
		if ( $found !== $woo ) {
			$item->set_taxes( array( 'total' => WC_Tax::calc_tax( (float) $item->get_total(), $found, false ) ) );
		}
	}

	/**
	 * Flags a stored decision whose rates had to fall back. A live checkout
	 * decision was already flagged by the cart calculation.
	 *
	 * @param bool  $and_taxes
	 * @param mixed $order
	 */
	public function finish( $and_taxes, $order ): void {
		$calculating       = $this->calculating;
		$this->calculating = null;
		if ( null === $calculating || ! $calculating['missing'] || ! $order instanceof WC_Order ) {
			return;
		}
		$stored = DecisionStore::get( $order );
		if ( null !== $stored && ! in_array( TaxLocation::MISSING_FLAG, $stored->flags(), true ) ) {
			$order->update_meta_data( DecisionStore::META, (string) wp_json_encode( $stored->with_flag( TaxLocation::MISSING_FLAG )->to_array() ) );
		}
	}

	/**
	 * @param string[] $hidden
	 * @return string[]
	 */
	public function hide_line_key( $hidden ) {
		$hidden[] = InputGatherer::LINE_KEY_META;
		return $hidden;
	}

	/**
	 * @param WC_Order_Item_Product $item
	 * @param string                $cart_item_key
	 */
	public function tag_line_item( $item, $cart_item_key ): void {
		$item->add_meta_data( InputGatherer::LINE_KEY_META, (string) $cart_item_key, true );
	}
}
