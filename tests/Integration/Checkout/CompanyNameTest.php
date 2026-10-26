<?php

namespace Vatright\Tests\Integration\Checkout;

use Vatright\Checkout\StoreApiExtension;
use Vatright\Domain\Decision;
use ReflectionProperty;
use WC_Checkout;

require_once __DIR__ . '/CheckoutTestCase.php';

class CompanyNameTest extends CheckoutTestCase {

	const ES_NUMBER   = 'ESA15075062';
	const REGISTERED  = 'Industria de Diseno Textil SA';
	const WRONG       = 'Totally Different Bakery';
	const REFUSAL     = 'registered for this VAT number';
	const DE_NO_MATCH = 'DE114103379';
	const NL_NUMBER   = 'NL820646660B01';

	/** @var string[] Bodies of the VIES requests, in order. */
	private $bodies = array();

	public function set_up() {
		parent::set_up();
		$this->bodies = array();
		$this->add_rate( 'ES', '21' );
		$this->add_rate( 'NL', '21' );
		$this->vies_fixture( 'name_match_es_invalid' );
	}

	/**
	 * Records the request body. Without a name to compare, VIES gives no
	 * verdict whatever the country, so a request without one is answered with
	 * NOT_PROCESSED.
	 *
	 * @param false|array<string,mixed> $preempt
	 * @param array<string,mixed>       $args
	 * @return false|array<string,mixed>
	 */
	public function route_vies( $preempt, $args, string $url ) {
		$body = (string) ( $args['body'] ?? '' );
		if ( false !== strpos( $url, 'check-vat-number' ) ) {
			$this->bodies[] = $body;
			if ( false === strpos( $body, 'traderName' ) && false !== strpos( $body, 'A15075062' ) && isset( $this->vies['A15075062'] ) && false !== strpos( (string) $this->vies['A15075062']['body'], 'traderNameMatch' ) ) {
				++$this->vies_calls;
				return $this->http( 200, '{"countryCode":"ES","vatNumber":"A15075062","valid":true,"traderNameMatch":"NOT_PROCESSED"}' );
			}
		}
		return parent::route_vies( $preempt, $args, $url );
	}

	/**
	 * WC_Checkout builds its fields for the first customer's country only.
	 */
	private function reset_checkout_fields(): void {
		$fields = new ReflectionProperty( WC_Checkout::class, 'fields' );
		$fields->setAccessible( true );
		$fields->setValue( WC()->checkout(), null );
	}

	private function refuse( bool $on = true ): void {
		$this->settings( array( 'refuse_name_mismatch' => $on ) );
	}

