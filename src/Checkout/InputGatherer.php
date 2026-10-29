<?php

namespace Vatright\Checkout;

use Vatright\Domain\DecisionInput;
use Vatright\Domain\Evidence;
use Vatright\Domain\Line;
use Vatright\Domain\VatNumber;
use Vatright\Ledger\Fx;
use Vatright\Ledger\ThresholdTracker;
use Vatright\Migration\LegacyData;
use Vatright\Orders\DecisionStore;
use Vatright\Registry\Validator;
use Vatright\Settings;
use WC_Cart;
use WC_Customer;
use WC_Geolocation;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product;

class InputGatherer {

	const NUMBER_META = '_vatright_number';

	/**
	 * Session key holding the billing country the customer self-certified.
	 * Ignored once the billing country no longer matches it.
	 */
	const SELF_CERTIFIED_SESSION = 'vatright_self_certified';

	/**
	 * Order item meta tying an order line to the cart line the checkout
	 * decision was made for.
	 */
	const LINE_KEY_META = '_vatright_line_key';

	const PICKUP_METHODS = array( 'local_pickup', 'pickup_location', 'legacy_local_pickup' );

	/**
	 * Triggers where the order is not in the ledger yet, so its own amount
	 * can push the year over the threshold.
	 */
	const NEW_SALE_TRIGGERS = array( 'cart', 'checkout', 'renewal' );

	/** @var Validator */
	private $validator;

	/** @var ThresholdTracker */
	private $threshold;

	/** @var Fx */
	private $fx;

	public function __construct( Validator $validator, ThresholdTracker $threshold, Fx $fx ) {
		$this->validator = $validator;
		$this->threshold = $threshold;
		$this->fx        = $fx;
	}

	public function from_cart( WC_Cart $cart, WC_Customer $customer, string $trigger, bool $allow_network ): DecisionInput {
		$lines = array();
		$net   = 0.0;
		foreach ( $cart->get_cart() as $key => $item ) {
			$product = $item['data'];
			$type    = SupplyType::for_product( $product );
			$lines[] = new Line( (string) $key, $type, $product->needs_shipping() );
			if ( Line::SERVICE !== $type ) {
				$net += (float) wc_get_price_excluding_tax( $product, array( 'qty' => $item['quantity'] ) );
			}
		}

		$billing   = strtoupper( $customer->get_billing_country() );
		$shipping  = $cart->needs_shipping() ? strtoupper( $customer->get_shipping_country() ) : '';
		$session   = CartSteering::session();
		$certified = null === $session ? null : $session->get( self::SELF_CERTIFIED_SESSION );

		return $this->input(
			array(
				'billing'           => $billing,
				'billing_postcode'  => $customer->get_billing_postcode(),
				'shipping'          => $shipping,
				'shipping_postcode' => '' === $shipping ? '' : $customer->get_shipping_postcode(),
				'pickup'            => (bool) array_intersect( wc_get_chosen_shipping_method_ids(), self::PICKUP_METHODS ),
				'number'            => LegacyData::customer_number( $customer ),
				'company'           => $customer->get_billing_company(),
				'user_id'           => $customer->get_id(),
				'ip'                => $this->ip_country( WC_Geolocation::get_ip_address() ),
				'self_certified'    => is_string( $certified ) && '' !== $billing && strtoupper( $certified ) === $billing ? $billing : null,
				'lines'             => $lines,
				'crossing'          => in_array( $trigger, self::NEW_SALE_TRIGGERS, true ) && $this->crosses( $net, get_woocommerce_currency(), $allow_network ),
			),
			$trigger,
			$allow_network
		);
	}

