<?php

namespace Vatright\Tests\Integration\Privacy;

use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use Vatright\Migration\LegacyData;
use Vatright\Orders\DecisionStore;
use Vatright\Privacy\Privacy;
use Vatright\Registry\ValidationCache;
use Vatright\Settings;
use WC_Customer;

require_once __DIR__ . '/PrivacyTestCase.php';

class PrivacyTest extends PrivacyTestCase {

	/** @var Privacy */
	private $privacy;

	/** @var ValidationCache */
	private $cache;

	public function set_up() {
		parent::set_up();
		$this->cache   = new ValidationCache( Settings::get() );
		$this->privacy = new Privacy( $this->cache );
	}

	public function test_registers_exporter_and_eraser(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$this->assertArrayHasKey( 'vatright', $exporters );
		$this->assertArrayHasKey( 'vatright', $erasers );
		$this->assertIsCallable( $exporters['vatright']['callback'] );
		$this->assertIsCallable( $erasers['vatright']['callback'] );
	}

	public function test_export_shape(): void {
		$order = $this->vat_order( $this->year() );
		$this->vat_order( $this->year(), 'someone-else@example.com' );

		$result = $this->privacy->export( self::EMAIL );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 2, $result['data'] );

		$record = $result['data'][0];
		$this->assertSame( 'vatright', $record['group_id'] );
		$this->assertSame( 'vatright-order-' . $order->get_id(), $record['item_id'] );
		$values = array_column( $record['data'], 'value', 'name' );
		$this->assertSame( 'DE123456789', $values['VAT number'] );
		$this->assertSame( 'Acme GmbH', $values['Registered name'] );
		$this->assertSame( 'WAPIAAAA', $values['Consultation number'] );
		$this->assertSame( 'DE', $values['Country of IP address'] );
		$this->assertStringContainsString( 'Line 1', $values['Registered address'] );

