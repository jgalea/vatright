<?php

namespace Vatright\Tests\Integration\Checkout;

use Vatright\Checkout\CartSteering;
use Vatright\Checkout\InputGatherer;
use Vatright\Checkout\StoreApiExtension;
use Vatright\Settings;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the tests fire WooCommerce's own hooks.

require_once __DIR__ . '/CheckoutTestCase.php';

class SelfCertificationTest extends CheckoutTestCase {

	public function tear_down() {
		remove_all_filters( 'woocommerce_geolocate_ip' );
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	private function visitor_in( string $country ): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		add_filter(
			'woocommerce_geolocate_ip',
			static function () use ( $country ) {
				return $country;
			}
		);
	}

	public function test_setting_is_off_by_default(): void {
		$this->assertFalse( Settings::defaults()['self_certification_required'] );
		$this->assertFalse( Settings::get()->self_certification_required() );
	}

	public function test_conflict_does_not_block_classic_checkout_when_off(): void {
		$this->visitor_in( 'FR' );
		$this->add_to_cart( $this->product( 'digital' ) );

		$reply = $this->classic_checkout( 'DE', '10115' );

		$this->assertSame( 'success', $reply['result'] );
	}

	public function test_conflict_does_not_block_block_checkout_when_off(): void {
		$this->visitor_in( 'FR' );
		$this->add_to_cart( $this->product( 'digital' ) );

		$this->order_from( $this->block_checkout( 'DE', '10115' ) );
	}

	public function test_conflict_blocks_classic_checkout_when_required(): void {
		$this->settings( array( 'self_certification_required' => true ) );
		$this->visitor_in( 'FR' );
		$this->add_to_cart( $this->product( 'digital' ) );

		$reply = $this->classic_checkout( 'DE', '10115' );

		$this->assertSame( 'failure', $reply['result'] );
		$this->assertStringContainsString( 'Confirm the country you are located in', (string) $reply['messages'] );
		$this->assertSame( array(), wc_get_orders( array( 'limit' => -1 ) ) );
	}

	public function test_conflict_blocks_block_checkout_when_required(): void {
		$this->settings( array( 'self_certification_required' => true ) );
		$this->visitor_in( 'FR' );
		$this->add_to_cart( $this->product( 'digital' ) );

		$response = $this->block_checkout( 'DE', '10115' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'vatright_self_certification_required', $response->get_data()['code'] );
		$this->assertSame( array(), wc_get_orders( array( 'limit' => -1 ) ) );
	}

	public function test_confirmation_lets_classic_checkout_through_and_is_stored(): void {
		$this->settings( array( 'self_certification_required' => true ) );
		$this->visitor_in( 'FR' );
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->session->set( InputGatherer::SELF_CERTIFIED_SESSION, 'DE' );

		$order = $this->placed( $this->classic_checkout( 'DE', '10115' ) );

		$this->assertSame( 'DE', $this->stored_decision( $order )->to_array()['evidence']['self_certified'] );
		$this->assertContains( 'evidence_conflict_self_certified', $this->stored_decision( $order )->flags() );
	}

	public function test_confirmation_lets_block_checkout_through_and_is_stored(): void {
		$this->settings( array( 'self_certification_required' => true ) );
		$this->visitor_in( 'FR' );
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->session->set( InputGatherer::SELF_CERTIFIED_SESSION, 'DE' );

		$order = $this->order_from( $this->block_checkout( 'DE', '10115' ) );

		$this->assertSame( 'DE', $this->stored_decision( $order )->to_array()['evidence']['self_certified'] );
	}

	public function test_confirmation_for_another_country_does_not_count(): void {
		$this->settings( array( 'self_certification_required' => true ) );
		$this->visitor_in( 'FR' );
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->session->set( InputGatherer::SELF_CERTIFIED_SESSION, 'AT' );

		$response = $this->block_checkout( 'DE', '10115' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'vatright_self_certification_required', $response->get_data()['code'] );
	}

	public function test_prompt_off_means_nothing_is_required(): void {
		$this->settings(
			array(
				'self_certification'          => false,
				'self_certification_required' => true,
			)
		);
		$this->visitor_in( 'FR' );
		$this->add_to_cart( $this->product( 'digital' ) );

		$this->order_from( $this->block_checkout( 'DE', '10115' ) );
	}

	public function test_goods_only_cart_is_never_blocked(): void {
		$this->settings( array( 'self_certification_required' => true ) );
		$this->visitor_in( 'FR' );
		$this->add_to_cart( $this->product( 'goods' ) );

		$this->order_from( $this->block_checkout( 'DE', '10115' ) );
	}

	public function test_no_ip_country_is_never_blocked(): void {
		$this->settings( array( 'self_certification_required' => true ) );
		$this->add_to_cart( $this->product( 'digital' ) );

		$this->order_from( $this->block_checkout( 'DE', '10115' ) );
	}

	public function test_valid_business_is_never_blocked(): void {
		$this->settings( array( 'self_certification_required' => true ) );
		$this->visitor_in( 'FR' );
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::VALID_DE );

		$order = $this->order_from( $this->block_checkout( 'DE', '10115' ) );

		$this->assertSame( 0.0, (float) $order->get_total_tax() );
	}

	public function test_status_tells_the_block_the_confirmation_is_required(): void {
		$this->settings( array( 'self_certification_required' => true ) );
		$this->visitor_in( 'FR' );
		$this->add_to_cart( $this->product( 'digital' ) );
		$this->customer( 'DE', '10115' );

		$status = StoreApiExtension::status( $this->calculate() );
		$this->assertTrue( $status['self_certification_required'] );
		$this->assertStringContainsString( 'Confirm the country you are located in', $status['message'] );

		CartSteering::session()->set( InputGatherer::SELF_CERTIFIED_SESSION, 'DE' );
		$confirmed = StoreApiExtension::status( $this->calculate() );
		$this->assertFalse( $confirmed['self_certification_required'] );
		$this->assertSame( '', $confirmed['message'] );
		CartSteering::session()->set( InputGatherer::SELF_CERTIFIED_SESSION, null );

		$this->settings( array() );
		$this->assertFalse( StoreApiExtension::status( $this->calculate() )['self_certification_required'] );
	}
}
