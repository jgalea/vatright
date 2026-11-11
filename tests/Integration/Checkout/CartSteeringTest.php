<?php

namespace Vatright\Tests\Integration\Checkout;

use Vatright\Admin\SetupCheck;
use Vatright\Checkout\TaxLocation;
use Vatright\Domain\LineDecision;
use Vatright\Domain\Rule;

require_once __DIR__ . '/CheckoutTestCase.php';

class CartSteeringTest extends CheckoutTestCase {

	public function test_country_change_revokes_exemption() {
		$this->steering()->arm_checkout();
		$this->add_to_cart( $this->product( 'digital' ) );
		$this->customer( 'DE', '10115', self::VALID_DE );

		$this->assertTrue( $this->calculate()->is_vat_exempt() );
		$this->assertTrue( WC()->customer->get_is_vat_exempt() );
		$this->assertSame( 0.0, (float) WC()->cart->get_total_tax() );

		$this->customer( 'FR', '75001', self::VALID_DE );
		$decision = $this->calculate();

		$this->assertFalse( $decision->is_vat_exempt() );
		$this->assertSame( Rule::PREFIX_MISMATCH_CHARGED, $decision->rule() );
		$this->assertFalse( WC()->customer->get_is_vat_exempt() );
		$this->assertSame( 18.0, (float) WC()->cart->get_total_tax() );

		$this->customer( 'MT', '1000', self::VALID_DE );
		$this->assertFalse( $this->calculate()->is_vat_exempt() );
		$this->assertFalse( WC()->customer->get_is_vat_exempt() );
	}

	public function test_local_pickup_revokes_goods_exemption() {
		$this->steering()->arm_checkout();
		$this->add_to_cart( $this->product( 'goods' ) );
		$this->customer( 'DE', '10115', self::VALID_DE );
		WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:' . $this->flat_instance ) );

		$this->assertSame( Rule::RC_GOODS_ART138, $this->calculate()->rule() );
		$this->assertSame( 0.0, (float) WC()->cart->get_total_tax() );

		WC()->session->set( 'chosen_shipping_methods', array( 'local_pickup:' . $this->pickup_instance ) );
		$decision = $this->calculate();

		$this->assertSame( Rule::LOCAL_PICKUP_DOMESTIC, $decision->rule() );
		$this->assertFalse( WC()->customer->get_is_vat_exempt() );
		$this->assertSame( array( $this->rates['MT'] => 18.0 ), $this->item_taxes() );
	}

