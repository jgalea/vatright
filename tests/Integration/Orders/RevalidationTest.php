<?php

namespace Vatright\Tests\Integration\Orders;

use Vatright\Orders\DecisionStore;
use Vatright\Orders\Revalidation;
use Vatright\Registry\ValidationCache;
use Vatright\Settings;

require_once __DIR__ . '/LifecycleTestCase.php';
require_once dirname( __DIR__, 2 ) . '/Support/WcsStandIn.php';

class RevalidationTest extends LifecycleTestCase {

	public function test_pending_validates_later_and_emails_admin() {
		$order = $this->pending_order( 'charge' );
		$this->assertContains( 'validation_pending', $this->stored( $order )->flags() );
		$total = $this->fresh( $order )->get_total();
		$this->assertGreaterThan( 0, (float) $this->fresh( $order )->get_total_tax(), 'VAT was charged while the registry was down' );

		$this->registry_valid();
		$this->revalidation()->run( $order->get_id(), 1 );

		$decision = $this->stored( $order );
		$this->assertContains( 'validated_after_outage', $decision->flags() );
		$this->assertNotContains( 'validation_pending', $decision->flags() );
		$this->assertFalse( $decision->is_vat_exempt(), 'A paid order is never re-taxed automatically' );
		$this->assertSame( $total, $this->fresh( $order )->get_total() );
		$this->assertSame( 'valid', $decision->to_array()['validation']['status'] );
		$this->assertStringContainsString( 'refund', $this->notes( $order ) );

		$log = DecisionStore::log( $this->fresh( $order ) );
		$this->assertCount( 2, $log );
		$this->assertSame( 'revalidation', $log[1]['trigger'] );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( get_option( 'admin_email' ) ), (array) $this->mails[0]['to'] );
		$this->assertStringContainsString( (string) $order->get_order_number(), $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'refund', $this->mails[0]['message'] );
		$this->assertStringContainsString( self::NUMBER, $this->mails[0]['message'] );
	}

	public function test_email_is_skipped_when_the_setting_is_off() {
		update_option( Settings::OPTION, array( 'notify_admin_on_revalidation' => false ) );
		Settings::reset();
		$order = $this->pending_order( 'charge' );

		$this->registry_valid();
		$this->revalidation()->run( $order->get_id(), 1 );

		$this->assertContains( 'validated_after_outage', $this->stored( $order )->flags(), 'The order is still marked' );
		$this->assertSame( array(), $this->mails );
	}

	public function test_retry_schedule_stops_after_last_attempt() {
		$order = $this->pending_order( 'charge' );
		$id    = $order->get_id();
		$this->registry_down();

		$expected = array(
			2 => HOUR_IN_SECONDS,
			3 => 6 * HOUR_IN_SECONDS,
			4 => DAY_IN_SECONDS,
			5 => 3 * DAY_IN_SECONDS,
		);
		$revalid  = $this->revalidation();
		for ( $attempt = 1; $attempt <= 4; $attempt++ ) {
			delete_transient( ValidationCache::PREFIX . md5( self::NUMBER . '|' ) );
			$revalid->run( $id, $attempt );
			$next = as_get_scheduled_actions(
				array(
					'hook'   => Revalidation::HOOK,
					'args'   => array( $id, $attempt + 1 ),
					'status' => 'pending',
				)
			);
			$this->assertCount( 1, $next, "Attempt $attempt schedules attempt " . ( $attempt + 1 ) );
			$when = reset( $next )->get_schedule()->get_date()->getTimestamp();
			$this->assertEqualsWithDelta( time() + $expected[ $attempt + 1 ], $when, 5 );
		}

		delete_transient( ValidationCache::PREFIX . md5( self::NUMBER . '|' ) );
		$revalid->run( $id, 5 );
		$this->assertSame(
			array(),
			as_get_scheduled_actions(
				array(
					'hook'   => Revalidation::HOOK,
					'args'   => array( $id, 6 ),
					'status' => 'pending',
				)
			)
		);
		$this->assertStringContainsString( 'repeated attempts', $this->notes( $order ) );
		$this->assertContains( 'validation_pending', $this->stored( $order )->flags() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_exempt_pending_invalid_flags_and_emails() {
		$order = $this->pending_order( 'exempt_pending' );
		$this->assertTrue( $this->stored( $order )->is_vat_exempt() );
		$this->assertSame( 0.0, (float) $this->fresh( $order )->get_total_tax() );

		$this->registry_invalid();
		$this->revalidation()->run( $order->get_id(), 1 );

		$decision = $this->stored( $order );
		$this->assertContains( 'invalid_after_exemption', $decision->flags() );
		$this->assertNotContains( 'validation_pending', $decision->flags() );
		$this->assertTrue( $decision->is_vat_exempt(), 'The totals are left for the merchant to decide' );
		$this->assertSame( 0.0, (float) $this->fresh( $order )->get_total_tax() );
		$this->assertStringContainsString( 'invalid', $this->notes( $order ) );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'not charged', $this->mails[0]['message'] );
		$this->assertStringContainsString( (string) $order->get_order_number(), $this->mails[0]['subject'] );
	}

	public function test_exempt_pending_valid_confirms_without_email() {
		$order = $this->pending_order( 'exempt_pending' );

		$this->registry_valid();
		$this->revalidation()->run( $order->get_id(), 1 );

		$decision = $this->stored( $order );
		$this->assertSame( array(), $decision->flags() );
		$this->assertTrue( $decision->is_vat_exempt() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_charged_order_with_invalid_number_is_not_flagged() {
		$order = $this->pending_order( 'charge' );

		$this->registry_invalid();
		$this->revalidation()->run( $order->get_id(), 1 );

		$flags = $this->stored( $order )->flags();
		$this->assertNotContains( 'invalid_after_exemption', $flags, 'VAT was charged, nothing is wrong' );
		$this->assertNotContains( 'validation_pending', $flags );
		$this->assertSame( array(), $this->mails );
	}

	public function test_schedule_only_for_pending_orders_and_only_once() {
		$pending = $this->pending_order( 'charge' );
		$settled = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		DecisionStore::apply( $settled, $this->decide( 'DE', 'DE', '10115', array( 'digital' ), false, self::NUMBER, 'valid' ), 'checkout' );
		$settled->save();

		$revalid = $this->revalidation();
		$revalid->schedule( $pending );
		$revalid->schedule( $pending );
		$revalid->schedule( $settled );

		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => Revalidation::HOOK,
					'args'   => array( $pending->get_id(), 1 ),
					'status' => 'pending',
				)
			)
		);
		$this->assertSame(
			array(),
			as_get_scheduled_actions(
				array(
					'hook'   => Revalidation::HOOK,
					'args'   => array( $settled->get_id(), 1 ),
					'status' => 'pending',
				)
			)
		);
	}

	public function test_a_settled_order_is_not_checked_again() {
		$order = $this->pending_order( 'charge' );
		$this->registry_valid();
		$revalid = $this->revalidation();
		$revalid->run( $order->get_id(), 1 );
		$calls = $this->vies_calls;

		$revalid->run( $order->get_id(), 2 );

		$this->assertSame( $calls, $this->vies_calls );
		$this->assertCount( 1, $this->mails );
	}

	public function test_held_emails_go_out_only_when_the_batch_asks_for_them() {
		$order = $this->pending_order( 'exempt_pending' );
		$this->registry_invalid();
		$revalid = $this->revalidation();

		$this->assertSame( Revalidation::INVALID, $revalid->recheck( $order, 'admin', 5, true ) );
		$this->assertSame( array(), $this->mails, 'Nothing is sent while the batch is still running' );

		$revalid->send_held_emails();
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'not charged', $this->mails[0]['message'] );

		$revalid->send_held_emails();
		$this->assertCount( 1, $this->mails, 'A held email is sent once' );
	}

	public function test_recheck_bypasses_the_validation_cache() {
		$order = $this->pending_order( 'exempt_pending' );
		$this->registry_valid();
		$revalid = $this->revalidation();
		$this->assertSame( Revalidation::VALID, $revalid->recheck( $order, 'admin' ) );

		$this->registry_invalid();
		$this->assertSame( Revalidation::INVALID, $revalid->recheck( $this->fresh( $order ), 'admin', 5 ), 'A cached valid answer must not hide a changed registry' );

		$this->assertContains( 'invalid_after_exemption', $this->stored( $order )->flags() );
		$log = DecisionStore::log( $this->fresh( $order ) );
		$this->assertSame( 'admin', end( $log )['trigger'] );
		$this->assertSame( 5, end( $log )['actor_user_id'] );
	}

	public function test_unavailable_registry_never_throws_past_the_job() {
		$order = $this->pending_order( 'charge' );
		$this->assertSame( Revalidation::UNAVAILABLE, $this->revalidation()->recheck( $order, 'admin' ), 'Nothing is stubbed, so the transport throws' );
		$this->assertContains( 'validation_pending', $this->stored( $order )->flags() );
	}

	public function test_pending_renewal_orders_are_scheduled_and_settled_like_checkout_orders() {
		$this->assertTrue( class_exists( 'WC_Subscriptions' ), 'The Subscriptions stand-in is loaded' );
		$renewal = $this->pending_order( 'charge' );
		$sub     = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );

		$this->revalidation()->register();

		$this->assertSame( $renewal, apply_filters( 'wcs_renewal_order_created', $renewal, $sub ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing Subscriptions' hook.

		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => Revalidation::HOOK,
					'args'   => array( $renewal->get_id(), 1 ),
					'status' => 'pending',
				)
			)
		);

		$this->registry_valid();
		$this->revalidation()->run( $renewal->get_id(), 1 );
		$this->assertContains( 'validated_after_outage', $this->stored( $renewal )->flags() );
		$this->assertCount( 1, $this->mails );
	}
}
