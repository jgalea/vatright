<?php

namespace Vatright\Orders;

use Vatright\Migration\LegacyData;
use WC_Order;

/**
 * Prints the customer's VAT number as the last line of the order's billing
 * address, which order pages, emails and invoice plugins that print the
 * address all show. A format that already carries another VAT plugin's
 * placeholder is left alone, so the number never appears twice.
 */
class BillingAddress {

	const TOKEN = '{vatright_vat_number}';

	const FIELD = 'vatright_vat_number';

	public function register(): void {
		add_filter( 'woocommerce_order_formatted_billing_address', array( $this, 'add_number' ), 10, 2 );
		add_filter( 'woocommerce_formatted_address_replacements', array( $this, 'replace_token' ), 10, 2 );
		add_filter( 'woocommerce_localisation_address_formats', array( $this, 'add_token' ), PHP_INT_MAX );
	}

	/**
	 * @param mixed $address
	 * @param mixed $order
	 * @return mixed
	 */
	public function add_number( $address, $order ) {
		if ( ! is_array( $address ) || ! $order instanceof WC_Order ) {
			return $address;
		}
		$number = substr( (string) preg_replace( '/[^A-Z0-9 .+*\-]/', '', strtoupper( LegacyData::order_number( $order ) ) ), 0, 24 );
		if ( '' !== $number ) {
			$address[ self::FIELD ] = $number;
		}
		return $address;
	}

	/**
	 * @param mixed $replacements
	 * @param mixed $args
	 * @return mixed
	 */
	public function replace_token( $replacements, $args ) {
		if ( ! is_array( $replacements ) ) {
			return $replacements;
		}
		$number = is_array( $args ) ? (string) ( $args[ self::FIELD ] ?? '' ) : '';
		/* translators: %s: the customer's VAT number */
		$replacements[ self::TOKEN ] = '' === $number ? '' : sprintf( __( 'VAT number: %s', 'vatright' ), $number );
		return $replacements;
	}

	/**
	 * @param mixed $formats
	 * @return mixed
	 */
	public function add_token( $formats ) {
		if ( ! is_array( $formats ) ) {
			return $formats;
		}
		foreach ( $formats as $country => $format ) {
			$format = (string) $format;
			foreach ( array_merge( LegacyData::address_tokens(), array( self::TOKEN ) ) as $token ) {
				if ( false !== strpos( $format, $token ) ) {
					continue 2;
				}
			}
			$formats[ $country ] = $format . "\n" . self::TOKEN;
		}
		return $formats;
	}
}
