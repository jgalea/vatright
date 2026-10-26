<?php

namespace Vatright\Intrastat;

use WC_Product;

final class ProductData {

	const CN8        = '_vatright_cn8';
	const ORIGIN     = '_vatright_origin_country';
	const NET_MASS   = '_vatright_net_mass';
	const SUPP_UNITS = '_vatright_supp_units';

	/**
	 * What INTRASTAT needs about one unit of a product. The product screen
	 * sets these once per product, so variations use their parent's values.
	 * Net mass is in kilograms and falls back to the product weight.
	 *
	 * @return array{cn8:string,origin:string,net_mass:?float,supp_units:?float}
	 */
	public static function for_product( WC_Product $product ): array {
		$mass = self::number( self::meta( $product, self::NET_MASS ) );
		if ( null === $mass ) {
			$weight = self::number( (string) $product->get_weight() );
			$mass   = null === $weight ? null : (float) wc_get_weight( $weight, 'kg' );
		}
		return array(
			'cn8'        => self::meta( $product, self::CN8 ),
			'origin'     => self::meta( $product, self::ORIGIN ),
			'net_mass'   => $mass,
			'supp_units' => self::number( self::meta( $product, self::SUPP_UNITS ) ),
		);
	}

	private static function meta( WC_Product $product, string $key ): string {
		$value = (string) $product->get_meta( $key );
		if ( '' === $value && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$value  = $parent ? (string) $parent->get_meta( $key ) : '';
		}
		return $value;
	}

	private static function number( string $raw ): ?float {
		return is_numeric( $raw ) && (float) $raw > 0 ? (float) $raw : null;
	}
}
