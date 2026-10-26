<?php

namespace Vatright\Tests\Integration\Integrations;

use Vatright\Checkout\InputGatherer;
use Vatright\Domain\Rules;
use Vatright\Integrations\Subscriptions;
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
use Vatright\Tests\Integration\Checkout\CheckoutTestCase;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Simple;
use WC_Subscription;

require_once __DIR__ . '/../Checkout/CheckoutTestCase.php';
require_once dirname( __DIR__, 2 ) . '/Support/WcsStandIn.php';

/**
 * Everything here runs against a stand-in that fires the hooks documented for
 * WooCommerce Subscriptions; none of it has run against the real plugin.
 */
class SubscriptionsTest extends CheckoutTestCase {

	const DOWN = '{"actionSucceed":false,"errorWrappers":[{"error":"MS_UNAVAILABLE"}]}';

	public function set_up() {
		if ( class_exists( 'WC_Subscriptions' ) && ! defined( 'VATRIGHT_TEST_WCS_STAND_IN' ) ) {
			$this->markTestSkipped( 'The real WooCommerce Subscriptions is active; these tests drive a stand-in.' );
		}
		parent::set_up();
	}

	public function test_renewal_reuses_fresh_validation() {
		$sub = $this->subscription();
		$this->forget_cached_answer();
		$this->vies_calls = 0;
		$this->register( $this->gatherer() );

		$renewal = $this->renew( $sub );

		$this->assertSame( 0, $this->vies_calls, 'A fresh stored validation must not reach the registry' );
		$decision = DecisionStore::get( $renewal )->to_array();
		$this->assertSame( 'renewal', $decision['trigger'] );
		$this->assertTrue( $decision['vat_exempt'] );
		$this->assertSame( 'valid', $decision['validation']['status'] );
		$this->assertTrue( $decision['validation']['from_cache'] );
		$this->assertSame( DecisionStore::get( $sub )->to_array()['validation']['consultation'], $decision['validation']['consultation'] );
		$this->assertSame( 0.0, (float) $renewal->get_total_tax() );
		$this->assertSame( DecisionStore::get( $sub )->id(), DecisionStore::get( wc_get_order( $sub->get_id() ) )->id(), 'Nothing changed, so the subscription keeps its decision' );
	}

	public function test_renewal_revalidates_stale_number() {
		$sub = $this->subscription();
		$this->age_validation( $sub, 10 );
		$this->vies_calls = 0;
		$this->register( $this->gatherer() );

		$renewal = $this->renew( $sub );

		$this->assertSame( 1, $this->vies_calls );
		$decision = DecisionStore::get( $renewal );
		$this->assertFalse( $decision->to_array()['validation']['from_cache'] );
		$this->assertTrue( $decision->is_vat_exempt() );
		$this->assertSame( $decision->id(), DecisionStore::get( wc_get_order( $sub->get_id() ) )->id(), 'The subscription takes the fresh registry answer' );
	}

	public function test_renewal_number_now_invalid_charges_vat() {
		$sub = $this->subscription();
		$this->age_validation( $sub, 10 );
		$this->vies_answer( self::VALID_DE, '{"countryCode":"DE","vatNumber":"114103379","valid":false}' );
		$this->register( $this->gatherer() );

		$renewal = $this->renew( $sub );

		$this->assertSame( 'no', $renewal->get_meta( 'is_vat_exempt' ) );
		$this->assertSame( 18.0, (float) $renewal->get_total_tax(), 'Below the threshold a German consumer pays the store country rate' );
		$this->assertSame( 118.0, (float) $renewal->get_total() );
		$sub_after = wc_get_order( $sub->get_id() );
		$this->assertFalse( DecisionStore::get( $sub_after )->is_vat_exempt() );
		$this->assertCount( 2, DecisionStore::log( $sub_after ), 'The change is logged on the subscription' );
		$renewal_log = DecisionStore::log( $renewal );
		$this->assertSame( 'renewal', end( $renewal_log )['trigger'], 'and on the renewal' );
	}

	/**
	 * @dataProvider outage_policies
	 */
	public function test_renewal_registry_down_follows_policy( string $policy, bool $exempt, float $tax ) {
		$this->settings( array( 'outage_policy' => $policy ) );
		$sub = $this->subscription();
		$this->age_validation( $sub, 10 );
		$this->vies_answer( self::VALID_DE, self::DOWN );
		$this->register( $this->gatherer() );

		$renewal = $this->renew( $sub );

		$decision = DecisionStore::get( $renewal );
		$this->assertSame( $exempt, $decision->is_vat_exempt() );
		$this->assertContains( 'validation_pending', $decision->flags() );
		$this->assertSame( $tax, (float) $renewal->get_total_tax() );
	}

