<?php

namespace Vatright\Tests\Integration\Orders;

use Vatright\Orders\DecisionStore;
use Vatright\Orders\MetaBox;
use Vatright\Orders\Revalidation;
use RuntimeException;

require_once __DIR__ . '/LifecycleTestCase.php';

class MetaBoxTest extends LifecycleTestCase {

	private function metabox(): MetaBox {
		return new MetaBox( $this->revalidation() );
	}

	private function html( $order ): string {
		ob_start();
		$this->metabox()->render( $order );
		return (string) ob_get_clean();
	}

	public function test_meta_box_escapes_registry_name() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		$order->set_billing_company( '<img src=x onerror=alert(1)>' );
		DecisionStore::apply(
			$order,
			$this->decide( 'DE', 'DE', '10115', array( 'digital' ), false, self::NUMBER, 'valid', '', '<script>alert(1)</script> GmbH' ),
			'checkout'
		);
		$order->save();

		$html = $this->html( $this->fresh( $order ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; GmbH', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringContainsString( 'WAPIAAAA', $html, 'The consultation reference is shown' );
		$this->assertStringContainsString( 'Line 1<br />', $html, 'Multi-line addresses keep their line breaks' );
	}

	public function test_meta_box_shows_rule_in_words_flags_and_log() {
		$order = $this->pending_order( 'charge' );
		$this->registry_valid();
		$this->revalidation()->run( $order->get_id(), 1 );

		$html = $this->html( $this->fresh( $order ) );

		$this->assertStringContainsString( '<strong>The VAT number was checked later and is valid. VAT was charged at checkout.</strong>', $html );
		$this->assertStringNotContainsString( '<strong>The VAT number could not be checked', $html );
		$this->assertStringContainsString( 'The customer may be due a VAT refund', $html );
		$this->assertStringContainsString( 'Decision log', $html );
		$this->assertStringContainsString( 'revalidation', $html );
		$this->assertStringContainsString( 'Re-check VAT number now', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
	}

	public function test_meta_box_shows_the_role_exemption_with_its_roles() {
		$order    = $this->order( 'FR', 'FR', '75001', array( 'digital' ) );
		$decision = $this->decide( 'FR', 'FR', '75001', array( 'digital' ), false, '', '', '', '', array( 'wholesale_customer' ) );
		DecisionStore::apply( $order, $decision, 'checkout' );
		$order->save();

		$html = $this->html( $this->fresh( $order ) );

		$this->assertStringContainsString( '<strong>VAT-exempt because of the customer role, as set in the VAT Right settings. The legal basis is the merchant&#039;s responsibility.</strong>', $html );
		$this->assertStringContainsString( 'wholesale_customer', $html );
		$this->assertStringContainsString( 'role_exempt', $html );
	}

	public function test_headline_says_could_not_be_checked_until_a_recheck_answers() {
		$order = $this->pending_order( 'charge' );

		$this->assertStringContainsString( '<strong>The VAT number could not be checked: VAT charged until it is.</strong>', $this->html( $this->fresh( $order ) ) );
	}

	public function test_headline_reports_an_invalid_answer_on_an_exempt_order() {
		$order = $this->pending_order( 'exempt_pending' );
		$this->registry_invalid();
		$this->revalidation()->run( $order->get_id(), 1 );

		$this->assertStringContainsString( '<strong>The VAT number was checked later and is not valid, but no VAT was charged.</strong>', $this->html( $this->fresh( $order ) ) );
	}

	public function test_meta_box_works_for_the_post_object_under_cpt_storage() {
		$order = $this->pending_order( 'charge' );
		$post  = get_post( $order->get_id() );

		$this->assertStringContainsString( 'Decision log', $this->html( $post ) );
	}

	public function test_meta_box_says_when_there_is_no_decision() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );

		$this->assertStringContainsString( 'No VAT decision is recorded', $this->html( $order ) );
	}

	public function test_no_recheck_button_without_a_number() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		DecisionStore::apply( $order, $this->decide( 'DE', 'DE', '10115', array( 'digital' ) ), 'checkout' );
		$order->save();

