<?php

namespace Vatright\Tests\Integration\Ledger;

use Vatright\Domain\Rule;
use Vatright\Domain\Scheme;
use Vatright\Ledger\Schema;
use Vatright\Ledger\ThresholdEmail;
use Vatright\Ledger\ThresholdNotifier;
use Vatright\Ledger\ThresholdTracker;
use Vatright\Settings;

require_once __DIR__ . '/LedgerTestCase.php';

class ThresholdNotifierTest extends LedgerTestCase {

	/** @var array<int,array<string,mixed>> */
	private $mails = array();

	/** @var int */
	private $year;

	public function set_up() {
		parent::set_up();
		$this->mails = array();
		$this->year  = (int) wp_date( 'Y' );
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		delete_option( ThresholdNotifier::OPTION );
		$this->enable_email( 'yes' );
	}

	private function enable_email( string $enabled ): void {
		$this->email()->update_option( 'enabled', $enabled );
		$this->email()->enabled = $enabled;
	}

	private function email(): ThresholdEmail {
		return WC()->mailer()->get_emails()[ ThresholdEmail::KEY ];
	}

	/**
	 * @param mixed               $short_circuit
	 * @param array<string,mixed> $atts
	 */
	public function capture_mail( $short_circuit, $atts ) {
		$this->mails[] = $atts;
		return true;
	}

	private function sale( float $net_eur ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::ledger_table(),
			array(
				'order_id'          => 1,
				'entry_type'        => 'sale',
				'decision_id'       => 'd',
				'scheme'            => Scheme::OSS_DESTINATION,
				'rule'              => Rule::B2C_DESTINATION,
				'supply_type'       => 'goods',
				'customer_country'  => 'DE',
				'place_of_supply'   => 'DE',
				'rate_percent'      => 19,
				'net'               => $net_eur,
				'vat'               => 0,
				'currency'          => 'EUR',
				'eur_rate'          => 1,
				'net_eur'           => $net_eur,
				'vat_eur'           => 0,
				'supply_at'         => $this->year . '-01-01 00:00:00',
				'period'            => $this->year . '-Q2',
				'relates_to_period' => $this->year . '-Q2',
				'created_at'        => $this->year . '-01-01 00:00:00',
			)
		);
		ThresholdTracker::flush( $this->year, $this->year - 1 );
	}

	private function notifier(): ThresholdNotifier {
		return new ThresholdNotifier( new ThresholdTracker() );
	}

	private function sent(): array {
		return (array) get_option( ThresholdNotifier::OPTION, array() );
	}

	public function test_below_the_warning_share_sends_nothing(): void {
		$this->sale( 7900 );
		$this->notifier()->check( $this->year );
		$this->assertSame( array(), $this->mails );
	}

	public function test_default_warning_share_is_eighty_percent(): void {
		$this->assertSame( 80, Settings::get()->threshold_warning_percent() );
	}

	public function test_warning_goes_to_the_admin_once_per_year(): void {
		$this->sale( 8100 );
		$this->notifier()->check( $this->year );
		$this->notifier()->check( $this->year );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( get_option( 'admin_email' ) ), (array) $this->mails[0]['to'] );
		$this->assertStringContainsString( '81%', $this->mails[0]['message'] );
		$this->assertStringContainsString( '8,100.00', $this->mails[0]['message'] );
		$this->assertStringContainsString( '10,000.00', $this->mails[0]['message'] );
		$this->assertArrayHasKey( 'warning', $this->sent()[ $this->year ] );
		$this->assertArrayNotHasKey( 'crossed', $this->sent()[ $this->year ] );
	}

	public function test_crossing_sends_a_second_email_after_the_warning(): void {
		$this->sale( 8100 );
		$this->notifier()->check( $this->year );
		$this->sale( 2000 );
		$this->notifier()->check( $this->year );
		$this->notifier()->check( $this->year );

		$this->assertCount( 2, $this->mails );
		$this->assertNotSame( $this->mails[0]['subject'], $this->mails[1]['subject'] );
		$this->assertStringContainsString( 'crossed', strtolower( $this->mails[1]['subject'] ) );
		$this->assertArrayHasKey( 'crossed', $this->sent()[ $this->year ] );
	}

	public function test_jumping_past_the_threshold_sends_only_the_crossing_email(): void {
		$this->sale( 12000 );
		$this->notifier()->check( $this->year );
		$this->notifier()->check( $this->year );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'crossed', strtolower( $this->mails[0]['subject'] ) );
		$this->assertArrayHasKey( 'warning', $this->sent()[ $this->year ] );
	}

	public function test_a_new_year_starts_again(): void {
		update_option(
			ThresholdNotifier::OPTION,
			array(
				$this->year - 1 => array(
					'warning' => '2025-05-01 00:00:00',
					'crossed' => '2025-06-01 00:00:00',
				),
			)
		);
		$this->sale( 8100 );
		$this->notifier()->check( $this->year );
		$this->assertCount( 1, $this->mails );
	}

	public function test_configured_share_is_used(): void {
		update_option( Settings::OPTION, array( 'threshold_warning_percent' => 50 ) );
		Settings::reset();
		$this->sale( 5100 );
		$this->notifier()->check( $this->year );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( '51%', $this->mails[0]['message'] );
	}

	public function test_oss_registered_stores_get_no_email(): void {
		update_option( Settings::OPTION, array( 'oss_registered' => true ) );
		Settings::reset();
		$this->sale( 12000 );
		$this->notifier()->check( $this->year );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_disabled_email_sends_nothing_and_is_not_marked_sent(): void {
		$this->enable_email( 'no' );
		$this->sale( 8100 );
		$this->notifier()->check( $this->year );

		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->sent() );
	}

	public function test_other_years_are_ignored(): void {
		$this->sale( 8100 );
		$this->notifier()->check( $this->year - 1 );
		$this->assertSame( array(), $this->mails );
	}

	public function test_the_ledger_write_triggers_the_check(): void {
		$this->notifier()->register();
		$rate  = $this->tax_rate( 'DE', '19' );
		$order = $this->make_order(
			array(
				'created' => $this->year . '-03-01 10:00:00',
				'items'   => array(
					array(
						'name'    => 'Big order',
						'net'     => 9000,
						'rate_id' => $rate,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order );
		$this->writer->record_order( $order );

		$subjects = array_filter(
			array_column( $this->mails, 'subject' ),
			static function ( string $subject ): bool {
				return false !== strpos( $subject, 'OSS threshold' );
			}
		);
		$this->assertCount( 1, $subjects );
	}

	public function test_email_is_registered_with_woocommerce(): void {
		$this->notifier()->register();
		$emails = apply_filters( 'woocommerce_email_classes', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$this->assertInstanceOf( ThresholdEmail::class, $emails[ ThresholdEmail::KEY ] );
	}
}
