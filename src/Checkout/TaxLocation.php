<?php

namespace Vatright\Checkout;

use Vatright\Domain\Decision;
use Vatright\Domain\Line;
use Vatright\Domain\LineDecision;
use Vatright\Domain\Scheme;
use WC_Tax;

/**
 * Translates a decision into the address and rates WooCommerce taxes with.
 *
 * When the rules ask for VAT in a country the store's tax tables have no rate
 * for, Woo would silently charge nothing. Instead the store's own rate is
 * charged, the decision is flagged rate_missing and the gap is remembered for
 * the setup check: too much VAT can be refunded, VAT never charged cannot be
 * collected.
 *
 * @phpstan-type Address array{country: string, state: string, postcode: string, city: string}
 */
final class TaxLocation {

	const MISSING_FLAG = 'rate_missing';

	const MISSING_OPTION = 'vatright_rate_missing';

	const UNTAXED_SCHEMES = array(
		Scheme::EXPORT,
		Scheme::OUTSIDE_TERRITORY,
		Scheme::REVERSE_CHARGE_SERVICES,
		Scheme::INTRA_EU_GOODS,
		Scheme::ROLE_EXEMPT,
	);

	public static function charges_line( LineDecision $line ): bool {
		return ! in_array( $line->scheme(), self::UNTAXED_SCHEMES, true );
	}

	public static function charges( Decision $decision ): bool {
		if ( $decision->is_vat_exempt() ) {
			return false;
		}
		foreach ( $decision->lines() as $line ) {
			if ( self::charges_line( $line ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Shipping follows the goods it carries; with no goods it follows the cart.
	 */
	public static function charges_shipping( Decision $decision ): bool {
		return ! $decision->is_vat_exempt() && null !== self::shipping_line( $decision );
	}

	/**
	 * The line whose treatment shipping takes: the first charged goods line, or
	 * the first charged line of a cart without goods.
	 */
	public static function shipping_line( Decision $decision ): ?LineDecision {
		$goods = array_filter(
			$decision->lines(),
			static function ( LineDecision $line ): bool {
				return Line::GOODS === $line->supply_type();
			}
		);
		foreach ( array() === $goods ? $decision->lines() : $goods as $line ) {
			if ( self::charges_line( $line ) ) {
				return $line;
			}
		}
		return null;
	}

	/**
	 * Shipping rates, taken like the rates of the line shipping follows, so a
	 * mixed cart never ships untaxed because the customer's country has no row.
	 * $rates are the shipping rates Woo found at $location.
	 *
	 * @param array<int|string,mixed> $rates
	 * @param Address                 $location
	 * @param Address                 $customer
	 * @return array{0: array<int|string,mixed>, 1: bool} The rates, and whether a rate was missing.
	 */
	public static function shipping_rates( Decision $decision, string $tax_class, array $location, array $customer, array $rates ): array {
		$line = self::shipping_line( $decision );
		if ( $decision->is_vat_exempt() || null === $line ) {
			return array( array(), false );
		}
		list( $found, $missing ) = self::line_rates( $line, $tax_class, $location, $customer, $rates );
		$shipping                = array_filter(
			$found,
			static function ( $rate ): bool {
				return is_array( $rate ) && 'yes' === ( $rate['shipping'] ?? '' );
			}
		);
		return array( $shipping, $missing );
	}

	/**
	 * Where Woo should tax the whole cart or order. Mixed decisions keep the
	 * customer's address; their lines are steered one by one.
	 *
	 * @param Address $address
	 * @return array{0: Address, 1: bool} The address, and whether a rate was missing.
	 */
	public static function address( Decision $decision, array $address ): array {
		$country = $decision->tax_country();
		if ( $decision->is_vat_exempt() || null === $country ) {
			return array( $address, false );
		}
		if ( WC()->countries->get_base_country() === $country ) {
			return array( self::base(), false );
		}
		if ( strtoupper( $address['country'] ) !== $country ) {
			$address = self::country_only( $country );
		}
		if ( ! self::charges( $decision ) || array() !== self::find( $address, '' ) ) {
			return array( $address, false );
		}
		self::note_missing( $country, '' );
		return array( self::base(), true );
	}

	/**
	 * Rates for one line. $rates are the ones Woo found at $location (the
	 * address Woo is taxing at), $customer is the customer's own address.
	 *
	 * @param array<int|string,mixed> $rates
	 * @param Address                 $location
	 * @param Address                 $customer
	 * @return array{0: array<int|string,mixed>, 1: bool} The rates, and whether a rate was missing.
	 */
	public static function line_rates( LineDecision $line, string $tax_class, array $location, array $customer, array $rates ): array {
		if ( ! self::charges_line( $line ) ) {
			return array( array(), false );
		}

		$country = $line->tax_country();
		if ( strtoupper( $location['country'] ) === $country ) {
			$found = $rates;
		} elseif ( WC()->countries->get_base_country() === $country ) {
			$found = WC_Tax::get_base_tax_rates( $tax_class );
		} else {
			$found = self::find( strtoupper( $customer['country'] ) === $country ? $customer : self::country_only( $country ), $tax_class );
		}
		if ( array() !== $found ) {
			return array( $found, false );
		}

		$base = WC_Tax::get_base_tax_rates( $tax_class );
		if ( array() === $base ) {
			return array( array(), false );
		}
		self::note_missing( $country, $tax_class );
		return array( $base, true );
	}

	/**
	 * @return Address
	 */
	public static function base(): array {
		return array(
			'country'  => WC()->countries->get_base_country(),
			'state'    => WC()->countries->get_base_state(),
			'postcode' => WC()->countries->get_base_postcode(),
			'city'     => WC()->countries->get_base_city(),
		);
	}

	/**
	 * @return Address
	 */
	private static function country_only( string $country ): array {
		return array(
			'country'  => $country,
			'state'    => '',
			'postcode' => '',
			'city'     => '',
		);
	}

	/**
	 * @param Address $address
	 * @return array<int|string,mixed>
	 */
	private static function find( array $address, string $tax_class ): array {
		return (array) WC_Tax::find_rates( array_merge( $address, array( 'tax_class' => $tax_class ) ) );
	}

	private static function note_missing( string $country, string $tax_class ): void {
		$seen = get_option( self::MISSING_OPTION, array() );
		$seen = is_array( $seen ) ? $seen : array();
		$key  = $country . '|' . $tax_class;
		if ( ! isset( $seen[ $key ] ) ) {
			$seen[ $key ] = gmdate( 'Y-m-d\TH:i:s\Z' );
			update_option( self::MISSING_OPTION, $seen, false );
		}
	}
}
