<?php

namespace Vatright\Tests\Integration\Checkout;

use Vatright\Domain\Rule;

require_once __DIR__ . '/CheckoutTestCase.php';

class B2bOnlyTest extends CheckoutTestCase {

	const DOWN_BODY    = '{"actionSucceed":false,"errorWrappers":[{"error":"MS_UNAVAILABLE"}]}';
	const INVALID_BODY = '{"countryCode":"DE","vatNumber":"123475223","valid":false}';

	/**
	 * @param string[]            $countries
	 * @param array<string,mixed> $more
	 */
	private function b2b( string $mode, array $countries = array(), array $more = array() ): void {
		$this->settings(
			array_merge(
				array(
					'b2b_only'           => $mode,
					'b2b_only_countries' => $countries,
				),
				$more
			)
		);
	}

	private function cart_with_number( string $number ): void {
		$this->add_to_cart( $this->product( 'goods' ) );
		WC()->customer->update_meta_data( '_vatright_number', $number );
	}

	private function no_orders(): void {
		$this->assertSame( array(), wc_get_orders( array( 'status' => array( 'processing', 'completed', 'on-hold', 'pending' ) ) ) );
	}

	public function test_off_by_default_lets_an_order_without_a_number_through_on_both_checkouts(): void {
		$this->add_to_cart( $this->product( 'goods' ) );
		$this->assertSame( 'success', $this->classic_checkout( 'DE', '10115' )['result'] );

		$this->add_to_cart( $this->product( 'goods' ) );
		$this->order_from( $this->block_checkout( 'DE', '10115' ) );
	}

	public function test_block_checkout_refuses_an_order_without_a_number(): void {
		$this->b2b( 'everywhere' );
		$this->add_to_cart( $this->product( 'goods' ) );

		$response = $this->block_checkout( 'DE', '10115' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'vatright_b2b_only_required', $response->get_data()['code'] );
		$this->assertStringContainsString( 'Enter a valid VAT number', $response->get_data()['message'] );
		$this->no_orders();
	}

	public function test_classic_checkout_refuses_an_order_without_a_number(): void {
		$this->b2b( 'everywhere' );
		$this->add_to_cart( $this->product( 'goods' ) );

		$reply = $this->classic_checkout( 'DE', '10115' );

		$this->assertSame( 'failure', $reply['result'] );
		$this->assertStringContainsString( 'Enter a valid VAT number', (string) $reply['messages'] );
		$this->no_orders();
	}

	public function test_a_verified_number_places_the_order_on_both_checkouts(): void {
		$this->b2b( 'everywhere' );
		$this->cart_with_number( self::VALID_DE );
		$this->assertSame( 'success', $this->classic_checkout( 'DE', '10115' )['result'] );

		$this->cart_with_number( self::VALID_DE );
		$order = $this->order_from( $this->block_checkout( 'DE', '10115' ) );
		$this->assertSame( 'yes', $order->get_meta( 'is_vat_exempt' ) );
	}

	public function test_an_invalid_number_gets_the_business_only_message_not_the_pay_with_vat_hint(): void {
		$this->b2b( 'everywhere' );
		$this->vies_answer( self::INVALID_DE, self::INVALID_BODY );
		$this->cart_with_number( self::INVALID_DE );

		$block = $this->block_checkout( 'DE', '10115' );
		$this->assertSame( 400, $block->get_status() );
		$this->assertSame( 'vatright_invalid_vat_number', $block->get_data()['code'] );
		$this->assertStringContainsString( 'businesses only', $block->get_data()['message'] );
		$this->assertStringNotContainsString( 'leave the field empty', $block->get_data()['message'] );

		$this->cart_with_number( self::INVALID_DE );
		$classic = $this->classic_checkout( 'DE', '10115' );
		$this->assertSame( 'failure', $classic['result'] );
		$this->assertStringContainsString( 'businesses only', (string) $classic['messages'] );
		$this->assertStringNotContainsString( 'leave the field empty', (string) $classic['messages'] );
		$this->no_orders();
	}

	public function test_the_invalid_number_message_is_unchanged_when_the_mode_is_off(): void {
		$this->vies_answer( self::INVALID_DE, self::INVALID_BODY );
		$this->cart_with_number( self::INVALID_DE );

		$this->assertStringContainsString( 'leave the field empty', $this->block_checkout( 'DE', '10115' )->get_data()['message'] );
	}

