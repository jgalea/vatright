<?php

namespace Vatright\Checkout;

use Vatright\Domain\Decision;
use Vatright\Domain\Rules;
use Vatright\Settings;
use Throwable;
use WC_Cart;
use WC_Customer;
use WC_Session;
use WC_Tax;

/**
 * Decides on every cart totals calculation and steers WooCommerce's own tax
 * calculation through the customer exemption flag, the taxable address and
 * per-line rates.
 *
 * @phpstan-import-type Address from TaxLocation
 */
class CartSteering {

	const SESSION_KEY = 'vatright_decision';

	const UNKNOWN_COUNTRY_FLAG = 'unknown_billing_country';

	/** @var InputGatherer */
	private $gatherer;

	/** @var Rules */
	private $rules;

	/** @var Decision|null */
	private $current = null;

	/** @var WC_Customer|null */
	private $customer = null;

	/** @var Address|null */
	private $customer_address = null;

	/** @var bool */
	private $checkout = false;

	/**
	 * Shipping rates to hand Woo for the shipping rate lookup that follows the
	 * woocommerce_shipping_tax_class filter, keyed by that lookup's arguments.
	 *
	 * @var array{args: array<string,string>, rates: array<int|string,mixed>}|null
	 */
	private $shipping_rates = null;

	public function __construct( InputGatherer $gatherer, Rules $rules ) {
		$this->gatherer = $gatherer;
		$this->rules    = $rules;
	}

