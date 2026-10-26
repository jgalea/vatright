<?php

namespace Vatright\Tests\Integration\Checkout;

use Vatright\Checkout\InputGatherer;
use Vatright\Domain\Rule;
use Vatright\Ledger\Fx;
use Vatright\Ledger\ThresholdTracker;
use Vatright\Orders\DecisionStore;
use Vatright\Registry\Brreg;
use Vatright\Registry\Hmrc;
use Vatright\Registry\Router;
use Vatright\Registry\SwissUid;
use Vatright\Registry\ValidationCache;
use Vatright\Registry\Validator;
use Vatright\Registry\Vies;
use Vatright\Registry\WpHttpTransport;
use Vatright\Settings;
use WC_Customer;
use WC_Order;

require_once __DIR__ . '/CheckoutTestCase.php';

class RoleExemptionTest extends CheckoutTestCase {

	/**
	 * @param string[] $roles
	 */
	private function sign_in( array $roles ): int {
		add_role( 'wholesale_customer', 'Wholesale customer' );
		$id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user = get_userdata( $id );
		$user->set_role( '' );
		foreach ( $roles as $role ) {
			$user->add_role( $role );
		}
		wp_set_current_user( $id );
		WC()->customer = new WC_Customer( $id, true );
		WC()->cart->empty_cart();
		$this->customer( 'FR', '75001' );
		return $id;
	}

	public function test_a_customer_with_an_exempt_role_pays_no_vat_and_the_rule_is_recorded() {
		$this->settings( array( 'exempt_roles' => array( 'wholesale_customer' ) ) );
		$this->sign_in( array( 'wholesale_customer' ) );
		$this->add_to_cart( $this->product( 'goods' ) );

		$decision = $this->calculate();

		$this->assertSame( Rule::ROLE_EXEMPT, $decision->rule() );
		$this->assertTrue( $decision->is_vat_exempt() );
		$this->assertSame( 0.0, (float) WC()->cart->get_total_tax() );
		$this->assertSame( array( 'wholesale_customer' ), $decision->to_array()['customer']['exempt_roles'] );
	}

	public function test_a_role_that_is_not_listed_is_charged() {
		$this->settings( array( 'exempt_roles' => array( 'wholesale_customer' ) ) );
		$this->sign_in( array( 'customer' ) );
		$this->add_to_cart( $this->product( 'goods' ) );

		$decision = $this->calculate();

		$this->assertFalse( $decision->is_vat_exempt() );
		$this->assertSame( 19.8, round( (float) WC()->cart->get_total_tax(), 2 ), '18% on the goods and the shipping' );
	}

	public function test_default_settings_exempt_nobody() {
		$this->sign_in( array( 'wholesale_customer' ) );
		$this->add_to_cart( $this->product( 'goods' ) );

		$this->assertFalse( $this->calculate()->is_vat_exempt() );
	}

	public function test_a_guest_is_never_role_exempt() {
		$this->settings( array( 'exempt_roles' => array( 'wholesale_customer', 'customer', 'subscriber' ) ) );
		$this->customer( 'FR', '75001' );
		$this->add_to_cart( $this->product( 'goods' ) );

		$this->assertFalse( $this->calculate()->is_vat_exempt() );
	}

	public function test_the_role_exemption_survives_to_the_placed_order() {
		$this->settings( array( 'exempt_roles' => array( 'wholesale_customer' ) ) );
		$this->sign_in( array( 'wholesale_customer' ) );
		$this->add_to_cart( $this->product( 'digital' ) );

		$response = $this->block_checkout( 'FR', '75001' );
		$order    = $this->order_from( $response );
		$decision = $this->stored_decision( $order );

		$this->assertSame( Rule::ROLE_EXEMPT, $decision->rule() );
		$this->assertSame( 'yes', wc_get_order( $order->get_id() )->get_meta( 'is_vat_exempt' ) );
		$this->assertSame( 0.0, (float) wc_get_order( $order->get_id() )->get_total_tax() );
	}

	public function test_an_admin_recalculation_decides_with_the_customers_roles() {
		$this->settings( array( 'exempt_roles' => array( 'wholesale_customer' ) ) );
		$id    = $this->sign_in( array( 'wholesale_customer' ) );
		$order = new WC_Order();
		$order->set_customer_id( $id );
		$order->set_billing_country( 'FR' );
		$order->set_billing_postcode( '75001' );
		$order->save();

		$transport = new WpHttpTransport();
		$settings  = Settings::get();
		$validator = new Validator(
			new Router( new Vies( $transport ), new Hmrc( $transport, '', '' ), new SwissUid( $transport ), new Brreg( $transport ) ),
			new ValidationCache( $settings ),
			$settings
		);
		$gatherer  = new InputGatherer( $validator, new ThresholdTracker(), new Fx( $transport ) );
		$this->assertSame( array( 'wholesale_customer' ), $gatherer->from_order( $order, 'admin', false )->exempt_roles() );

		$guest = new WC_Order();
		$guest->set_billing_country( 'FR' );
		$guest->save();
		$this->assertSame( array(), $gatherer->from_order( $guest, 'admin', false )->exempt_roles() );
		$this->assertNull( DecisionStore::get( $guest ) );
	}
}
