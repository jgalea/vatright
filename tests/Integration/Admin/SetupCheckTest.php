<?php

namespace Vatright\Tests\Integration\Admin;

use Vatright\Admin\SetupCheck;
use Vatright\Domain\Territory;
use Vatright\Settings;
use Vatright\Tests\Support\IntegrationTestCase;
use WC_Tax;

class SetupCheckTest extends IntegrationTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" ); // phpcs:ignore WordPress.DB
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_default_country', 'MT' );
		update_option( 'woocommerce_tax_based_on', 'shipping' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_default_customer_address', 'geolocation' );
		update_option(
			Settings::OPTION,
			array(
				'store_vat_number'            => 'MT12345634',
				'hmrc_client_id'              => 'id',
				'hmrc_client_secret'          => 'secret',
				'self_certification_required' => true,
			)
		);
		foreach ( Territory::EU as $iso ) {
			$this->insert_rate( $iso, 20 );
		}
	}

	private function insert_rate( string $country, float $rate ): void {
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => $country,
				'tax_rate'         => (string) $rate,
				'tax_rate_name'    => 'VAT',
				'tax_rate_class'   => '',
			)
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function ids( ?callable $vies = null ): array {
		delete_transient( SetupCheck::VIES_TRANSIENT );
		return array_column( ( new SetupCheck( $vies ) )->issues(), 'id' );
	}

	public function test_clean_store_has_no_issues(): void {
		$this->assertSame( array(), $this->ids() );
	}

	public function test_unrequired_self_certification_is_an_info_notice(): void {
		update_option(
			Settings::OPTION,
			array(
				'store_vat_number'            => 'MT12345634',
				'hmrc_client_id'              => 'id',
				'hmrc_client_secret'          => 'secret',
				'self_certification_required' => false,
			)
		);
		$this->assertContains( 'self_certification_optional', $this->ids() );
	}

	public function test_non_eu_rates_without_a_registration_are_reported(): void {
		$this->insert_rate( 'GB', 20 );
		$this->assertContains( 'non_eu_rates_not_registered', $this->ids() );

		update_option(
			Settings::OPTION,
			array(
				'store_vat_number'            => 'MT12345634',
				'hmrc_client_id'              => 'id',
				'hmrc_client_secret'          => 'secret',
				'self_certification_required' => true,
				'non_eu_registrations'        => array( 'GB' ),
			)
		);
		$this->assertNotContains( 'non_eu_rates_not_registered', $this->ids() );
	}

	public function test_a_registered_country_without_a_rate_row_is_an_error_until_a_rate_exists(): void {
		update_option(
			Settings::OPTION,
			array(
				'store_vat_number'     => 'MT12345634',
				'non_eu_registrations' => array( 'GB' ),
			)
		);
		$issue = array_values( array_filter( ( new SetupCheck() )->issues( false ), static fn( $i ) => 'non_eu_rates_missing' === $i['id'] ) );
		$this->assertCount( 1, $issue );
		$this->assertSame( SetupCheck::ERROR, $issue[0]['severity'] );

		$this->insert_rate( 'GB', 20 );
		$this->assertNotContains( 'non_eu_rates_missing', $this->ids() );
	}

	public function test_taxes_disabled(): void {
		update_option( 'woocommerce_calc_taxes', 'no' );
		$this->assertContains( 'taxes_disabled', $this->ids() );
	}

	public function test_tax_based_on_shop_base(): void {
		update_option( 'woocommerce_tax_based_on', 'base' );
		$this->assertContains( 'tax_based_on_shop_base', $this->ids() );
	}

	public function test_base_country_outside_eu(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		$this->assertContains( 'base_country_outside_eu', $this->ids() );
	}

	public function test_fixed_prices_undecided_only_when_prices_include_tax(): void {
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		$this->assertContains( 'fixed_prices_undecided', $this->ids() );

		update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION ), array( 'fixed_prices' => true ) ) );
		$this->assertNotContains( 'fixed_prices_undecided', $this->ids() );
	}

	public function test_standard_rate_missing_names_the_country(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'woocommerce_tax_rates', array( 'tax_rate_country' => 'DE' ) ); // phpcs:ignore WordPress.DB
		$issues = ( new SetupCheck() )->issues();
		$this->assertSame( array( 'standard_rates_missing' ), array_column( $issues, 'id' ) );
		$this->assertStringContainsString( 'DE', $issues[0]['message'] );
		$this->assertStringNotContainsString( 'FR', $issues[0]['message'] );
	}

	public function test_state_specific_row_does_not_count_as_standard_rate(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'woocommerce_tax_rates', array( 'tax_rate_country' => 'ES' ) ); // phpcs:ignore WordPress.DB
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'ES',
				'tax_rate_state'   => 'CE',
				'tax_rate'         => '0',
				'tax_rate_name'    => 'VAT',
				'tax_rate_class'   => '',
			)
		);
		$this->assertContains( 'standard_rates_missing', $this->ids() );
	}

	public function test_wildcard_rate_detected(): void {
		$this->insert_rate( '', 20 );
		$this->assertContains( 'wildcard_rate_taxes_exports', $this->ids() );
	}

	public function test_zero_wildcard_rate_is_not_an_issue(): void {
		$this->insert_rate( '', 0 );
		$this->assertNotContains( 'wildcard_rate_taxes_exports', $this->ids() );
	}

	public function test_store_vat_number_missing(): void {
		update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION ), array( 'store_vat_number' => '' ) ) );
		$this->assertContains( 'store_vat_number_missing', $this->ids() );
	}

	/**
	 * KG-4: the threshold is only for suppliers established in one member
	 * state. A store number from another member state than the base country
	 * is the sign the setup check can see.
	 */
	public function test_store_number_from_another_member_state_warns_threshold_may_not_apply(): void {
		$this->assertNotContains( 'threshold_may_not_apply', $this->ids(), 'Control: a Maltese number for a Maltese store' );

		update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION ), array( 'store_vat_number' => 'DE114103379' ) ) );
		$issues = array_column( ( new SetupCheck() )->issues(), null, 'id' );
		$this->assertArrayHasKey( 'threshold_may_not_apply', $issues );
		$this->assertSame( SetupCheck::WARNING, $issues['threshold_may_not_apply']['severity'] );
		$this->assertStringContainsString( 'DE', $issues['threshold_may_not_apply']['message'] );
		$this->assertStringContainsString( 'MT', $issues['threshold_may_not_apply']['message'] );

		update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION ), array( 'oss_registered' => true ) ) );
		$this->assertNotContains( 'threshold_may_not_apply', $this->ids(), 'Registered for OSS: the threshold no longer matters' );
	}

	public function test_greek_store_with_el_number_is_not_warned(): void {
		update_option( 'woocommerce_default_country', 'GR' );
		update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION ), array( 'store_vat_number' => 'EL094259216' ) ) );
		$this->assertNotContains( 'threshold_may_not_apply', $this->ids() );
	}

	public function test_geolocation_disabled(): void {
		update_option( 'woocommerce_default_customer_address', 'base' );
		$this->assertContains( 'geolocation_disabled', $this->ids() );
	}

	public function test_vies_outage_lists_unavailable_countries_only(): void {
		$issues = ( new SetupCheck(
			fn() => array(
				'DE' => 'Available',
				'IT' => 'Unavailable',
			)
		) )->issues();
		$this->assertSame( array( 'vies_outage' ), array_column( $issues, 'id' ) );
		$this->assertStringContainsString( 'IT', $issues[0]['message'] );
		$this->assertStringNotContainsString( 'DE', $issues[0]['message'] );
	}

	public function test_vies_status_is_cached_between_checks(): void {
		delete_transient( SetupCheck::VIES_TRANSIENT );
		$calls = 0;
		$check = new SetupCheck(
			static function () use ( &$calls ) {
				++$calls;
				return array( 'DE' => 'Available' );
			}
		);
		$check->issues();
		$check->issues();
		$this->assertSame( 1, $calls );
	}

	public function test_registry_status_skipped_when_not_requested(): void {
		delete_transient( SetupCheck::VIES_TRANSIENT );
		$calls = 0;
		$check = new SetupCheck(
			static function () use ( &$calls ) {
				++$calls;
				return array();
			}
		);
		$check->issues( false );
		$this->assertSame( 0, $calls );
	}

	public function test_all_available_vies_is_not_an_issue(): void {
		$this->assertSame( array(), $this->ids( fn() => array( 'DE' => 'Available' ) ) );
	}

	public function test_hmrc_credentials_absent_is_info_only(): void {
		update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION ), array( 'hmrc_client_id' => '' ) ) );
		$issues = ( new SetupCheck() )->issues();
		$this->assertSame( array( 'hmrc_credentials_missing' ), array_column( $issues, 'id' ) );
		$this->assertSame( SetupCheck::INFO, $issues[0]['severity'] );
	}

	public function test_every_issue_has_a_fix_url(): void {
		update_option( 'woocommerce_calc_taxes', 'no' );
		update_option( 'woocommerce_default_customer_address', 'base' );
		foreach ( ( new SetupCheck() )->issues() as $issue ) {
			$this->assertStringStartsWith( admin_url(), $issue['fix_url'] );
		}
	}
}