	private function cart_es(): void {
		$this->add_to_cart( $this->product( 'goods' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::ES_NUMBER );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function block_extra( string $company, string $country = 'ES' ): array {
		return array( 'billing_address' => array_merge( $this->address( $country, 'NL' === $country ? '1017AB' : '28001' ), array( 'company' => $company ) ) );
	}

	private function no_orders(): void {
		$this->assertSame( array(), wc_get_orders( array( 'status' => array( 'processing', 'completed', 'on-hold', 'pending' ) ) ) );
	}

	public function test_block_checkout_refuses_a_name_the_registry_rejects(): void {
		$this->refuse();
		$this->cart_es();

		$response = $this->block_checkout( 'ES', '28001', $this->block_extra( self::WRONG ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'vatright_name_mismatch', $response->get_data()['code'] );
		$this->assertStringContainsString( self::REFUSAL, $response->get_data()['message'] );
		$this->no_orders();
	}

	public function test_classic_checkout_refuses_a_name_the_registry_rejects(): void {
		$this->refuse();
		$this->cart_es();

		$reply = $this->classic_checkout( 'ES', '28001', array( 'billing_company' => self::WRONG ) );

		$this->assertSame( 'failure', $reply['result'] );
		$this->assertStringContainsString( self::REFUSAL, (string) $reply['messages'] );
		$this->no_orders();
	}

	public function test_the_name_goes_to_the_registry_with_the_number(): void {
		$this->refuse();
		$this->cart_es();

		$this->block_checkout( 'ES', '28001', $this->block_extra( self::WRONG ) );

		$this->assertNotSame( array(), $this->bodies );
		$this->assertStringContainsString( '"traderName":"' . self::WRONG . '"', end( $this->bodies ) );
	}

	public function test_off_by_default_the_same_mismatch_places_the_order_and_no_name_is_sent(): void {
		$this->cart_es();
		$this->order_from( $this->block_checkout( 'ES', '28001', $this->block_extra( self::WRONG ) ) );

		$this->cart_es();
		$this->assertSame( 'success', $this->classic_checkout( 'ES', '28001', array( 'billing_company' => self::WRONG ) )['result'] );

		foreach ( $this->bodies as $body ) {
			$this->assertStringNotContainsString( 'traderName', $body );
		}
	}

	public function test_a_name_the_registry_accepts_places_the_order_on_both_checkouts(): void {
		$this->refuse();
		$this->vies_fixture( 'name_match_es_valid' );
		$this->cart_es();
		$order = $this->order_from( $this->block_checkout( 'ES', '28001', $this->block_extra( self::REGISTERED ) ) );
		$this->assertSame( 'yes', $order->get_meta( 'is_vat_exempt' ) );

		$this->cart_es();
		$this->assertSame( 'success', $this->classic_checkout( 'ES', '28001', array( 'billing_company' => self::REGISTERED ) )['result'] );
	}

	public function test_without_a_company_name_nothing_is_sent_and_nothing_is_refused(): void {
		$this->refuse();
		$this->cart_es();
		$this->order_from( $this->block_checkout( 'ES', '28001', $this->block_extra( '' ) ) );

		$this->cart_es();
		$this->assertSame( 'success', $this->classic_checkout( 'ES', '28001' )['result'] );

		$this->assertNotSame( array(), $this->bodies );
		foreach ( $this->bodies as $body ) {
			$this->assertStringNotContainsString( 'traderName', $body );
		}
	}

	public function test_a_country_whose_registry_returns_no_verdict_never_refuses(): void {
		$this->refuse();
		$this->vies_fixture( 'name_match_not_processed_de' );
		$this->add_to_cart( $this->product( 'goods' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::DE_NO_MATCH );

		$order = $this->order_from( $this->block_checkout( 'DE', '10115', $this->block_extra( self::WRONG, 'DE' ) ) );

		$this->assertStringContainsString( '"traderName":"' . self::WRONG . '"', end( $this->bodies ), 'The name was sent, VIES just gave no verdict.' );
		$this->reset_checkout_fields();
		$this->assertSame( 'yes', $order->get_meta( 'is_vat_exempt' ) );

		$this->add_to_cart( $this->product( 'goods' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::DE_NO_MATCH );
		$reply = $this->classic_checkout( 'DE', '10115', array( 'billing_company' => self::WRONG ) );
		$this->assertSame( 'success', $reply['result'], (string) wp_json_encode( $reply ) );
	}

	public function test_a_registry_outage_is_not_a_name_mismatch(): void {
		$this->refuse();
		$this->vies_answer( self::ES_NUMBER, '{"actionSucceed":false,"errorWrappers":[{"error":"MS_UNAVAILABLE"}]}' );
		$this->cart_es();

		$order = $this->order_from( $this->block_checkout( 'ES', '28001', $this->block_extra( self::WRONG ) ) );

		$this->assertContains( 'validation_pending', $this->stored_decision( $order )->flags() );
	}

	public function test_an_invalid_number_keeps_the_invalid_number_message(): void {
		$this->refuse();
		$this->vies_answer( self::ES_NUMBER, '{"countryCode":"ES","vatNumber":"A15075062","valid":false,"traderNameMatch":"INVALID"}' );
		$this->cart_es();

		$response = $this->block_checkout( 'ES', '28001', $this->block_extra( self::WRONG ) );

		$this->assertSame( 'vatright_invalid_vat_number', $response->get_data()['code'] );
	}

	public function test_the_checkout_notice_says_to_use_the_registered_name(): void {
		$this->refuse();
		$this->cart_es();
		$this->block_checkout( 'ES', '28001', $this->block_extra( self::WRONG ) );

		$decision = $this->steering()->current();
		$this->assertInstanceOf( Decision::class, $decision );
		$status = StoreApiExtension::status( $decision );

		$this->assertSame( 'invalid', $status['status'] );
		$this->assertStringContainsString( self::REFUSAL, $status['message'] );
	}

	public function test_turning_the_setting_off_stops_the_notice(): void {
		$this->refuse();
		$this->cart_es();
		$this->block_checkout( 'ES', '28001', $this->block_extra( self::WRONG ) );
		$decision = $this->steering()->current();
		$this->refuse( false );

		$this->assertNotNull( $decision );
		$this->assertStringNotContainsString( self::REFUSAL, StoreApiExtension::status( $decision )['message'] );
	}

	public function test_a_lookup_without_the_network_cannot_refuse(): void {
		$this->refuse();
		$this->cart_es();
		WC()->customer->set_billing_country( 'ES' );
		WC()->customer->set_shipping_country( 'ES' );
		WC()->customer->set_billing_company( self::WRONG );

		$decision = $this->calculate();

		$this->assertNotNull( $decision );
		$this->assertNull( $decision->blocking_error() );
		$this->assertSame( array(), $this->bodies, 'A cart update stays offline and carries no name.' );
		$this->assertStringNotContainsString( self::REFUSAL, StoreApiExtension::status( $decision )['message'] );
	}

	private function cart_nl(): void {
		$this->vies_fixture( 'name_match_not_processed_nl' );
		$this->add_to_cart( $this->product( 'goods' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::NL_NUMBER );
	}

	public function test_block_checkout_refuses_a_name_that_differs_from_the_one_vies_returns(): void {
		$this->refuse();
		$this->cart_nl();

		$response = $this->block_checkout( 'NL', '1017AB', $this->block_extra( self::WRONG, 'NL' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'vatright_name_mismatch', $response->get_data()['code'] );
		$this->no_orders();
	}

	public function test_classic_checkout_refuses_a_name_that_differs_from_the_one_vies_returns(): void {
		$this->refuse();
		$this->cart_nl();

		$reply = $this->classic_checkout( 'NL', '1017AB', array( 'billing_company' => self::WRONG ) );

		$this->assertSame( 'failure', $reply['result'] );
		$this->assertStringContainsString( self::REFUSAL, (string) $reply['messages'] );
		$this->no_orders();
	}

	public function test_a_looser_spelling_of_the_returned_name_places_the_order_on_both_checkouts(): void {
		$this->refuse();
		$this->cart_nl();
		$order = $this->order_from( $this->block_checkout( 'NL', '1017AB', $this->block_extra( 'abn amro bank', 'NL' ) ) );
		$this->assertSame( 'yes', $order->get_meta( 'is_vat_exempt' ) );

		$this->reset_checkout_fields();
		$this->cart_nl();
		$reply = $this->classic_checkout( 'NL', '1017AB', array( 'billing_company' => 'ABN AMRO Bank Naamloze Vennootschap' ) );
		$this->assertSame( 'success', $reply['result'], (string) wp_json_encode( $reply ) );
	}

	public function test_the_decision_records_a_local_comparison_as_the_reason(): void {
		$this->refuse();
		$this->cart_nl();
		$this->block_checkout( 'NL', '1017AB', $this->block_extra( self::WRONG, 'NL' ) );

		$decision = $this->steering()->current();

		$this->assertInstanceOf( Decision::class, $decision );
		$this->assertSame( 'name_mismatch_local', $decision->to_array()['validation']['detail'] );
		$this->assertSame( 'ABN AMRO BANK N.V.', $decision->to_array()['validation']['name'] );
	}

	public function test_the_decision_records_the_registry_verdict_as_the_reason(): void {
		$this->refuse();
		$this->cart_es();
		$this->block_checkout( 'ES', '28001', $this->block_extra( self::WRONG ) );

		$decision = $this->steering()->current();

		$this->assertInstanceOf( Decision::class, $decision );
		$this->assertSame( 'name_mismatch', $decision->to_array()['validation']['detail'] );
	}

	public function test_a_registry_that_returns_no_name_never_refuses_locally(): void {
		$this->refuse();
		$this->vies_fixture( 'name_match_not_processed_de' );
		$this->add_to_cart( $this->product( 'goods' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::DE_NO_MATCH );

		$this->assertNull( $this->block_checkout( 'DE', '10115', $this->block_extra( self::WRONG, 'DE' ) )->get_data()['code'] ?? null );
	}

	public function test_with_the_setting_off_the_local_comparison_does_not_run(): void {
		$this->cart_nl();
		$this->order_from( $this->block_checkout( 'NL', '1017AB', $this->block_extra( self::WRONG, 'NL' ) ) );
	}
}