		$ledger = $result['data'][1];
		$this->assertSame( 'vatright-ledger', $ledger['group_id'] );
		$this->assertSame( 'DE123456789', array_column( $ledger['data'], 'value', 'name' )['VAT number'] );
	}

	public function test_export_finds_orders_by_account(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'account@example.com' ) );
		$order   = $this->vat_order( $this->year(), 'billing-differs@example.com' );
		$order->set_customer_id( $user_id );
		$order->save();

		$this->assertCount( 2, $this->privacy->export( 'account@example.com' )['data'] );
	}

	public function test_export_pages_and_ignores_orders_without_vatright_data(): void {
		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$this->privacy->export( 'nobody@example.com' )
		);
	}

	public function test_eraser_retains_within_window(): void {
		$order = $this->vat_order( $this->year() - 3 );

		$result = $this->privacy->erase( self::EMAIL );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertStringContainsString( 'tax law', $result['messages'][0] );
		$this->assertStringContainsString( (string) ( $this->year() + 8 ), $result['messages'][0] );
		$record = $this->record( $order->get_id() );
		$this->assertSame( 'DE123456789', $record['vat_number']['canonical'] );
		$this->assertSame( 'Acme GmbH', $record['validation']['name'] );
		$this->assertSame( 'DE', $record['evidence']['ip'] );
		$this->assertSame( 'DE123456789', wc_get_order( $order->get_id() )->get_meta( DecisionStore::NUMBER_META ) );
		$this->assertSame( 'DE123456789', $this->ledger_vat( $order->get_id() ) );
	}

	public function test_eraser_retention_runs_to_end_of_year_after_the_period(): void {
		$last_kept    = $this->vat_order( $this->year() - 10 );
		$first_erased = $this->vat_order( $this->year() - 11 );

		$result = $this->privacy->erase( self::EMAIL );

		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['items_removed'] );
		$this->assertNotNull( $this->record( $last_kept->get_id() )['vat_number'] );
		$this->assertNull( $this->record( $first_erased->get_id() )['vat_number'] );
	}

	public function test_eraser_scrubs_outside_window(): void {
		$order = $this->vat_order( $this->year() - 12 );

		$result = $this->privacy->erase( self::EMAIL );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( array(), $result['messages'] );

		$record = $this->record( $order->get_id() );
		$this->assertNull( $record['vat_number'] );
		$this->assertNull( $record['validation']['name'] );
		$this->assertNull( $record['validation']['address'] );
		$this->assertNull( $record['validation']['consultation'] );
		$this->assertNull( $record['evidence']['ip'] );
		$this->assertSame( 'reverse_charge_services', $record['scheme'] );
		$this->assertSame( 'DE', $record['evidence']['billing'] );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( DecisionStore::NUMBER_META ) );
		$this->assertNull( $this->ledger_vat( $order->get_id() ) );
	}

	public function test_eraser_reports_nothing_the_second_time(): void {
		$this->vat_order( $this->year() - 12 );
		$this->privacy->erase( self::EMAIL );

		$result = $this->privacy->erase( self::EMAIL );

		$this->assertFalse( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
	}

	public function test_eraser_leaves_other_customers_alone(): void {
		$other = $this->vat_order( $this->year() - 12, 'someone-else@example.com' );

		$this->privacy->erase( self::EMAIL );

		$this->assertNotNull( $this->record( $other->get_id() )['vat_number'] );
		$this->assertSame( 'DE123456789', $this->ledger_vat( $other->get_id() ) );
	}

	public function test_eraser_clears_validation_cache_even_inside_window(): void {
		update_option( Settings::OPTION, array( 'store_vat_number' => 'MT12345678' ) );
		$this->vat_order( $this->year() );
		$number    = VatNumber::parse( 'DE123456789', '' );
		$requester = VatNumber::parse( 'MT12345678', '' );
		$result    = new ValidationResult( 'valid', 'vies', '2026-10-17T12:00:00Z', 'Acme GmbH' );
		$this->cache->put( $number, null, $result );
		$this->cache->put( $number, $requester, $result );
		$this->assertNotNull( $this->cache->get( $number, null ) );
		$this->assertNotNull( $this->cache->get( $number, $requester ) );

		$this->privacy->erase( self::EMAIL );

		$this->assertNull( $this->cache->get( $number, null ) );
		$this->assertNull( $this->cache->get( $number, $requester ) );
	}

	public function test_export_includes_the_number_saved_on_the_account(): void {
		$user_id = $this->account_with_saved_number();

		$result = $this->privacy->export( 'saved@example.com' );

		$this->assertCount( 1, $result['data'] );
		$this->assertSame( 'vatright-account-' . $user_id, $result['data'][0]['item_id'] );
		$this->assertSame( 'DE123456789', array_column( $result['data'][0]['data'], 'value', 'name' )['Saved VAT number'] );
		$this->assertSame( array(), $this->privacy->export( 'saved@example.com', 2 )['data'], 'The account item is exported once, on the first page' );
	}

	/**
	 * The saved number is a checkout convenience, not a VAT record: the
	 * orders keep their own copy, so the account copy goes even inside the
	 * retention window.
	 */
	public function test_eraser_removes_the_number_saved_on_the_account(): void {
		$user_id = $this->account_with_saved_number();
		$order   = $this->vat_order( $this->year(), 'saved@example.com' );
		$number  = VatNumber::parse( 'DE123456789', '' );
		$this->cache->put( $number, null, new ValidationResult( 'valid', 'vies', '2026-10-17T12:00:00Z', 'Acme GmbH' ) );

		$result = $this->privacy->erase( 'saved@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertSame( '', get_user_meta( $user_id, '_vatright_number', true ) );
		$this->assertSame( '', get_user_meta( $user_id, '_wc_other/vatright/vat-number', true ) );
		$this->assertSame( 'DE123456789', wc_get_order( $order->get_id() )->get_meta( DecisionStore::NUMBER_META ), 'The order keeps its VAT record' );
		$this->assertNull( $this->cache->get( $number, null ) );
	}

	public function test_eraser_removes_numbers_another_plugin_saved_on_the_account(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'legacy@example.com' ) );
		update_user_meta( $user_id, 'vat_number', 'DE123456789' );
		update_user_meta( $user_id, 'billing_eu_vat_number', 'DE123456789' );

		$result = $this->privacy->erase( 'legacy@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( metadata_exists( 'user', $user_id, 'vat_number' ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, 'billing_eu_vat_number' ) );
		$this->assertSame( '', LegacyData::customer_number( new WC_Customer( $user_id ) ) );
	}

	public function test_erased_account_number_does_not_come_back_from_an_older_key(): void {
		$user_id = $this->account_with_saved_number();
		update_user_meta( $user_id, 'billing_vat_number', 'DE123456789' );

		$this->privacy->erase( 'saved@example.com' );

		$this->assertSame( '', LegacyData::customer_number( new WC_Customer( $user_id ) ) );
	}

	public function test_eraser_scrubs_an_order_only_another_plugin_numbered(): void {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		$order->set_billing_email( self::EMAIL );
		$order->set_date_created( strtotime( ( $this->year() - 12 ) . '-06-01 10:00:00 UTC' ) );
		$order->update_meta_data( '_billing_vat_number', 'DE123456789' );
		$order->update_meta_data( '_vat_number', 'DE123456789' );
		$order->save();

		$result = $this->privacy->erase( self::EMAIL );

		$this->assertTrue( $result['items_removed'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->meta_exists( '_billing_vat_number' ) );
		$this->assertFalse( $order->meta_exists( '_vat_number' ) );
		$this->assertSame( '', LegacyData::order_number( $order ) );
	}

	private function account_with_saved_number(): int {
		$user_id = self::factory()->user->create( array( 'user_email' => 'saved@example.com' ) );
		update_user_meta( $user_id, '_vatright_number', 'DE123456789' );
		update_user_meta( $user_id, '_wc_other/vatright/vat-number', 'DE123456789' );
		return $user_id;
	}

	public function test_policy_text_names_registries_and_retention(): void {
		update_option( Settings::OPTION, array( 'retention_years' => 7 ) );

		$text = $this->privacy->policy_text();

		$this->assertStringContainsString( 'VIES', $text );
		$this->assertStringContainsString( 'HMRC', $text );
		$this->assertStringContainsString( '7 years', $text );
		$this->assertStringContainsString( 'cannot be met', $text );
	}

	public function test_eraser_clears_the_cache_entries_of_both_store_numbers(): void {
		update_option(
			Settings::OPTION,
			array(
				'store_vat_number'    => 'MT12345678',
				'store_vat_number_gb' => 'GB980780684',
			)
		);
		$this->vat_order( $this->year() );
		$number = VatNumber::parse( 'DE123456789', '' );
		$eu     = VatNumber::parse( 'MT12345678', '' );
		$gb     = VatNumber::parse( 'GB980780684', '' );
		$result = new ValidationResult( 'valid', 'vies', '2026-10-17T12:00:00Z', 'Acme GmbH' );
		$this->cache->put( $number, $eu, $result );
		$this->cache->put( $number, $gb, $result );

		$this->privacy->erase( self::EMAIL );

		$this->assertNull( $this->cache->get( $number, $eu ) );
		$this->assertNull( $this->cache->get( $number, $gb ) );
	}
}