		$this->assertStringNotContainsString( 'Re-check VAT number now', $this->html( $this->fresh( $order ) ) );
	}

	public function test_meta_box_warns_when_the_number_changed_since_the_decision() {
		$order = $this->pending_order( 'charge' );
		$this->assertStringNotContainsString( 'changed since this decision', $this->html( $this->fresh( $order ) ) );

		$order->update_meta_data( DecisionStore::NUMBER_META, 'DE129273398' );
		$order->save();

		$this->assertStringContainsString( 'changed since this decision', $this->html( $this->fresh( $order ) ) );
	}

	public function test_meta_box_is_registered_on_the_order_screen() {
		$this->metabox()->add();

		$screen = wc_get_page_screen_id( 'shop-order' );
		$this->assertArrayHasKey( 'vatright-decision', $GLOBALS['wp_meta_boxes'][ $screen ]['normal']['default'] );
	}

	private function recheck_request( int $order_id, bool $nonce ): void {
		$_GET = array(
			'action'   => MetaBox::ACTION,
			'order_id' => (string) $order_id,
		);
		if ( $nonce ) {
			$_GET['_wpnonce']     = wp_create_nonce( MetaBox::ACTION . $order_id );
			$_REQUEST['_wpnonce'] = $_GET['_wpnonce']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		);
		add_filter(
			'wp_die_handler',
			static function () {
				return static function () {
					throw new RuntimeException( 'die' ); // phpcs:ignore WordPress.Security.EscapeOutput
				};
			}
		);
	}

	public function test_recheck_handler_rechecks_and_returns_to_the_order() {
		$order = $this->pending_order( 'exempt_pending' );
		$this->admin();
		$this->registry_invalid();
		$this->recheck_request( $order->get_id(), true );

		try {
			$this->metabox()->handle_recheck();
			$this->fail( 'Expected a redirect' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'redirect:', $e->getMessage() );
			$this->assertStringContainsString( (string) $order->get_id(), $e->getMessage() );
		}

		$this->assertContains( 'invalid_after_exemption', $this->stored( $order )->flags() );
		$_GET     = array();
		$_REQUEST = array();
	}

	public function test_recheck_handler_rejects_a_missing_nonce() {
		$order = $this->pending_order( 'exempt_pending' );
		$this->admin();
		$this->registry_invalid();
		$this->recheck_request( $order->get_id(), false );

		try {
			$this->metabox()->handle_recheck();
			$this->fail( 'Expected wp_die' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'die', $e->getMessage() );
		}

		$this->assertSame( 0, $this->vies_calls );
		$_GET     = array();
		$_REQUEST = array();
	}

	public function test_recheck_handler_needs_the_capability() {
		$order = $this->pending_order( 'exempt_pending' );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->registry_invalid();
		$this->recheck_request( $order->get_id(), true );

		try {
			$this->metabox()->handle_recheck();
			$this->fail( 'Expected wp_die' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'die', $e->getMessage() );
		}

		$this->assertSame( 0, $this->vies_calls );
		$this->assertNotContains( 'invalid_after_exemption', $this->stored( $order )->flags() );
		$_GET     = array();
		$_REQUEST = array();
	}

	public function test_every_rule_and_flag_has_words() {
		foreach ( ( new \ReflectionClass( \Vatright\Domain\Rule::class ) )->getConstants() as $rule ) {
			$this->assertNotSame( $rule, MetaBox::rule_text( $rule ), "$rule has no wording" );
		}
		foreach ( array( 'validation_pending', 'validated_after_outage', 'invalid_after_exemption', 'evidence_conflict_self_certified', 'single_evidence', 'name_mismatch', 'prefix_mismatch', 'backfilled', 'rate_missing' ) as $flag ) {
			$this->assertNotSame( $flag, MetaBox::flag_text( $flag ), "$flag has no wording" );
		}
		$this->assertInstanceOf( Revalidation::class, $this->revalidation() );
	}
}
