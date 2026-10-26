<?php

namespace Vatright\Tests\Integration\Checkout;

use Vatright\Domain\Rule;
use Vatright\Orders\DecisionStore;
use WC_Order;

require_once __DIR__ . '/CheckoutTestCase.php';

class ClassicCheckoutTest extends CheckoutTestCase {

	public function test_classic_valid_number_exempts_order() {
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::VALID_DE );

		$reply = $this->classic_checkout( 'DE', '10115' );

		$order = $this->placed( $reply );
		$this->assertSame( 1, $this->vies_calls );
		$this->assertSame( 0.0, (float) $order->get_total_tax() );
		$this->assertSame( 'yes', $order->get_meta( 'is_vat_exempt' ) );
		$this->assertSame( Rule::RC_SERVICES_ART196, DecisionStore::get( $order )->rule() );
	}

	/**
	 * KG-14: the classic order review prices shipping before the first totals
	 * calculation of the request, so shipping must already be taxed where the
	 * goods are.
	 */
	public function test_order_review_taxes_shipping_where_the_goods_are_taxed() {
		$this->add_to_cart( $this->product( 'goods' ) );
		$this->customer( 'DE', '10115' );
		WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:' . $this->flat_instance ) );
		$this->steering()->reset();

		WC()->cart->calculate_shipping();
		WC()->cart->calculate_totals();

		$this->assertSame( array( $this->rates['MT'] => 18.0 ), $this->item_taxes() );
		$this->assertSame( array( $this->rates['MT'] => 1.8 ), array_map( 'floatval', WC()->cart->get_shipping_taxes() ) );
		$this->assertEqualsWithDelta( 129.8, (float) WC()->cart->get_total( 'edit' ), 0.001 );
	}

	public function test_reject_policy_blocks_classic_checkout() {
		$this->settings( array( 'outage_policy' => 'reject' ) );
		$this->vies_answer( self::DOWN_DE, '{"actionSucceed":false,"errorWrappers":[{"error":"MS_UNAVAILABLE"}]}' );
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::DOWN_DE );

		$reply = $this->classic_checkout( 'DE', '10115' );

		$this->assertSame( 'failure', $reply['result'] );
		$this->assertStringContainsString( 'could not check your VAT number', (string) $reply['messages'] );
		$this->assertSame( array(), wc_get_orders( array( 'limit' => -1 ) ) );
	}

	public function test_invalid_number_blocks_classic_checkout() {
		$this->vies_answer( self::INVALID_DE, '{"countryCode":"DE","vatNumber":"123475223","valid":false}' );
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::INVALID_DE );

		$reply = $this->classic_checkout( 'DE', '10115' );

		$this->assertSame( 'failure', $reply['result'] );
		$this->assertStringContainsString( 'This VAT number is not valid', (string) $reply['messages'] );
	}

	public function test_decision_recorded_on_block_and_classic_orders() {
		$this->add_to_cart( $this->product( 'digital' ) );
		$classic = $this->placed( $this->classic_checkout( 'DE', '10115' ) );

		$this->add_to_cart( $this->product( 'digital' ) );
		$block = $this->order_from( $this->block_checkout( 'DE', '10115' ) );

		foreach ( array(
			'classic' => $classic,
			'block'   => $block,
		) as $flow => $order ) {
			$order    = wc_get_order( $order->get_id() );
			$decision = DecisionStore::get( $order );
			$this->assertNotNull( $decision, "$flow: no decision" );
			$this->assertSame( 'checkout', $decision->trigger(), $flow );
			$this->assertSame( Rule::B2C_ORIGIN_BELOW_THRESHOLD, $decision->rule(), $flow );
			$this->assertSame( 18.0, (float) $order->get_total_tax(), $flow );
			$log = DecisionStore::log( $order );
			$this->assertCount( 1, $log, $flow );
			$this->assertSame( $decision->id(), $log[0]['decision_id'], $flow );
			foreach ( $order->get_items() as $item ) {
				$this->assertNotSame( '', $item->get_meta( '_vatright_line_key' ), "$flow: line not tied to its decision line" );
				$this->assertNotNull( $decision->line( (string) $item->get_meta( '_vatright_line_key' ) ), $flow );
			}
		}
	}
}
