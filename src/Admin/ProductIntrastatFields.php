<?php

namespace Vatright\Admin;

use Vatright\Domain\Cn8;
use Vatright\Intrastat\ProductData;

class ProductIntrastatFields {

	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
	}

	public function render(): void {
		woocommerce_wp_text_input(
			array(
				'id'            => ProductData::CN8,
				'label'         => __( 'INTRASTAT CN8 code', 'vatright' ),
				'description'   => __( 'The eight-digit Combined Nomenclature commodity code, for the INTRASTAT report.', 'vatright' ),
				'desc_tip'      => true,
				'wrapper_class' => 'hide_if_virtual',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => ProductData::ORIGIN,
				'label'         => __( 'Country of origin', 'vatright' ),
				'description'   => __( 'Two-letter code of the country where the goods were made, for example PT.', 'vatright' ),
				'desc_tip'      => true,
				'wrapper_class' => 'hide_if_virtual',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => ProductData::NET_MASS,
				'label'         => __( 'Net mass (kg)', 'vatright' ),
				'description'   => sprintf(
					/* translators: %s: the store's weight unit. */
					__( 'Mass of one unit without packaging, in kilograms. Left empty, the product weight is converted from %s.', 'vatright' ),
					(string) get_option( 'woocommerce_weight_unit' )
				),
				'desc_tip'      => true,
				'data_type'     => 'decimal',
				'wrapper_class' => 'hide_if_virtual',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => ProductData::SUPP_UNITS,
				'label'         => __( 'Supplementary units', 'vatright' ),
				'description'   => __( 'Only for commodity codes that ask for a supplementary unit, such as pairs: how many one unit of this product holds.', 'vatright' ),
				'desc_tip'      => true,
				'data_type'     => 'decimal',
				'wrapper_class' => 'hide_if_virtual',
			)
		);
	}

	/**
	 * A value that does not fit its field is dropped, and the merchant is
	 * told. A field the form did not send keeps its value.
	 *
	 * @param \WC_Product $product
	 */
	public function save( $product ): void {
		$cn8 = $this->posted( ProductData::CN8 );
		if ( null !== $cn8 ) {
			$this->store( $product, ProductData::CN8, '' === $cn8 ? '' : Cn8::normalise( $cn8 ), __( 'The CN8 code must be eight digits, so it was not saved.', 'vatright' ) );
		}

		$origin = $this->posted( ProductData::ORIGIN );
		if ( null !== $origin ) {
			$origin = strtoupper( $origin );
			$known  = '' === $origin || isset( WC()->countries->get_countries()[ $origin ] );
			$this->store( $product, ProductData::ORIGIN, $known ? $origin : null, __( 'The country of origin is not a known country code, so it was not saved.', 'vatright' ) );
		}

		foreach ( array( ProductData::NET_MASS, ProductData::SUPP_UNITS ) as $key ) {
			$raw = $this->posted( $key );
			if ( null === $raw ) {
				continue;
			}
			$raw    = str_replace( ',', '.', $raw );
			$usable = '' === $raw || ( is_numeric( $raw ) && (float) $raw > 0 );
			$this->store( $product, $key, $usable ? ( '' === $raw ? '' : wc_format_decimal( $raw ) ) : null, __( 'Net mass and supplementary units must be numbers above zero, so they were not saved.', 'vatright' ) );
		}
	}

	private function posted( string $key ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product nonce.
		return isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : null;
	}

	/**
	 * @param \WC_Product $product
	 * @param string|null $value   Null when the posted value was not acceptable.
	 */
	private function store( $product, string $key, ?string $value, string $error ): void {
		if ( null === $value && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			\WC_Admin_Meta_Boxes::add_error( $error );
		}
		if ( null === $value || '' === $value ) {
			$product->delete_meta_data( $key );
			return;
		}
		$product->update_meta_data( $key, $value );
	}
}