	/**
	 * @return array<string,array{string,bool,float}>
	 */
	public function outage_policies(): array {
		return array(
			'charge'         => array( 'charge', false, 18.0 ),
			'exempt pending' => array( 'exempt_pending', true, 0.0 ),
			'reject'         => array( 'reject', false, 18.0 ),
		);
	}

	public function test_decision_meta_not_copied_verbatim() {
		$sub = $this->subscription();
		$this->age_validation( $sub, 10 );
		$this->register( $this->gatherer() );
		$stored = DecisionStore::get( wc_get_order( $sub->get_id() ) );

		$renewal = $this->renew( $sub );

		$this->assertNotSame( $stored->id(), DecisionStore::get( $renewal )->id() );
		$this->assertSame( 'renewal', DecisionStore::get( $renewal )->trigger() );
		$log = DecisionStore::log( $renewal );
		$this->assertSame( $stored->id(), end( $log )['previous_id'], 'The record names the decision it replaced' );
	}

	public function test_early_renewal_is_decided_like_any_renewal() {
		$sub = $this->subscription();
		$this->age_validation( $sub, 10 );
		$this->vies_answer( self::VALID_DE, '{"countryCode":"DE","vatNumber":"114103379","valid":false}' );
		$this->register( $this->gatherer() );

		$renewal = $this->renew( $sub, true );

		$this->assertSame( (string) $sub->get_id(), $renewal->get_meta( '_subscription_renewal_early' ) );
		$this->assertSame( 'renewal', DecisionStore::get( $renewal )->trigger() );
		$this->assertSame( 18.0, (float) $renewal->get_total_tax() );
	}

	public function test_subscription_without_a_decision_is_decided_from_its_number() {
		$sub = $this->subscription( false );
		$this->register( $this->gatherer() );

		$renewal = $this->renew( $sub );

		$this->assertSame( 1, $this->vies_calls );
		$this->assertTrue( DecisionStore::get( $renewal )->is_vat_exempt() );
		$this->assertSame( 0.0, (float) $renewal->get_total_tax() );
	}

	public function test_failed_decision_charges_vat() {
		$sub      = $this->subscription();
		$gatherer = $this->createMock( InputGatherer::class );
		$gatherer->method( 'from_order' )->willThrowException( new RuntimeException( 'boom' ) );
		$this->register( $gatherer );

		$renewal = $this->renew( $sub );

		$this->assertNull( DecisionStore::get( $renewal ), 'The copied exemption must not survive' );
		$this->assertSame( 'no', $renewal->get_meta( 'is_vat_exempt' ) );
		$this->assertGreaterThan( 0, (float) $renewal->get_total_tax() );
	}

	/**
	 * Like a checkout decision whose destination has no rate row, a renewal
	 * that cannot be decided is charged the store's rate rather than nothing.
	 * Outside the EU VAT area Woo's own (empty) answer stands.
	 *
	 * @return array<string,array{string,float}>
	 */
	public function failed_decision_without_a_rate_row(): array {
		return array(
			'Austria, no AT row: store rate'   => array( 'AT', 18.0 ),
			'United States: nothing to charge' => array( 'US', 0.0 ),
		);
	}

	/**
	 * @dataProvider failed_decision_without_a_rate_row
	 */
	public function test_failed_decision_without_a_rate_row( string $country, float $tax ) {
		$sub = $this->subscription();
		$sub->set_billing_country( $country );
		$sub->save();
		$gatherer = $this->createMock( InputGatherer::class );
		$gatherer->method( 'from_order' )->willThrowException( new RuntimeException( 'boom' ) );
		$this->register( $gatherer );

		$renewal = $this->renew( $sub );

		$this->assertSame( 'no', $renewal->get_meta( 'is_vat_exempt' ) );
		$this->assertSame( $tax, (float) $renewal->get_total_tax() );
	}

