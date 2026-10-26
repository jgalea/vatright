<?php

namespace Vatright\Tests\Integration\Checkout;

use Vatright\Checkout\CartSteering;
use Vatright\Checkout\InputGatherer;
use Vatright\Checkout\SupplyType;
use Vatright\Checkout\TaxLocation;
use Vatright\Domain\Decision;
use Vatright\Ledger\ThresholdTracker;
use Vatright\Orders\DecisionStore;
use Vatright\Plugin;
use Vatright\Settings;
use Vatright\Tests\Support\HttpGuard;
use Vatright\Tests\Support\IntegrationTestCase;
use RuntimeException;
use WC_Order;
use WC_Product_Simple;
use WC_Shipping_Zone;
use WC_Tax;
use WP_REST_Request;
use WP_REST_Response;
use WPDieException;

abstract class CheckoutTestCase extends IntegrationTestCase {

	const VALID_DE   = 'DE114103379';
	const INVALID_DE = 'DE123475223';
	const DOWN_DE    = 'DE129273398';

	/** @var int */
	protected $vies_calls = 0;

	/** @var array<string,array<string,mixed>> vatNumber digits => WP HTTP response */
	protected $vies = array();

	/** @var array<string,int> country => standard rate id */
	protected $rates = array();

	/** @var int */
	protected $pickup_instance = 0;

	/** @var int */
	protected $flat_instance = 0;

	public function set_up() {
		parent::set_up();

		update_option( 'woocommerce_default_country', 'MT' );
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'shipping' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
		update_option( 'woocommerce_shipping_tax_class', 'inherit' );
		delete_option( Settings::OPTION );
		delete_option( TaxLocation::MISSING_OPTION );
		Settings::reset();
		$this->install_schema();
		ThresholdTracker::flush( (int) wp_date( 'Y' ), (int) wp_date( 'Y' ) - 1 );

		$this->rates = array();
		foreach ( array(
			'MT' => '18',
			'DE' => '19',
			'FR' => '20',
			'ES' => '21',
		) as $country => $percent ) {
			$this->rates[ $country ] = $this->add_rate( $country, $percent );
		}

		$zone = new WC_Shipping_Zone( 0 );
		foreach ( $zone->get_shipping_methods() as $method ) {
			$zone->delete_shipping_method( $method->instance_id );
		}
		$this->flat_instance = $zone->add_shipping_method( 'flat_rate' );
		update_option(
			'woocommerce_flat_rate_' . $this->flat_instance . '_settings',
			array(
				'title'      => 'Flat',
				'tax_status' => 'taxable',
				'cost'       => '10',
			)
		);
		$this->pickup_instance = $zone->add_shipping_method( 'local_pickup' );
		update_option(
			'woocommerce_local_pickup_' . $this->pickup_instance . '_settings',
			array(
				'title'      => 'Pickup',
				'tax_status' => 'taxable',
				'cost'       => '0',
			)
		);
		\WC_Cache_Helper::invalidate_cache_group( 'shipping_zones' );

		$this->vies_calls = 0;
		$this->vies       = array();
		HttpGuard::uninstall();
		add_filter( 'pre_http_request', array( $this, 'route_vies' ), PHP_INT_MAX, 3 );
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		add_filter( 'woocommerce_cart_needs_payment', '__return_false' );
		add_filter( 'woocommerce_order_needs_payment', '__return_false' );

		$this->vies_fixture( 'sample_DE114103379' );

		// Store API route objects keep the order they placed; each test is a new visitor.
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core's global.
		wp_set_current_user( 0 );
		// The first cart's session handler stays hooked for the whole run, so the
		// cart object is kept and only emptied; session and customer start fresh.
		WC()->session  = null;
		WC()->customer = null;
		wc_load_cart();
		WC()->cart->empty_cart();
		$this->steering()->reset();
	}

	public function tear_down() {
		WC()->cart->empty_cart();
		$this->steering()->reset();
		Settings::reset();
		parent::tear_down();
	}

	protected function steering(): CartSteering {
		$steering = Plugin::cart_steering();
		$this->assertNotNull( $steering, 'Plugin did not boot the cart steering.' );
		return $steering;
	}