	public function test_eu_mode_leaves_non_eu_customers_alone(): void {
		$this->b2b( 'eu' );
		$this->add_to_cart( $this->product( 'goods' ) );
		$this->assertSame( 'success', $this->classic_checkout( 'CH', '8001' )['result'] );

		$this->add_to_cart( $this->product( 'goods' ) );
		$this->assertSame( 'vatright_b2b_only_required', $this->block_checkout( 'FR', '75001' )->get_data()['code'] );
	}

	public function test_list_mode_covers_only_the_chosen_countries(): void {
		$this->b2b( 'countries', array( 'FR', 'CH' ) );

		$this->add_to_cart( $this->product( 'goods' ) );
		$this->assertSame( 'failure', $this->classic_checkout( 'CH', '8001' )['result'] );

		$this->add_to_cart( $this->product( 'goods' ) );
		$this->order_from( $this->block_checkout( 'DE', '10115' ) );
	}

	public function test_everywhere_mode_covers_countries_outside_the_eu_too(): void {
		$this->b2b( 'everywhere' );
		$this->add_to_cart( $this->product( 'goods' ) );

		$this->assertSame( 'vatright_b2b_only_required', $this->block_checkout( 'CH', '8001' )->get_data()['code'] );
	}

	public function test_the_message_can_be_edited_with_a_filter(): void {
		$this->b2b( 'everywhere' );
		$seen = array();
		add_filter(
			'vatright_b2b_only_message',
			static function ( $message, $code, $country ) use ( &$seen ) {
				$seen = array( $code, $country );
				return 'Trade accounts only.';
			},
			10,
			3
		);
		$this->add_to_cart( $this->product( 'goods' ) );

		$block = $this->block_checkout( 'DE', '10115' );
		$this->assertSame( 'Trade accounts only.', $block->get_data()['message'] );
		$this->assertSame( array( 'b2b_only_required', 'DE' ), $seen );

		$this->add_to_cart( $this->product( 'goods' ) );
		$this->assertStringContainsString( 'Trade accounts only.', (string) $this->classic_checkout( 'DE', '10115' )['messages'] );
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public function outage_policies(): array {
		return array(
			'charge VAT and flag it'     => array( 'charge', true ),
			'exempt now, re-check later' => array( 'exempt_pending', true ),
			'do not accept the order'    => array( 'reject', false ),
		);
	}

	/**
	 * A registry that was tried and failed follows the outage policy: the
	 * number is accepted only as the policy accepts it, and always flagged.
	 *
	 * @dataProvider outage_policies
	 */
	public function test_a_registry_outage_follows_the_outage_policy( string $policy, bool $accepted ): void {
		$this->b2b( 'everywhere', array(), array( 'outage_policy' => $policy ) );
		$this->vies_answer( self::DOWN_DE, self::DOWN_BODY );
		$this->cart_with_number( self::DOWN_DE );

		$response = $this->block_checkout( 'DE', '10115' );

		if ( ! $accepted ) {
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'vatright_registry_unavailable', $response->get_data()['code'] );
			$this->assertStringContainsString( 'businesses only', $response->get_data()['message'] );
			$this->no_orders();
			return;
		}
		$order = $this->order_from( $response );
		$this->assertContains( 'validation_pending', $this->stored_decision( $order )->flags() );
	}

	/**
	 * VIES turning the store away for load is not an outage the shop chose to
	 * absorb: the number stays unchecked and the order is refused.
	 */
	public function test_a_throttled_lookup_leaves_the_number_unchecked_and_is_refused(): void {
		$this->b2b( 'everywhere', array(), array( 'outage_policy' => 'exempt_pending' ) );
		$this->vies_answer( self::DOWN_DE, '{"actionSucceed":false,"errorWrappers":[{"error":"MS_MAX_CONCURRENT_REQ"}]}' );
		$this->cart_with_number( self::DOWN_DE );

		$response = $this->block_checkout( 'DE', '10115' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'vatright_b2b_only_unverified', $response->get_data()['code'] );
		$this->no_orders();
	}

	public function test_outage_rule_is_still_recorded_on_the_accepted_order(): void {
		$this->b2b( 'everywhere' );
		$this->vies_answer( self::DOWN_DE, self::DOWN_BODY );
		$this->cart_with_number( self::DOWN_DE );

		$order = $this->order_from( $this->block_checkout( 'DE', '10115' ) );

		$this->assertSame( Rule::PENDING_CHARGED, $this->stored_decision( $order )->rule() );
	}
}
