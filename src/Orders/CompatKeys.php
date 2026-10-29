<?php

namespace Vatright\Orders;

use Vatright\Domain\Decision;
use Vatright\Domain\ValidationResult;
use Vatright\Migration\LegacyData;
use Vatright\Settings;
use WC_Order;

/**
 * Copies the decided VAT number into the order keys invoice plugins read
 * (they were set by WooCommerce EU VAT Number). A key is written only while
 * the plugin that owns it is inactive, and only when it is empty or VAT Right
 * wrote it, so another plugin's value is never replaced.
 */
final class CompatKeys {

	/**
	 * The compatibility keys VAT Right wrote on this order.
	 */
	const OWNED_META = '_vatright_compat_keys';

	/**
	 * `_vat_number_is_valid` is 'true' only when the number validated and
	 * the order was zero-rated for it, because invoice plugins print the
	 * zero-rated note on that value alone.
	 */
	public static function write( WC_Order $order, Decision $decision ): void {
		if ( ! Settings::get()->write_compat_keys() ) {
			return;
		}
		$record = $decision->to_array();
		$number = (string) ( $record['vat_number']['canonical'] ?? '' );
		$valid  = ValidationResult::VALID === ( $record['validation']['status'] ?? '' ) && $decision->is_vat_exempt();
		$values = array(
			'_billing_vat_number'  => $number,
			'_vat_number_is_valid' => '' === $number ? '' : ( $valid ? 'true' : 'false' ),
		);

		$owned = self::owned( $order );
		foreach ( $values as $key => $value ) {
			if ( LegacyData::is_active( LegacyData::COMPAT_KEYS[ $key ] ) ) {
				continue;
			}
			$mine = in_array( $key, $owned, true );
			if ( ! $mine && '' !== (string) $order->get_meta( $key ) ) {
				continue;
			}
			if ( '' === $value ) {
				if ( $mine ) {
					$order->delete_meta_data( $key );
					$owned = array_values( array_diff( $owned, array( $key ) ) );
				}
				continue;
			}
			$order->update_meta_data( $key, $value );
			$owned[] = $key;
		}

		$owned = array_values( array_unique( $owned ) );
		if ( array() === $owned ) {
			$order->delete_meta_data( self::OWNED_META );
		} else {
			$order->update_meta_data( self::OWNED_META, implode( ',', $owned ) );
		}
	}

	/**
	 * Removes the copies VAT Right made; the caller saves the order.
	 */
	public static function remove( WC_Order $order ): void {
		foreach ( self::owned( $order ) as $key ) {
			$order->delete_meta_data( $key );
		}
		$order->delete_meta_data( self::OWNED_META );
	}

	/**
	 * @return string[]
	 */
	private static function owned( WC_Order $order ): array {
		return array_values( array_intersect( array_keys( LegacyData::COMPAT_KEYS ), explode( ',', (string) $order->get_meta( self::OWNED_META ) ) ) );
	}
}
