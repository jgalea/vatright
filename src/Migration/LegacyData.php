<?php

namespace Vatright\Migration;

use Vatright\Checkout\InputGatherer;
use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use Vatright\Orders\DecisionStore;
use Vatright\Privacy\Retention;
use WC_Customer;
use WC_Order;

/**
 * VAT numbers other VAT plugins left on orders and customers, read with the
 * meta keys each plugin uses. A store that switched to VAT Right keeps these
 * until it imports them, so every reader falls back to them when VAT Right
 * has no number of its own.
 */
final class LegacyData {

	/**
	 * Written by the importer on each order it filled: the source plugin and
	 * the validation status it had recorded.
	 */
	const IMPORTED_META = '_vatright_imported';

	/**
	 * In order of precedence. `active` is a class or constant the plugin
	 * defines while it runs; `address_token` is the placeholder it adds to the
	 * address formats.
	 */
	const SOURCES = array(
		'eu-vat-number' => array(
			'name'          => 'WooCommerce EU VAT Number',
			'active'        => array( 'WC_EU_VAT_Number', 'WC_EU_VAT_VERSION' ),
			'order_number'  => array( '_billing_vat_number', '_vat_number' ),
			'user_number'   => array( 'vat_number', 'billing_vat_number' ),
			'address_token' => '{vat_id}',
		),
		'wpfactory'     => array(
			'name'          => 'EU/UK VAT Validation Manager for WooCommerce',
			'active'        => array( 'WPFACTORY_WC_EU_VAT_VERSION' ),
			'order_number'  => array( '_billing_eu_vat_number' ),
			'user_number'   => array( 'billing_eu_vat_number', '_wc_other/alg_eu_vat/billing_eu_vat_number' ),
			'address_token' => '{billing_eu_vat_number}',
		),
		'aelia'         => array(
			'name'          => 'Aelia EU VAT Assistant',
			'active'        => array( 'WC_Aelia_EU_VAT_Assistant' ),
			'order_number'  => array( 'vat_number' ),
			'user_number'   => array( 'vat_number' ),
			'address_token' => '{vat_number}',
		),
		'simba'         => array(
			'name'          => 'EU/UK VAT Compliance for WooCommerce',
			'active'        => array( 'WC_EU_VAT_Compliance' ),
			'order_number'  => array( 'VAT Number' ),
			'user_number'   => array(),
			'address_token' => '',
		),
	);

	/**
	 * Order keys invoice plugins read, and the plugin that writes them.
	 */
	const COMPAT_KEYS = array(
		'_billing_vat_number'  => 'eu-vat-number',
		'_vat_number_is_valid' => 'eu-vat-number',
	);

	/**
	 * The first source with a number on this order, or only $only's.
	 *
	 * @return array{source:string,number:string,status:string}|null
	 */
	public static function order( WC_Order $order, ?string $only = null ): ?array {
		foreach ( self::SOURCES as $source => $definition ) {
			if ( null !== $only && $only !== $source ) {
				continue;
			}
			foreach ( $definition['order_number'] as $key ) {
				$number = strtoupper( trim( (string) $order->get_meta( $key ) ) );
				if ( '' !== $number ) {
					return array(
						'source' => $source,
						'number' => $number,
						'status' => self::verdict( $source, array( $order, 'get_meta' ) ),
					);
				}
			}
		}
		return null;
	}

	/**
	 * The validation status the source plugin recorded, as a VAT Right
	 * status: valid, invalid, or unchecked when it recorded no verdict.
	 *
	 * @param callable(string):mixed $meta Reads one of the order's meta values.
	 */
	public static function verdict( string $source, callable $meta ): string {
		switch ( $source ) {
			case 'eu-vat-number':
				if ( 'true' === $meta( '_vat_number_is_valid' ) ) {
					return ValidationResult::VALID;
				}
				return 'true' === $meta( '_vat_number_is_validated' ) ? ValidationResult::INVALID : ValidationResult::UNCHECKED;
			case 'wpfactory':
				// Its details are only saved for a number the registry confirmed.
				return is_array( $meta( 'billing_eu_vat_number_details' ) ) ? ValidationResult::VALID : ValidationResult::UNCHECKED;
			case 'aelia':
				$validated = (string) $meta( '_vat_number_validated' );
				if ( 'valid' === $validated ) {
					return ValidationResult::VALID;
				}
				return 'not-valid' === $validated ? ValidationResult::INVALID : ValidationResult::UNCHECKED;
			case 'simba':
				$valid = (string) $meta( 'Valid VAT Number' );
				if ( 'false' === $valid ) {
					return ValidationResult::INVALID;
				}
				return '' !== $valid && '' !== (string) $meta( 'VAT number validated' ) ? ValidationResult::VALID : ValidationResult::UNCHECKED;
		}
		return ValidationResult::UNCHECKED;
	}

