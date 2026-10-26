<?php

namespace Vatright\Tests\Integration;

use Vatright\Settings;
use Vatright\Tests\Support\IntegrationTestCase;

class SettingsTest extends IntegrationTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
	}

	public function test_defaults(): void {
		$settings = Settings::get();
		$this->assertSame( '', $settings->store_vat_number() );
		$this->assertSame( 'charge', $settings->outage_policy() );
		$this->assertSame( 7, $settings->cache_ttl_valid_days() );
		$this->assertFalse( $settings->allow_domestic_exemption() );
		$this->assertFalse( $settings->oss_registered() );
		$this->assertFalse( $settings->fixed_prices() );
		$this->assertTrue( $settings->self_certification() );
		$this->assertFalse( $settings->self_certification_required() );
		$this->assertTrue( $settings->notify_admin_on_revalidation() );
		$this->assertSame( 10, $settings->retention_years() );
		$this->assertSame( '', $settings->hmrc_client_id() );
		$this->assertSame( '', $settings->hmrc_client_secret() );
		$this->assertSame( 'EUR', $settings->report_currency() );
		$this->assertSame( 'off', $settings->b2b_only() );
		$this->assertSame( array(), $settings->b2b_only_countries() );
		$this->assertFalse( $settings->delete_data_on_uninstall() );
		$this->assertFalse( $settings->registration_vat_field() );
		$this->assertSame( array(), $settings->exempt_roles() );
	}

	public function test_exempt_roles_drops_unknown_and_non_string_entries(): void {
		update_option( Settings::OPTION, array( 'exempt_roles' => array( 'customer', 'no_such_role', 7, '', 'customer' ) ) );
		$this->assertSame( array( 'customer' ), Settings::get()->exempt_roles() );

		update_option( Settings::OPTION, array( 'exempt_roles' => 'customer' ) );
		$this->assertSame( array(), Settings::get()->exempt_roles() );
	}

	public function test_stored_values_override_defaults(): void {
		update_option(
			Settings::OPTION,
			array(
				'outage_policy'   => 'reject',
				'retention_years' => 6,
			)
		);
		$this->assertSame( 'reject', Settings::get()->outage_policy() );
		$this->assertSame( 6, Settings::get()->retention_years() );
		$this->assertSame( 7, Settings::get()->cache_ttl_valid_days() );
	}

	public function test_unknown_outage_policy_falls_back_to_charge(): void {
		update_option( Settings::OPTION, array( 'outage_policy' => 'nonsense' ) );
		$this->assertSame( 'charge', Settings::get()->outage_policy() );
	}

	public function test_unknown_b2b_only_mode_falls_back_to_off(): void {
		update_option( Settings::OPTION, array( 'b2b_only' => 'nonsense' ) );
		$this->assertSame( 'off', Settings::get()->b2b_only() );
	}

	public function test_b2b_only_countries_are_upper_cased_and_deduplicated(): void {
		update_option( Settings::OPTION, array( 'b2b_only_countries' => array( 'de', 'FR', 'DE', '', 'x' ) ) );
		$this->assertSame( array( 'DE', 'FR' ), Settings::get()->b2b_only_countries() );
	}

	public function test_a_customer_gets_the_number_for_their_country(): void {
		update_option(
			Settings::OPTION,
			array(
				'store_vat_number'    => 'MT12345678',
				'store_vat_number_gb' => 'GB980780684',
			)
		);
		$s = Settings::get();

		$this->assertSame( 'GB980780684', $s->store_vat_number_for( 'GB' ) );
		$this->assertSame( 'GB980780684', $s->store_vat_number_for( 'gb' ) );
		$this->assertSame( 'MT12345678', $s->store_vat_number_for( 'DE' ) );
		$this->assertSame( 'MT12345678', $s->store_vat_number_for( '' ) );
		$this->assertTrue( $s->has_separate_store_numbers() );
	}

	public function test_a_store_with_one_number_quotes_it_to_everyone(): void {
		update_option( Settings::OPTION, array( 'store_vat_number' => 'MT12345678' ) );
		$this->assertSame( 'MT12345678', Settings::get()->store_vat_number_for( 'GB' ) );
		$this->assertFalse( Settings::get()->has_separate_store_numbers() );

		update_option( Settings::OPTION, array( 'store_vat_number_gb' => 'GB980780684' ) );
		$this->assertSame( 'GB980780684', Settings::get()->store_vat_number_for( 'DE' ) );
		$this->assertFalse( Settings::get()->has_separate_store_numbers() );
	}

	public function test_the_requesters_are_the_parsed_numbers_eu_first(): void {
		update_option(
			Settings::OPTION,
			array(
				'store_vat_number'    => 'MT12345678',
				'store_vat_number_gb' => 'GB980780684',
			)
		);
		$this->assertSame(
			array( 'MT12345678', 'GB980780684' ),
			array_map(
				static function ( $n ) {
					return $n->canonical();
				},
				Settings::get()->store_requesters()
			)
		);

		update_option( Settings::OPTION, array() );
		$this->assertSame( array(), Settings::get()->store_requesters() );
	}
}
