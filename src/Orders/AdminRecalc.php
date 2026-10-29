<?php

namespace Vatright\Orders;

use Vatright\Checkout\CartSteering;
use Vatright\Checkout\InputGatherer;
use Vatright\Domain\Decision;
use Vatright\Domain\Rules;
use Vatright\Domain\VatNumber;
use Vatright\Migration\LegacyData;
use Throwable;
use WC_Order;

/**
 * Re-decides an order when an admin recalculates its taxes, so the recorded
 * decision, the log and Woo's own calculation agree.
 */
class AdminRecalc {

	/**
	 * The order edit screen posts billing fields as _billing_{key}; the
	 * checkout UI adds this one to that screen.
	 */
	const FIELD = '_billing_vatright_number';

	/** @var InputGatherer */
	private $gatherer;

	/** @var Rules */
	private $rules;

	/** @var CartSteering */
	private $cart;

	public function __construct( InputGatherer $gatherer, Rules $rules, CartSteering $cart ) {
		$this->gatherer = $gatherer;
		$this->rules    = $rules;
		$this->cart     = $cart;
	}

	public function register(): void {
		add_action( 'woocommerce_order_before_calculate_taxes', array( $this, 'recalculate' ), 10, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_number' ), 45 );
	}

	/**
	 * @param mixed $args
	 * @param mixed $order
	 */
	public function recalculate( $args, $order ): void {
		if ( ! $order instanceof WC_Order || ! $this->is_admin_edit( $order ) ) {
			return;
		}

		try {
			$decision = $this->rules->decide( $this->gatherer->from_order( self::at_taxed_address( $order, $args ), 'admin', true ) );
		} catch ( Throwable $e ) {
			wc_get_logger()->error( 'VAT decision on admin recalculation failed: ' . $e->getMessage(), array( 'source' => 'vatright' ) );
			return;
		}

		$previous = DecisionStore::get( $order );
		if ( null === $previous || $decision->differs_in_tax_from( $previous ) || self::number_of( $decision ) !== self::number_of( $previous ) ) {
			DecisionStore::apply( $order, $decision, 'admin', get_current_user_id() );
		}
	}

	/**
	 * Stores the number typed in the order's billing box as the order's VAT
	 * number. The decision is not touched until taxes are recalculated.
	 *
	 * @param int|string $order_id
	 */
	public function save_number( $order_id ): void {
		$order = wc_get_order( $order_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the order nonce before this hook runs.
		if ( ! $order instanceof WC_Order || ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_order', $order->get_id() ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next line once it is known to be a string.
		$posted = wp_unslash( $_POST[ self::FIELD ] );
		$raw    = is_string( $posted ) ? trim( sanitize_text_field( $posted ) ) : '';
		$parsed = '' === $raw ? null : VatNumber::parse( $raw, $order->get_billing_country() );
		$number = null === $parsed ? $raw : $parsed->canonical();

		if ( LegacyData::order_number( $order ) === $number ) {
			return;
		}
		if ( '' === $number && '' === (string) $order->get_meta( DecisionStore::NUMBER_META ) ) {
			// Clearing a number another plugin left: an empty value of ours stops it being read.
			$order->update_meta_data( DecisionStore::NUMBER_META, '' );
		} elseif ( '' === $number ) {
			$order->delete_meta_data( DecisionStore::NUMBER_META );
		} else {
			$order->update_meta_data( DecisionStore::NUMBER_META, $number );
		}
		$order->save_meta_data();
	}

	/**
	 * Draft orders belong to the checkout and unattended recalculations such
	 * as renewals to their own trigger; only a user who can edit orders
	 * recalculating outside a checkout is an admin edit.
	 */
	private function is_admin_edit( WC_Order $order ): bool {
		return 'checkout-draft' !== $order->get_status()
			&& null === $this->cart->checkout_decision()
			&& current_user_can( 'edit_shop_orders' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown
	}

	/**
	 * The Recalculate button posts the address in the form, saved or not, and
	 * Woo taxes at it. The decision is made on a copy carrying that address so
	 * deciding never saves an edit the admin has not saved.
	 *
	 * @param mixed $args
	 */
	private static function at_taxed_address( WC_Order $order, $args ): WC_Order {
		$based_on = get_option( 'woocommerce_tax_based_on' );
		if ( ! is_array( $args ) || empty( $args['country'] ) || 'base' === $based_on ) {
			return $order;
		}
		$type = 'billing' === $based_on || ! $order->has_shipping_address() ? 'billing' : 'shipping';
		$copy = clone $order;
		foreach ( array( 'country', 'state', 'postcode', 'city' ) as $field ) {
			$copy->{"set_{$type}_{$field}"}( (string) ( $args[ $field ] ?? '' ) );
		}
		return $copy;
	}

	private static function number_of( Decision $decision ): string {
		return (string) ( $decision->to_array()['vat_number']['canonical'] ?? '' );
	}
}