	/**
	 * @param array<string,mixed> $values
	 */
	protected function settings( array $values ): void {
		update_option( Settings::OPTION, $values );
		Settings::reset();
	}

	protected function add_rate( string $country, string $percent, string $tax_class = '', string $postcode = '' ): int {
		$id = (int) WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => $country,
				'tax_rate'          => $percent,
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => $tax_class,
			)
		);
		if ( '' !== $postcode ) {
			WC_Tax::_update_tax_rate_postcodes( $id, $postcode );
		}
		return $id;
	}

	protected function product( string $supply = 'goods', string $price = '100', string $tax_class = '' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( ucfirst( $supply ) );
		$product->set_regular_price( $price );
		$product->set_virtual( 'goods' !== $supply );
		$product->set_tax_class( $tax_class );
		if ( 'service' === $supply ) {
			$product->update_meta_data( SupplyType::META, 'service' );
		}
		$product->save();
		return $product;
	}

	protected function add_to_cart( WC_Product_Simple ...$products ): void {
		foreach ( $products as $product ) {
			WC()->cart->add_to_cart( $product->get_id(), 1 );
		}
	}

	protected function customer( string $country, string $postcode = '', string $vat = '' ): void {
		$customer = WC()->customer;
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$customer->{"set_{$type}_country"}( $country );
			$customer->{"set_{$type}_state"}( '' );
			$customer->{"set_{$type}_postcode"}( $postcode );
			$customer->{"set_{$type}_city"}( 'City' );
			$customer->{"set_{$type}_address_1"}( '1 Street' );
		}
		$customer->update_meta_data( InputGatherer::NUMBER_META, $vat );
	}

	protected function calculate(): ?Decision {
		WC()->cart->calculate_totals();
		return $this->steering()->current();
	}

	/**
	 * @return array<int,float> Tax rate id => item tax across the cart.
	 */
	protected function item_taxes(): array {
		$taxes = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			foreach ( $item['line_tax_data']['total'] ?? array() as $rate_id => $tax ) {
				$taxes[ (int) $rate_id ] = ( $taxes[ (int) $rate_id ] ?? 0.0 ) + (float) $tax;
			}
		}
		return $taxes;
	}

	protected function vies_fixture( string $name ): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/vies/' . $name . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		preg_match( '/"vatNumber":"([^"]+)"/', (string) $fixture['request']['body_contains'], $match );
		$this->vies[ $match[1] ] = $this->http( (int) $fixture['response']['status'], (string) $fixture['response']['body'] );
	}

	/**
	 * A test-local registry answer for a number with no recorded fixture.
	 */
	protected function vies_answer( string $number, string $body ): void {
		$this->vies[ substr( $number, 2 ) ] = $this->http( 200, $body );
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function http( int $status, string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Takes the HTTP guard's place (HttpGuard ignores answers given by earlier
	 * filters, so it cannot sit behind this one): counts every VIES request,
	 * answers the numbers a test registered and throws on everything else.
	 *
	 * @param false|array<string,mixed> $preempt
	 * @param array<string,mixed>       $args
	 * @return false|array<string,mixed>
	 */
	public function route_vies( $preempt, $args, string $url ) {
		if ( false !== strpos( $url, 'check-vat-number' ) ) {
			++$this->vies_calls;
			foreach ( $this->vies as $digits => $response ) {
				if ( false !== strpos( (string) ( $args['body'] ?? '' ), '"vatNumber":"' . $digits . '"' ) ) {
					return $response;
				}
			}
		}
		throw new RuntimeException( "Unstubbed outbound request: $url" ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * @param array<string,mixed> $body
	 */
	protected function store_api( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wc/store/v1/' . $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	/**
	 * @return array<string,string>
	 */
	protected function address( string $country, string $postcode = '1000', bool $with_email = true ): array {
		$address = array(
			'first_name' => 'Ada',
			'last_name'  => 'Lovelace',
			'address_1'  => '1 Street',
			'city'       => 'City',
			'state'      => '',
			'postcode'   => $postcode,
			'country'    => $country,
			'phone'      => '123456',
		);
		if ( $with_email ) {
			$address['email'] = 'ada@example.com';
		}
		return $address;
	}

	/**
	 * Places an order through the Store API checkout route the way the
	 * checkout block does: an address update request, then the place-order
	 * request. The cart is filled beforehand. Shipping defaults to billing.
	 *
	 * @param array<string,mixed> $extra
	 */
	protected function block_checkout( string $country, string $postcode = '1000', array $extra = array(), string $ship_country = '', string $ship_postcode = '' ): WP_REST_Response {
		$addresses = array(
			'billing_address'  => $this->address( $country, $postcode ),
			'shipping_address' => $this->address( '' === $ship_country ? $country : $ship_country, '' === $ship_country ? $postcode : $ship_postcode, false ),
		);
		$this->assertSame( 200, $this->store_api( 'POST', 'cart/update-customer', $addresses )->get_status() );
		if ( WC()->cart->needs_shipping() ) {
			$selected = $this->store_api(
				'POST',
				'cart/select-shipping-rate',
				array(
					'package_id' => 0,
					'rate_id'    => 'flat_rate:' . $this->flat_instance,
				)
			);
			$this->assertSame( 200, $selected->get_status(), (string) wp_json_encode( $selected->get_data() ) );
		}
		$this->steering()->reset();
		return $this->store_api( 'POST', 'checkout', array_merge( $addresses, array( 'payment_method' => '' ), $extra ) );
	}

	/**
	 * Runs WC_Checkout::process_checkout() as the classic checkout's AJAX
	 * request does and returns its JSON reply.
	 *
	 * @param array<string,string> $fields
	 * @return array<string,mixed>
	 */
	protected function classic_checkout( string $country, string $postcode = '1000', array $fields = array() ): array {
		$this->steering()->reset();
		$_POST    = array_merge(
			array(
				'billing_first_name'                 => 'Ada',
				'billing_last_name'                  => 'Lovelace',
				'billing_address_1'                  => '1 Street',
				'billing_city'                       => 'City',
				'billing_state'                      => '',
				'billing_postcode'                   => $postcode,
				'billing_country'                    => $country,
				'billing_email'                      => 'ada@example.com',
				'billing_phone'                      => '123456',
				'ship_to_different_address'          => '0',
				'payment_method'                     => '',
				'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
			),
			$fields
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the test sets the nonce itself.

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function ( $message ) {
					throw new WPDieException( is_string( $message ) ? esc_html( $message ) : '' );
				};
			}
		);

		ob_start();
		try {
			WC()->checkout()->process_checkout();
		} catch ( WPDieException $e ) {
			unset( $e );
		}
		$reply = (string) ob_get_clean();

		$_POST    = array();
		$_REQUEST = array();
		remove_all_filters( 'wp_doing_ajax' );

		// Without an exit, process_checkout() goes on to print its failure reply too; the first reply is the real one.
		$first   = strpos( $reply, '}{' );
		$decoded = json_decode( false === $first ? $reply : substr( $reply, 0, $first + 1 ), true );
		$this->assertIsArray( $decoded, 'Classic checkout did not answer with JSON: ' . $reply );
		return $decoded;
	}

	/**
	 * @param array<string,mixed> $reply
	 */
	protected function placed( array $reply ): WC_Order {
		$this->assertSame( 'success', $reply['result'] ?? null, (string) wp_json_encode( $reply ) );
		$orders = wc_get_orders(
			array(
				'limit'   => 1,
				'orderby' => 'id',
				'order'   => 'DESC',
			)
		);
		$this->assertCount( 1, $orders );
		return $orders[0];
	}

	protected function order_from( WP_REST_Response $response ): WC_Order {
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( (int) $response->get_data()['order_id'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		return $order;
	}

	protected function stored_decision( WC_Order $order ): Decision {
		$fresh    = wc_get_order( $order->get_id() );
		$decision = DecisionStore::get( $fresh instanceof WC_Order ? $fresh : $order );
		$this->assertNotNull( $decision, 'No decision stored on the order.' );
		return $decision;
	}

	/**
	 * @return array<int,float> Tax rate id => tax total on the order.
	 */
	protected function order_taxes( WC_Order $order ): array {
		$taxes = array();
		foreach ( $order->get_taxes() as $tax ) {
			$taxes[ (int) $tax->get_rate_id() ] = (float) $tax->get_tax_total() + (float) $tax->get_shipping_tax_total();
		}
		return $taxes;
	}
}