	public function register(): void {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'on_before_calculate_totals' ), 5 );
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'decide_before_shipping' ) );
		add_filter( 'woocommerce_customer_taxable_address', array( $this, 'filter_taxable_address' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_get_item_tax_rates', array( $this, 'filter_item_tax_rates' ), 10, 3 );
		add_filter( 'woocommerce_shipping_tax_class', array( $this, 'filter_shipping_tax_class' ), 10, 4 );
		add_filter( 'woocommerce_find_rates', array( $this, 'filter_shipping_rates' ), 10, 2 );
		add_filter( 'woocommerce_adjust_non_base_location_prices', array( $this, 'adjust_non_base_location_prices' ) );
	}

	/**
	 * From here on in this request, decisions are final checkout decisions:
	 * one live registry check is allowed on a cache miss.
	 */
	public function arm_checkout(): void {
		$this->checkout = true;
	}

	public function reset(): void {
		$this->current          = null;
		$this->customer         = null;
		$this->customer_address = null;
		$this->checkout         = false;
		$this->shipping_rates   = null;
	}

	/**
	 * The decision made in this request, else the last one kept in the session.
	 */
	public function current(): ?Decision {
		if ( null !== $this->current ) {
			return $this->current;
		}
		$session = self::session();
		$stored  = null === $session ? null : $session->get( self::SESSION_KEY );
		return is_array( $stored ) ? Decision::from_array( $stored ) : null;
	}

	/**
	 * The final checkout decision, once one was made in this request.
	 */
	public function checkout_decision(): ?Decision {
		return $this->checkout ? $this->current : null;
	}

	public function on_before_calculate_totals( WC_Cart $cart ): void {
		$this->decide( $cart );
	}

	/**
	 * The classic order review prices shipping before the request's first
	 * totals calculation, and Woo taxes each shipping rate as it prices it.
	 *
	 * Woo caches each package's priced rates in the session by a hash of the
	 * package, so the country shipping is taxed in goes into the package. The
	 * shipping rate lookup runs once here as well, so a cached package still
	 * flags a decision whose shipping rate had to fall back.
	 *
	 * @param array<int|string,mixed> $packages
	 * @return array<int|string,mixed>
	 */
	public function decide_before_shipping( $packages ) {
		$cart = WC()->cart;
		if ( null === $this->current || $cart->get_customer() !== $this->customer ) {
			$this->decide( $cart );
		}
		if ( null === $this->current ) {
			return $packages;
		}

		WC_Tax::get_shipping_tax_rates( null, $this->customer );
		$line     = TaxLocation::charges_shipping( $this->current ) ? TaxLocation::shipping_line( $this->current ) : null;
		$taxed_in = null === $line ? '' : $line->tax_country();
		foreach ( $packages as $index => $package ) {
			if ( is_array( $package ) ) {
				$packages[ $index ]['vatright_taxed_in'] = $taxed_in;
			}
		}
		return $packages;
	}

	/**
	 * Never leaves an earlier exemption in place: the customer flag is set on
	 * every run, and when deciding fails the customer is charged.
	 */
	public function decide( WC_Cart $cart ): ?Decision {
		$customer               = $cart->get_customer();
		$this->customer         = $customer;
		$this->customer_address = null;

		try {
			$decision = $this->rules->decide(
				$this->gatherer->from_cart( $cart, $customer, $this->checkout ? 'checkout' : 'cart', $this->checkout )
			);
		} catch ( Throwable $e ) {
			wc_get_logger()->error( 'VAT decision failed, charging VAT: ' . $e->getMessage(), array( 'source' => 'vatright' ) );
			$decision = null;
		}

		if ( null !== $decision && InputGatherer::is_unknown_country( $customer->get_billing_country() ) ) {
			$decision = $decision->with_flag( self::UNKNOWN_COUNTRY_FLAG );
		}
		$customer->set_is_vat_exempt( null !== $decision && $decision->is_vat_exempt() );
		$this->store( $decision );
		return $decision;
	}

	/**
	 * @param string[]    $address Country, state, postcode, city.
	 * @param WC_Customer $customer
	 * @return string[]
	 */
	public function filter_taxable_address( $address, $customer ) {
		if ( null === $this->current || $customer !== $this->customer ) {
			return $address;
		}

		$this->customer_address     = array(
			'country'  => (string) $address[0],
			'state'    => (string) $address[1],
			'postcode' => (string) $address[2],
			'city'     => (string) $address[3],
		);
		list( $location, $missing ) = TaxLocation::address( $this->current, $this->customer_address );
		if ( $missing ) {
			$this->flag_missing();
		}
		return array( $location['country'], $location['state'], $location['postcode'], $location['city'] );
	}

	/**
	 * @param array<int|string,mixed> $rates
	 * @param object{key: string, tax_class: string} $item WC_Cart_Totals item.
	 * @param WC_Cart                 $cart
	 * @return array<int|string,mixed>
	 */
	public function filter_item_tax_rates( $rates, $item, $cart ) {
		$decision = $this->current;
		if ( null === $decision || $decision->is_vat_exempt() || $cart->get_customer() !== $this->customer ) {
			return $rates;
		}
		$line = $decision->line( (string) $item->key );
		if ( null === $line ) {
			return $rates;
		}

		$raw                     = $this->customer->get_taxable_address();
		$location                = array(
			'country'  => (string) $raw[0],
			'state'    => (string) $raw[1],
			'postcode' => (string) $raw[2],
			'city'     => (string) $raw[3],
		);
		list( $found, $missing ) = TaxLocation::line_rates( $decision, $line, (string) $item->tax_class, $location, $this->customer_address ?? $location, (array) $rates );
		if ( $missing ) {
			$this->flag_missing();
		}
		return $found;
	}

	/**
	 * Shipping is taxed like the line it follows. When that needs other rates
	 * than Woo is about to find at $location, they are handed over by
	 * filter_shipping_rates() on the lookup that follows.
	 *
	 * @param string|null      $tax_class
	 * @param WC_Cart|null     $cart
	 * @param WC_Customer|null $customer Null means the current customer.
	 * @param string[]         $location Country, state, postcode, city.
	 * @return string|null
	 */
	public function filter_shipping_tax_class( $tax_class, $cart, $customer, $location = array() ) {
		$this->shipping_rates = null;
		if ( null === $this->current || ( $customer ?? WC()->customer ) !== $this->customer || $this->current->is_vat_exempt() || null === $tax_class ) {
			return $tax_class;
		}
		if ( ! TaxLocation::charges_shipping( $this->current ) ) {
			return null;
		}
		if ( 4 !== count( $location ) || '' === (string) $location[0] ) {
			return $tax_class;
		}

		$args                    = array(
			'country'   => (string) $location[0],
			'state'     => (string) $location[1],
			'postcode'  => (string) $location[2],
			'city'      => (string) $location[3],
			'tax_class' => (string) $tax_class,
		);
		$at                      = array_slice( $args, 0, 4 );
		$woo                     = (array) WC_Tax::find_shipping_rates( $args );
		list( $rates, $missing ) = TaxLocation::shipping_rates( $this->current, (string) $tax_class, $at, $this->customer_address ?? $at, $woo );
		if ( $missing ) {
			$this->flag_missing();
		}
		if ( $rates !== $woo ) {
			ksort( $args );
			$this->shipping_rates = array(
				'args'  => $args,
				'rates' => $rates,
			);
		}
		return $tax_class;
	}

	/**
	 * @param array<int|string,mixed> $rates
	 * @param array<string,string>    $args
	 * @return array<int|string,mixed>
	 */
	public function filter_shipping_rates( $rates, $args ) {
		if ( null === $this->shipping_rates ) {
			return $rates;
		}
		ksort( $args );
		if ( $args !== $this->shipping_rates['args'] ) {
			return $rates;
		}
		$replacement          = $this->shipping_rates['rates'];
		$this->shipping_rates = null;
		return $replacement;
	}

	/**
	 * @param bool $adjust
	 * @return bool
	 */
	public function adjust_non_base_location_prices( $adjust ) {
		return $adjust && ! Settings::get()->fixed_prices();
	}

	private function flag_missing(): void {
		if ( null !== $this->current && ! in_array( TaxLocation::MISSING_FLAG, $this->current->flags(), true ) ) {
			$this->store( $this->current->with_flag( TaxLocation::MISSING_FLAG ) );
		}
	}

	private function store( ?Decision $decision ): void {
		$this->current = $decision;
		$session       = self::session();
		if ( null !== $session ) {
			$session->set( self::SESSION_KEY, null === $decision ? null : $decision->to_array() );
		}
	}

	/**
	 * WooCommerce only starts a session on front-end requests; the stubs type
	 * the property as always set.
	 */
	public static function session(): ?WC_Session {
		$session = WC()->session;
		return $session instanceof WC_Session ? $session : null; // @phpstan-ignore instanceof.alwaysTrue
	}
}