	/**
	 * VAT Right's own number, or the one another plugin left on an order VAT
	 * Right holds no record of. A number VAT Right saved or cleared, or a
	 * decision it made, is its own record; a decision the backfill inferred
	 * without a number is not. An order whose personal data was removed keeps
	 * nothing, so the removed number never comes back from older keys.
	 */
	public static function order_number( WC_Order $order ): string {
		$own = (string) $order->get_meta( InputGatherer::NUMBER_META );
		if ( '' !== $own || $order->meta_exists( InputGatherer::NUMBER_META ) || $order->meta_exists( Retention::SCRUBBED_META ) ) {
			return $own;
		}
		$record = json_decode( (string) $order->get_meta( DecisionStore::META ), true );
		if ( is_array( $record ) && ! empty( $record['vat_number'] ) ) {
			return (string) ( '' !== (string) ( $record['vat_number']['canonical'] ?? '' ) ? $record['vat_number']['canonical'] : ( $record['vat_number']['raw'] ?? '' ) );
		}
		if ( is_array( $record ) && ! in_array( 'backfilled', (array) ( $record['flags'] ?? array() ), true ) ) {
			return '';
		}
		$legacy = self::order( $order );
		return null === $legacy ? '' : $legacy['number'];
	}

	/**
	 * The vat_number section of a decision record for a number another
	 * plugin saved, which may lack its country prefix.
	 *
	 * @return array<string,string>|null
	 */
	public static function number_section( string $raw, string $billing_country ): ?array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		$parsed = VatNumber::parse( $raw, $billing_country );
		return array(
			'raw'       => $raw,
			'canonical' => null === $parsed ? '' : $parsed->canonical(),
			'prefix'    => null === $parsed ? '' : $parsed->prefix(),
			'country'   => null === $parsed ? '' : $parsed->country(),
		);
	}

	/**
	 * The validation section of a decision record for the verdict another
	 * plugin recorded. No registry is named: VAT Right asked none.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function validation_section( string $status, string $source ): ?array {
		if ( '' === $status ) {
			return null;
		}
		return ( new ValidationResult( $status, '', '', null, null, null, false, 'imported:' . $source ) )->to_array();
	}

	/**
	 * @return array{source:string,number:string}|null
	 */
	public static function customer( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}
		foreach ( self::SOURCES as $source => $definition ) {
			foreach ( $definition['user_number'] as $key ) {
				$number = strtoupper( trim( (string) get_user_meta( $user_id, $key, true ) ) );
				if ( '' !== $number ) {
					return array(
						'source' => $source,
						'number' => $number,
					);
				}
			}
		}
		return null;
	}

	/**
	 * A number the customer saved or cleared with VAT Right wins over any
	 * older one.
	 */
	public static function customer_number( WC_Customer $customer ): string {
		$own = (string) $customer->get_meta( InputGatherer::NUMBER_META );
		if ( '' !== $own || $customer->meta_exists( InputGatherer::NUMBER_META ) ) {
			return $own;
		}
		$legacy = self::customer( $customer->get_id() );
		return null === $legacy ? '' : $legacy['number'];
	}

	/**
	 * Whether the source plugin is running. The vatright_other_plugin_active
	 * filter covers a renamed or forked copy the class check misses.
	 */
	public static function is_active( string $source ): bool {
		$active = false;
		foreach ( self::SOURCES[ $source ]['active'] ?? array() as $symbol ) {
			$active = $active || class_exists( $symbol, false ) || defined( $symbol ); // @phpstan-ignore function.impossibleType (the other plugins aren't analysed)
		}
		return (bool) apply_filters( 'vatright_other_plugin_active', $active, $source );
	}

	/**
	 * @return string[] Address placeholders the formats already carry from another plugin.
	 */
	public static function address_tokens(): array {
		return array_filter( array_column( self::SOURCES, 'address_token' ) );
	}

	/**
	 * The store's own VAT numbers as the source plugin saved them.
	 *
	 * @param callable(string):mixed $option Reads one site option.
	 * @return array{eu:string,gb:string}
	 */
	public static function store_numbers( string $source, callable $option ): array {
		$found = array(
			'eu' => '',
			'gb' => '',
		);
		switch ( $source ) {
			case 'simba':
				$stored = $option( 'woocommerce_eu_vat_store_id' );
				if ( is_array( $stored ) ) {
					$found['eu'] = (string) ( $stored['eu'] ?? '' );
					$found['gb'] = (string) ( $stored['uk'] ?? '' );
				} elseif ( '' !== trim( (string) $stored ) ) {
					$found[ self::region( (string) $stored ) ] = (string) $stored;
				}
				break;
			case 'aelia':
				$settings = $option( 'wc_aelia_eu_vat_assistant' );
				if ( is_array( $settings ) ) {
					$number = self::with_prefix( (string) ( $settings['vies_requester_vat_country'] ?? '' ), (string) ( $settings['vies_requester_vat_number'] ?? '' ) );
					if ( '' !== $number ) {
						$found[ self::region( $number ) ] = $number;
					}
				}
				break;
			case 'wpfactory':
				$number = self::with_prefix( (string) $option( 'alg_wc_eu_vat_requester_country_code' ), (string) $option( 'alg_wc_eu_vat_requester_vat_number' ) );
				if ( '' !== $number ) {
					$found[ self::region( $number ) ] = $number;
				}
				break;
		}
		return array(
			'eu' => trim( $found['eu'] ),
			'gb' => trim( $found['gb'] ),
		);
	}

	private static function with_prefix( string $country, string $number ): string {
		$number  = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $number ) );
		$country = strtoupper( trim( $country ) );
		if ( '' === $number ) {
			return '';
		}
		if ( 1 === preg_match( '/^[A-Z]{2}/', $number ) || '' === $country ) {
			return $number;
		}
		return ( 'GR' === $country ? 'EL' : $country ) . $number;
	}

	private static function region( string $number ): string {
		return 0 === stripos( ltrim( $number ), 'GB' ) ? 'gb' : 'eu';
	}
}
