<?php

namespace Vatright\Tests\Integration\Orders;

use Vatright\Migration\LegacyData;
use Vatright\Orders\AdminRecalc;
use Vatright\Orders\DecisionStore;

require_once __DIR__ . '/LifecycleTestCase.php';

class AdminRecalcTest extends LifecycleTestCase {

	/**
	 * A German consumer who was charged the store's 18% (below the
	 * threshold), before anyone entered a VAT number.
	 */
	private function charged_order() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		DecisionStore::apply( $order, $this->decide( 'DE', 'DE', '10115', array( 'digital' ) ), 'checkout' );
		$order->calculate_totals();
		$order->save();
		$this->assertSame( 18.0, (float) $order->get_total_tax() );
		return $order;
	}

	public function test_admin_recalc_appends_log_and_toggles_exemption() {
		$order = $this->charged_order();
		$admin = $this->admin();
		$this->registry_valid();

		$order->update_meta_data( DecisionStore::NUMBER_META, self::NUMBER );
		$order->calculate_taxes();
		$order->calculate_totals( false );
		$order->save();

		$order    = $this->fresh( $order );
		$decision = DecisionStore::get( $order );
		$this->assertSame( 'RC_SERVICES_ART196', $decision->rule() );
		$this->assertSame( 'yes', $order->get_meta( 'is_vat_exempt' ) );
		$this->assertSame( 0.0, (float) $order->get_total_tax() );
		$log = DecisionStore::log( $order );
		$this->assertCount( 2, $log );
		$this->assertSame( 'admin', $log[1]['trigger'] );
		$this->assertSame( $admin, $log[1]['actor_user_id'] );
		$this->assertSame( $log[0]['decision_id'], $log[1]['previous_id'] );

		$order->update_meta_data( DecisionStore::NUMBER_META, '' );
		$order->calculate_taxes();
		$order->calculate_totals( false );
		$order->save();

		$order = $this->fresh( $order );
		$this->assertSame( 'no', $order->get_meta( 'is_vat_exempt' ) );
		$this->assertSame( 18.0, (float) $order->get_total_tax(), 'Removing the number charges VAT again' );
		$this->assertCount( 3, DecisionStore::log( $order ) );
	}

	/**
	 * The order screen's Recalculate button posts the address in the form,
	 * saved or not, and Woo taxes at that address; the decision must be made
	 * for the same address.
	 */
	public function test_recalc_decides_for_the_address_woo_taxes_at() {
		update_option( 'woocommerce_tax_based_on', 'billing' );
		$order = $this->order( 'US', 'US', '', array( 'digital' ) );
		DecisionStore::apply( $order, $this->decide( 'US', 'US', '', array( 'digital' ) ), 'checkout' );
		$order->calculate_totals();
		$order->save();
		$this->assertSame( 0.0, (float) $order->get_total_tax() );
		$this->admin();

		$order->calculate_taxes(
			array(
				'country'  => 'DE',
				'state'    => '',
				'postcode' => '10115',
				'city'     => 'Berlin',
			)
		);
		$order->calculate_totals( false );

		$this->assertSame( 'B2C_ORIGIN_BELOW_THRESHOLD', $this->stored( $order )->rule() );
		$this->assertSame( 18.0, (float) $order->get_total_tax() );
		$this->assertSame( 'US', $this->fresh( $order )->get_billing_country(), 'The unsaved address is not saved by deciding' );
	}

	public function test_recalc_that_changes_nothing_adds_no_log_row() {
		$order = $this->charged_order();
		$this->admin();

		$order->calculate_taxes();
		$order->calculate_taxes();
		$order->save();

		$this->assertCount( 1, DecisionStore::log( $this->fresh( $order ) ) );
	}

	public function test_a_changed_number_is_applied_even_when_the_tax_is_the_same() {
		$order = $this->charged_order();
		$this->admin();
		$this->registry_invalid();

		$order->update_meta_data( DecisionStore::NUMBER_META, self::NUMBER );
		$order->calculate_taxes();
		$order->save();

		$decision = $this->stored( $order );
		$this->assertSame( 'INVALID_NUMBER', $decision->rule() );
		$this->assertFalse( $decision->is_vat_exempt() );
		$this->assertCount( 2, DecisionStore::log( $this->fresh( $order ) ) );
	}

	public function test_order_without_a_decision_gets_one() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		$this->admin();
		$this->registry_valid();

		$order->update_meta_data( DecisionStore::NUMBER_META, self::NUMBER );
		$order->calculate_taxes();
		$order->save();

		$this->assertSame( 'RC_SERVICES_ART196', $this->stored( $order )->rule() );
	}

	public function test_recalc_without_the_capability_leaves_the_decision_alone() {
		$order = $this->charged_order();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->registry_valid();

		$order->update_meta_data( DecisionStore::NUMBER_META, self::NUMBER );
		$order->calculate_taxes();
		$order->save();

		$this->assertSame( 0, $this->vies_calls );
		$this->assertCount( 1, DecisionStore::log( $this->fresh( $order ) ) );
		$this->assertSame( 'no', $this->fresh( $order )->get_meta( 'is_vat_exempt' ) );
	}

	public function test_draft_orders_are_left_to_the_checkout() {
		$order = $this->charged_order();
		$order->set_status( 'checkout-draft' );
		$order->save();
		$this->admin();
		$this->registry_valid();

		$order->update_meta_data( DecisionStore::NUMBER_META, self::NUMBER );
		$order->calculate_taxes();

		$this->assertSame( 0, $this->vies_calls );
		$this->assertCount( 1, DecisionStore::log( $order ) );
	}

	public function test_failure_to_decide_keeps_the_stored_decision() {
		$order = $this->charged_order();
		$this->admin();
		$threw = 0;
		add_filter(
			'woocommerce_order_get_billing_country',
			static function () use ( &$threw ) {
				++$threw;
				throw new \RuntimeException( 'broken' );
			}
		);

		$order->calculate_taxes();

		$this->assertSame( 1, $threw, 'The decision was attempted' );
		$this->assertCount( 1, DecisionStore::log( $order ) );
	}

	public function test_save_number_stores_the_canonical_number() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		$this->admin();

		$_POST[ AdminRecalc::FIELD ] = ' de 114.103.379 ';
		do_action( 'woocommerce_process_shop_order_meta', $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing Woo's hook.
		$this->assertSame( self::NUMBER, $this->fresh( $order )->get_meta( DecisionStore::NUMBER_META ) );

		$_POST[ AdminRecalc::FIELD ] = '';
		do_action( 'woocommerce_process_shop_order_meta', $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing Woo's hook.
		$this->assertSame( '', $this->fresh( $order )->get_meta( DecisionStore::NUMBER_META ) );
		$_POST = array();
	}

	public function test_clearing_a_number_another_plugin_left_keeps_it_cleared() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		$order->update_meta_data( '_billing_vat_number', self::NUMBER );
		$order->save();
		$this->admin();

		$_POST[ AdminRecalc::FIELD ] = self::NUMBER;
		do_action( 'woocommerce_process_shop_order_meta', $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing Woo's hook.
		$this->assertFalse( $this->fresh( $order )->meta_exists( DecisionStore::NUMBER_META ), 'Saving the number shown unchanged stores nothing' );

		$_POST[ AdminRecalc::FIELD ] = '';
		do_action( 'woocommerce_process_shop_order_meta', $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing Woo's hook.
		$_POST = array();

		$order = $this->fresh( $order );
		$this->assertSame( '', LegacyData::order_number( $order ) );
		$this->assertSame( self::NUMBER, $order->get_meta( '_billing_vat_number' ), 'The other plugin\'s key is left as it was' );
	}

	public function test_save_number_needs_the_capability() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'customer' ) ) );

		$_POST[ AdminRecalc::FIELD ] = self::NUMBER;
		do_action( 'woocommerce_process_shop_order_meta', $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing Woo's hook.
		$_POST = array();

		$this->assertSame( '', $this->fresh( $order )->get_meta( DecisionStore::NUMBER_META ) );
	}
}