	/**
	 * Evidence captured at checkout (IP country, self-certification) is
	 * reused from the order's previous decision: whoever triggers a later
	 * decision, such as an admin, is not the customer.
	 */
	public function from_order( WC_Order $order, string $trigger, bool $allow_network ): DecisionInput {
		$ships = count( $order->get_shipping_methods() ) > 0;
		$lines = array();
		$net   = 0.0;
		$goods = false;
		foreach ( $order->get_items() as $id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$product = $product instanceof WC_Product ? $product : null;
			$type    = $product ? SupplyType::for_product( $product ) : ( $ships ? Line::GOODS : Line::DIGITAL );
			$key     = (string) $item->get_meta( self::LINE_KEY_META );
			$lines[] = new Line( '' === $key ? (string) $id : $key, $type, $product ? $product->needs_shipping() : Line::GOODS === $type );
			$goods   = $goods || Line::GOODS === $type;
			if ( Line::SERVICE !== $type ) {
				$net += (float) $item->get_total();
			}
		}

		$billing  = strtoupper( $order->get_billing_country() );
		$shipping = $goods ? strtoupper( $order->get_shipping_country() ) : '';
		$previous = DecisionStore::get( $order );
		$evidence = null === $previous ? array() : (array) $previous->to_array()['evidence'];
		$methods  = array_map(
			static function ( WC_Order_Item_Shipping $method ): string {
				return $method->get_method_id();
			},
			array_values( $order->get_shipping_methods() )
		);

		return $this->input(
			array(
				'billing'           => $billing,
				'billing_postcode'  => $order->get_billing_postcode(),
				'shipping'          => $shipping,
				'shipping_postcode' => '' === $shipping ? '' : $order->get_shipping_postcode(),
				'pickup'            => (bool) array_intersect( $methods, self::PICKUP_METHODS ),
				'number'            => LegacyData::order_number( $order ),
				'company'           => '',
				'user_id'           => $order->get_customer_id(),
				'ip'                => array_key_exists( 'ip', $evidence ) ? $evidence['ip'] : $this->ip_country( $order->get_customer_ip_address() ),
				'self_certified'    => isset( $evidence['self_certified'] ) && $evidence['self_certified'] === $billing ? $billing : null,
				'lines'             => $lines,
				'crossing'          => in_array( $trigger, self::NEW_SALE_TRIGGERS, true ) && $this->crosses( $net, $order->get_currency(), $allow_network ),
			),
			$trigger,
			$allow_network
		);
	}

	/**
	 * @param array{billing: string, billing_postcode: string, shipping: string, shipping_postcode: string, pickup: bool, number: string, company: string, user_id: int, ip: ?string, self_certified: ?string, lines: Line[], crossing: bool} $in
	 */
	private function input( array $in, string $trigger, bool $allow_network ): DecisionInput {
		$settings = Settings::get();
		$raw      = trim( $in['number'] );
		$number   = '' === $raw ? null : VatNumber::parse( $raw, $in['billing'] );
		$status   = $this->threshold->status( (int) wp_date( 'Y' ) );
		$exceeded = $status->exceeded || $in['crossing'];

		$threshold             = $status->to_array();
		$threshold['exceeded'] = $exceeded;

		$validation = null;
		if ( null !== $number ) {
			$name       = NameMismatch::lookup_name( $in['company'] );
			$validation = 'checkout' === $trigger && $allow_network ? $this->validator->validate_for_visitor( $number, 'checkout', $name ) : $this->validator->validate( $number, $allow_network, false, $name );
		}
		// A throttled lookup is something a visitor can bring about, so the merchant's outage policy does not apply: an exemption or a refusal would be theirs to trigger.
		$outage = null !== $validation && 'throttled' === $validation->detail() ? 'charge' : $settings->outage_policy();

		return new DecisionInput(
			WC()->countries->get_base_country(),
			$settings->oss_registered(),
			$exceeded,
			$threshold,
			$outage,
			$settings->allow_domestic_exemption(),
			$settings->fixed_prices(),
			$in['billing'],
			$in['billing_postcode'],
			$in['shipping'],
			$in['shipping_postcode'],
			$in['pickup'],
			$number,
			'' === $raw ? null : $raw,
			$validation,
			new Evidence(
				'' === $in['billing'] ? null : $in['billing'],
				'' === $in['shipping'] ? null : $in['shipping'],
				$in['ip'],
				$in['self_certified']
			),
			$in['lines'],
			$trigger,
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			$this->exempt_roles( $in['user_id'] )
		);
	}

	/**
	 * @return string[]
	 */
	private function exempt_roles( int $user_id ): array {
		$configured = Settings::get()->exempt_roles();
		$user       = $user_id > 0 && array() !== $configured ? get_userdata( $user_id ) : false;
		return $user ? array_values( array_intersect( $configured, $user->roles ) ) : array();
	}

	/**
	 * Converting a non-euro amount may need an ECB request, which is only
	 * made when the caller allows network calls; without a rate the order's
	 * own amount is left out and only the ledger totals count.
	 */
	private function crosses( float $net, string $currency, bool $allow_network ): bool {
		if ( 'EUR' !== $currency ) {
			$rate = $allow_network ? $this->fx->rate_on_or_before( $currency, gmdate( 'Y-m-d' ) ) : null;
			if ( null === $rate || $rate <= 0 ) {
				return false;
			}
			$net = $net / $rate;
		}
		return $this->threshold->crossing( $net );
	}

	/**
	 * Uses only the local geolocation database (or a filter); never the
	 * external lookup services, so this never makes a request.
	 */
	private function ip_country( string $ip ): ?string {
		if ( '' === $ip ) {
			return null;
		}
		$country = strtoupper( (string) ( WC_Geolocation::geolocate_ip( $ip, false, false )['country'] ?? '' ) );
		return '' === $country ? null : $country;
	}
}