	public function test_mixed_cart_item_rates() {
		$this->settings( array( 'oss_registered' => true ) );
		add_filter(
			'woocommerce_geolocate_ip',
			static function () {
				return 'FR';
			}
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		$book                   = $this->product( 'goods' );
		$ebook                  = $this->product( 'digital' );
		$this->add_to_cart( $book, $ebook );
		$this->customer( 'FR', '75001' );
		WC()->customer->set_shipping_country( 'DE' );
		WC()->customer->set_shipping_postcode( '10115' );

		$decision = $this->calculate();
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertNull( $decision->tax_country() );
		foreach ( WC()->cart->get_cart() as $item ) {
			$expected = $item['product_id'] === $book->get_id() ? array( $this->rates['DE'] => 19.0 ) : array( $this->rates['FR'] => 20.0 );
			$this->assertEquals( $expected, array_map( 'floatval', $item['line_tax_data']['total'] ) );
		}
	}

	public function test_inclusive_prices_exempt_customer_pays_net() {
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		$this->steering()->arm_checkout();
		$this->add_to_cart( $this->product( 'digital', '118' ) );
		$this->customer( 'DE', '10115', self::VALID_DE );

		$this->assertTrue( $this->calculate()->is_vat_exempt() );
		$this->assertEqualsWithDelta( 100.0, (float) WC()->cart->get_total( 'edit' ), 0.001 );
		$this->assertSame( 0.0, (float) WC()->cart->get_total_tax() );
	}

	public function test_fixed_prices_keep_gross_constant() {
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		$this->settings(
			array(
				'oss_registered' => true,
				'fixed_prices'   => true,
			)
		);
		$this->add_to_cart( $this->product( 'digital', '118' ) );
		$this->customer( 'DE', '10115' );

		$this->assertSame( Rule::B2C_DESTINATION, $this->calculate()->rule() );
		$this->assertEqualsWithDelta( 118.0, (float) WC()->cart->get_total( 'edit' ), 0.001 );
		$this->assertEqualsWithDelta( 118.0 - 118.0 / 1.19, (float) WC()->cart->get_total_tax(), 0.01 );

		$this->settings( array( 'oss_registered' => true ) );
		$this->calculate();
		$this->assertEqualsWithDelta( 119.0, (float) WC()->cart->get_total( 'edit' ), 0.001, 'Control: without fixed prices the German customer pays German VAT on the net price' );
	}

	/**
	 * KG-1: above the threshold, goods to Northern Ireland take GB as tax
	 * country. With no GB rate Woo would charge nothing; the store's own rate
	 * is charged instead, flagged, and named by the setup check until a GB
	 * rate exists.
	 */
	public function test_northern_ireland_goods_without_gb_rate_charge_origin_and_flag() {
		$this->settings( array( 'oss_registered' => true ) );
		$this->add_to_cart( $this->product( 'goods' ) );
		$this->customer( 'GB', 'BT1 1AA' );
		WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:' . $this->flat_instance ) );

		$decision = $this->calculate();

		$this->assertSame( 'GB', $decision->tax_country() );
		$this->assertContains( TaxLocation::MISSING_FLAG, $decision->flags() );
		$this->assertSame( array( $this->rates['MT'] => 18.0 ), $this->item_taxes() );
		$this->assertEqualsWithDelta( 1.8, (float) WC()->cart->get_shipping_tax(), 0.001 );
		$this->assertContains( 'destination_rates_missing', array_column( ( new SetupCheck() )->issues( false ), 'id' ) );

		$gb       = $this->add_rate( 'GB', '20', '', 'BT*' );
		$decision = $this->calculate();

		$this->assertNotContains( TaxLocation::MISSING_FLAG, $decision->flags() );
		$this->assertSame( array( $gb => 20.0 ), $this->item_taxes() );
		$this->assertNotContains( 'destination_rates_missing', array_column( ( new SetupCheck() )->issues( false ), 'id' ) );
	}

	/**
	 * KG-8: a mixed cart (goods under OSS to Italy, a service at the store's
	 * rate) has no single tax country. Italy has no rate row, so shipping must
	 * fall back with the goods instead of going untaxed.
	 */
	public function test_mixed_cart_shipping_without_destination_rate_falls_back_and_flags() {
		$this->settings( array( 'oss_registered' => true ) );
		$this->add_to_cart( $this->product( 'goods' ), $this->product( 'service' ) );
		$this->customer( 'IT', '00100' );
		WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:' . $this->flat_instance ) );

		$decision = $this->calculate();

		$this->assertNull( $decision->tax_country() );
		$this->assertContains( TaxLocation::MISSING_FLAG, $decision->flags() );
		$this->assertSame( array( $this->rates['MT'] => 36.0 ), $this->item_taxes() );
		$this->assertEqualsWithDelta( 1.8, (float) WC()->cart->get_shipping_tax(), 0.001 );

		$it = $this->add_rate( 'IT', '22' );
		$this->steering()->reset();
		$this->calculate();
		$this->assertEqualsWithDelta( 2.2, (float) WC()->cart->get_shipping_tax(), 0.001, 'Control: with an Italian row, shipping follows the goods at 22%' );
		$this->assertArrayHasKey( $it, $this->item_taxes() );
	}

	public function test_export_and_outside_territory_lines_untaxed_even_when_a_rate_matches() {
		$this->add_rate( 'US', '7' );
		$this->add_to_cart( $this->product( 'goods' ) );
		WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:' . $this->flat_instance ) );

		$this->customer( 'ES', '35001' );
		$this->assertSame( Rule::OUTSIDE_TERRITORY, $this->calculate()->rule() );
		$this->assertSame( 0.0, (float) WC()->cart->get_total_tax(), 'Canary Islands: the ES row must not apply' );

		$this->customer( 'US', '10001' );
		$this->assertSame( Rule::EXPORT_NON_EU, $this->calculate()->rule() );
		$this->assertSame( 0.0, (float) WC()->cart->get_total_tax(), 'US: the merchant\'s US row must not apply' );
	}

	public function test_decision_kept_in_session_for_the_next_request() {
		$this->add_to_cart( $this->product( 'digital' ) );
		$this->customer( 'DE', '10115' );
		$id = $this->calculate()->id();

		$this->steering()->reset();

		$this->assertSame( $id, $this->steering()->current()->id() );
	}

	/**
	 * The property behind principle 1: across countries with and without
	 * rate rows, supply types, VAT numbers and threshold states, the cart
	 * carries VAT whenever the decision charges any line. Zero VAT is only
	 * allowed when the rules exempted the customer or every line is outside
	 * EU VAT or reverse charged. Shipping on goods carries VAT whenever the
	 * decision charges shipping, mixed carts (no single tax country) included.
	 */
	public function test_never_zero_vat_unless_rules_exempt_or_zero_rate() {
		$this->vies_answer( 'IT00743110157', '{"countryCode":"IT","vatNumber":"00743110157","valid":true}' );
		$customers = array(
			array( 'MT', '1000' ),
			array( 'DE', '10115' ),
			array( 'IT', '00100' ),
			array( 'GB', 'BT1 1AA' ),
			array( 'GB', 'SW1A 1AA' ),
			array( 'US', '10001' ),
			array( 'ES', '35001' ),
			array( 'MC', '98000' ),
		);
		$numbers   = array( '', self::VALID_DE, 'IT00743110157', 'XI980780684', self::DOWN_DE );
		$supplies  = array( array( 'goods' ), array( 'digital' ), array( 'service' ), array( 'goods', 'service' ), array( 'goods', 'digital' ) );
		$checked   = 0;
		$zero      = 0;
		$mixed     = 0;

		foreach ( array( false, true ) as $oss ) {
			$this->settings( array( 'oss_registered' => $oss ) );
			foreach ( $supplies as $supply ) {
				WC()->cart->empty_cart();
				foreach ( $supply as $type ) {
					$this->add_to_cart( $this->product( $type ) );
				}
				foreach ( $customers as $customer ) {
					foreach ( $numbers as $number ) {
						$this->steering()->reset();
						$this->steering()->arm_checkout();
						$this->customer( $customer[0], $customer[1], $number );
						WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:' . $this->flat_instance ) );
						$decision = $this->calculate();
						$this->assertNotNull( $decision );
						++$checked;

						$tax     = (float) WC()->cart->get_total_tax();
						$charges = array_filter(
							$decision->lines(),
							static function ( LineDecision $line ) use ( $decision ): bool {
								return TaxLocation::charges_line( $decision, $line );
							}
						);
						$label   = sprintf( '%s %s %s number=%s oss=%d rule=%s', implode( '+', $supply ), $customer[0], $customer[1], $number, $oss, $decision->rule() );

						if ( $decision->is_vat_exempt() || array() === $charges ) {
							$this->assertSame( 0.0, $tax, $label );
							++$zero;
						} else {
							$this->assertGreaterThan( 0.0, $tax, $label );
						}

						if ( in_array( 'goods', $supply, true ) && TaxLocation::charges_shipping( $decision ) ) {
							$this->assertGreaterThan( 0.0, (float) WC()->cart->get_shipping_tax(), 'shipping: ' . $label );
							$mixed += null === $decision->tax_country() ? 1 : 0;
						}
					}
				}
			}
		}

		$this->assertSame( 2 * 5 * 8 * 5, $checked );
		$this->assertGreaterThan( 0, $mixed, 'The matrix must include mixed carts that charge shipping.' );
		$this->assertGreaterThan( 0, $zero, 'The matrix must include exempt and export cases.' );
		$this->assertGreaterThan( $zero, $checked, 'The matrix must include charged cases.' );
	}
}