	public function test_initial_checkout_stores_decision_on_subscription() {
		$parent = $this->order( WC_Order::class );
		$this->decide_and_store( $parent );
		$sub = $this->order( WC_Subscription::class );
		$this->register( $this->gatherer() );

		do_action( 'woocommerce_checkout_subscription_created', $sub, $parent, null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- fires Subscriptions' hook.

		$stored = DecisionStore::get( wc_get_order( $sub->get_id() ) );
		$this->assertNotNull( $stored );
		$this->assertSame( DecisionStore::get( $parent )->id(), $stored->id() );
		$this->assertCount( 1, DecisionStore::log( wc_get_order( $sub->get_id() ) ) );
	}

	public function test_switch_stores_decision_on_subscription() {
		$sub    = $this->subscription();
		$switch = $this->order( WC_Order::class );
		$this->decide_and_store( $switch );
		add_filter(
			'vatright_test_wcs_switch_subscriptions',
			static function () use ( $sub ) {
				return array( $sub->get_id() => $sub );
			}
		);
		$this->register( $this->gatherer() );

		do_action( 'woocommerce_subscriptions_switch_completed', $switch ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- fires Subscriptions' hook.

		$this->assertSame( DecisionStore::get( $switch )->id(), DecisionStore::get( wc_get_order( $sub->get_id() ) )->id() );
	}

	private function register( InputGatherer $gatherer ): void {
		( new Subscriptions( $gatherer, new Rules() ) )->register();
	}

	private function gatherer(): InputGatherer {
		$transport = new WpHttpTransport();
		$settings  = Settings::get();
		return new InputGatherer(
			new Validator(
				new Router( new Vies( $transport ), new Hmrc( $transport, '', '' ), new SwissUid( $transport ), new Brreg( $transport ) ),
				new ValidationCache( $settings ),
				$settings
			),
			new ThresholdTracker(),
			new Fx( $transport )
		);
	}

	/**
	 * One 100.00 digital line for a German consumer, carrying the number and
	 * the checkout line key the way an order from this plugin does.
	 *
	 * @param class-string<WC_Order> $type
	 */
	private function order( string $type ): WC_Order {
		$order = new $type();
		$order->set_billing_country( 'DE' );
		$order->set_billing_postcode( '10115' );
		$order->update_meta_data( InputGatherer::NUMBER_META, self::VALID_DE );
		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_virtual( true );
		$product->save();
		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_subtotal( '100' );
		$item->set_total( '100' );
		$item->add_meta_data( InputGatherer::LINE_KEY_META, 'line-digital', true );
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->save();
		return $order;
	}

	private function decide_and_store( WC_Order $order ): void {
		$decision = ( new Rules() )->decide( $this->gatherer()->from_order( $order, 'checkout', true ) );
		DecisionStore::apply( $order, $decision, 'checkout' );
		$order->save();
	}

	private function subscription( bool $decided = true ): WC_Subscription {
		$sub = $this->order( WC_Subscription::class );
		if ( $decided ) {
			$this->decide_and_store( $sub );
			// The VIES fixtures carry a fixed requestDate, which would age out of the reuse window.
			$this->age_validation( $sub, 0 );
		}
		$this->vies_calls = 0;
		return $sub;
	}

	private function age_validation( WC_Order $sub, int $days ): void {
		$record                             = json_decode( (string) $sub->get_meta( DecisionStore::META ), true );
		$record['validation']['checked_at'] = gmdate( 'Y-m-d\TH:i:s\Z', time() - $days * DAY_IN_SECONDS );
		$sub->update_meta_data( DecisionStore::META, wp_json_encode( $record ) );
		$sub->save();
		$this->forget_cached_answer();
	}

	private function forget_cached_answer(): void {
		delete_transient( ValidationCache::PREFIX . md5( self::VALID_DE . '|' ) );
	}

	/**
	 * What the paid plugin does when a payment falls due: a new order with the
	 * subscription's addresses, items and every meta key copied verbatim, then
	 * the filter.
	 */
	private function renew( WC_Subscription $sub, bool $early = false ): WC_Order {
		$sub     = wc_get_order( $sub->get_id() );
		$renewal = new WC_Order();
		$renewal->set_address( $sub->get_address( 'billing' ), 'billing' );
		foreach ( $sub->get_items() as $item ) {
			$copy = new WC_Order_Item_Product();
			$copy->set_product( $item->get_product() );
			$copy->set_quantity( $item->get_quantity() );
			$copy->set_subtotal( $item->get_subtotal() );
			$copy->set_total( $item->get_total() );
			foreach ( $item->get_meta_data() as $meta ) {
				$copy->add_meta_data( $meta->key, $meta->value, true );
			}
			$renewal->add_item( $copy );
		}
		foreach ( $sub->get_meta_data() as $meta ) {
			if ( DecisionStore::LOG_META === $meta->key ) {
				$renewal->add_meta_data( $meta->key, $meta->value );
			} else {
				$renewal->update_meta_data( $meta->key, $meta->value );
			}
		}
		if ( $early ) {
			$renewal->update_meta_data( '_subscription_renewal_early', (string) $sub->get_id() );
		}
		$renewal->calculate_totals( false );
		$renewal->save();

		$renewal = apply_filters( 'wcs_renewal_order_created', $renewal, $sub ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- fires Subscriptions' hook.
		return wc_get_order( $renewal->get_id() );
	}
}
