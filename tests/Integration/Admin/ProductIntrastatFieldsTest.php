<?php

namespace Vatright\Tests\Integration\Admin;

use Vatright\Admin\ProductIntrastatFields;
use Vatright\Intrastat\ProductData;
use Vatright\Tests\Support\IntegrationTestCase;
use WC_Product_Simple;
use WC_Product_Variation;
use WC_Product_Variable;

class ProductIntrastatFieldsTest extends IntegrationTestCase {

	/** @var ProductIntrastatFields */
	private $fields;

	public function set_up() {
		parent::set_up();
		$this->fields = new ProductIntrastatFields();
		update_option( 'woocommerce_weight_unit', 'kg' );
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		}
	}

	public function tear_down() {
		$_POST = array();
		parent::tear_down();
	}

	private function product( string $weight = '' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Shirt' );
		$product->set_weight( $weight );
		$product->save();
		return $product;
	}

	/**
	 * @param array<string,string> $post
	 */
	private function save( WC_Product_Simple $product, array $post ): void {
		$_POST = $post;
		$this->fields->save( $product );
	}

	public function test_fields_are_hooked_into_the_general_tab_and_the_product_save(): void {
		$this->fields->register();

		$this->assertNotFalse( has_action( 'woocommerce_product_options_general_product_data', array( $this->fields, 'render' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_admin_process_product_object', array( $this->fields, 'save' ) ) );
	}

	public function test_render_prints_the_four_inputs_hidden_for_virtual_products(): void {
		ob_start();
		$this->fields->render();
		$html = (string) ob_get_clean();

		foreach ( array( ProductData::CN8, ProductData::ORIGIN, ProductData::NET_MASS, ProductData::SUPP_UNITS ) as $id ) {
			$this->assertStringContainsString( 'name="' . $id . '"', $html );
		}
		$this->assertStringContainsString( 'hide_if_virtual', $html );
	}

	public function test_valid_values_are_saved_normalised(): void {
		$product = $this->product();

		$this->save(
			$product,
			array(
				ProductData::CN8        => ' 6109 1000 ',
				ProductData::ORIGIN     => 'cn',
				ProductData::NET_MASS   => '0,35',
				ProductData::SUPP_UNITS => '2',
			)
		);

		$this->assertSame( '61091000', $product->get_meta( ProductData::CN8 ) );
		$this->assertSame( 'CN', $product->get_meta( ProductData::ORIGIN ) );
		$this->assertSame( '0.35', $product->get_meta( ProductData::NET_MASS ) );
		$this->assertSame( '2', $product->get_meta( ProductData::SUPP_UNITS ) );
	}

	public function test_invalid_values_are_not_stored(): void {
		$product = $this->product();
		$this->save(
			$product,
			array(
				ProductData::CN8        => '6109',
				ProductData::ORIGIN     => 'ZZ',
				ProductData::NET_MASS   => '-3',
				ProductData::SUPP_UNITS => 'abc',
			)
		);

		foreach ( array( ProductData::CN8, ProductData::ORIGIN, ProductData::NET_MASS, ProductData::SUPP_UNITS ) as $key ) {
			$this->assertSame( '', $product->get_meta( $key ), $key );
		}
	}

	public function test_clearing_a_field_removes_the_value(): void {
		$product = $this->product();
		$this->save( $product, array( ProductData::CN8 => '61091000' ) );
		$this->save( $product, array( ProductData::CN8 => '' ) );

		$this->assertSame( '', $product->get_meta( ProductData::CN8 ) );
	}

	public function test_net_mass_defaults_to_the_product_weight_converted_to_kilograms(): void {
		$product = $this->product( '500' );

		update_option( 'woocommerce_weight_unit', 'g' );
		$this->assertEqualsWithDelta( 0.5, ProductData::for_product( $product )['net_mass'], 0.0001 );

		update_option( 'woocommerce_weight_unit', 'lbs' );
		$this->assertEqualsWithDelta( 226.796, ProductData::for_product( $product )['net_mass'], 0.001 );

		update_option( 'woocommerce_weight_unit', 'oz' );
		$this->assertEqualsWithDelta( 14.175, ProductData::for_product( $product )['net_mass'], 0.001 );

		update_option( 'woocommerce_weight_unit', 'kg' );
		$this->assertEqualsWithDelta( 500.0, ProductData::for_product( $product )['net_mass'], 0.0001 );
	}

	public function test_an_explicit_net_mass_is_in_kilograms_whatever_the_store_unit(): void {
		update_option( 'woocommerce_weight_unit', 'lbs' );
		$product = $this->product( '10' );
		$product->update_meta_data( ProductData::NET_MASS, '0.4' );
		$product->save();

		$this->assertSame( 0.4, ProductData::for_product( $product )['net_mass'] );
	}

	public function test_no_weight_and_no_mass_means_unknown(): void {
		$this->assertNull( ProductData::for_product( $this->product() )['net_mass'] );
	}

	public function test_variations_use_the_values_of_their_parent(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Shirt' );
		$parent->update_meta_data( ProductData::CN8, '61091000' );
		$parent->update_meta_data( ProductData::ORIGIN, 'PT' );
		$parent->update_meta_data( ProductData::NET_MASS, '0.3' );
		$parent->save();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->save();

		$data = ProductData::for_product( $variation );

		$this->assertSame( '61091000', $data['cn8'] );
		$this->assertSame( 'PT', $data['origin'] );
		$this->assertSame( 0.3, $data['net_mass'] );
	}
}
