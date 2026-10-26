<?php

namespace Vatright\Tests\Integration\Admin;

use Vatright\Admin\SettingsSection;
use Vatright\Admin\SetupCheck;
use Vatright\Ledger\Backfill;
use Vatright\Rates\RateSync;
use Vatright\Settings;
use Vatright\Tests\Support\IntegrationTestCase;
use Vatright\Domain\ValidationResult;
use ReflectionProperty;
use WC_Admin_Settings;

class SettingsSectionTest extends IntegrationTestCase {

	/** @var SettingsSection */
	private $section;

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( SettingsSection::JOBS_SCHEDULED );
		update_option( 'woocommerce_default_country', 'MT' );
		foreach ( array( 'errors', 'messages' ) as $property ) {
			$reflection = new ReflectionProperty( WC_Admin_Settings::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( null, array() );
		}
		$this->section = new SettingsSection( new SetupCheck() );
	}

	private function notices(): string {
		ob_start();
		WC_Admin_Settings::show_messages();
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $posted
	 */
	private function save( array $posted ): void {
		WC_Admin_Settings::save_fields( $this->section->fields(), array( Settings::OPTION => $posted ) );
	}

	public function test_section_registered_under_tax(): void {
		$this->assertArrayHasKey( SettingsSection::SECTION, apply_filters( 'woocommerce_get_sections_tax', array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$this->assertNotEmpty( apply_filters( 'woocommerce_get_settings_tax', array(), SettingsSection::SECTION ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$this->assertSame( array( 'x' ), apply_filters( 'woocommerce_get_settings_tax', array( 'x' ), 'standard' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	}

	public function test_defaults_need_no_save(): void {
		$by_key = array();
		foreach ( $this->section->fields() as $field ) {
			if ( isset( $field['id'] ) && 0 === strpos( $field['id'], Settings::OPTION . '[' ) ) {
				$by_key[ substr( $field['id'], strlen( Settings::OPTION ) + 1, -1 ) ] = $field['value'];
			}
		}
		$defaults = Settings::defaults();
		$this->assertEqualsCanonicalizing( array_keys( $defaults ), array_keys( $by_key ) );
		foreach ( $defaults as $key => $default ) {
			$expected = is_bool( $default ) ? ( $default ? 'yes' : 'no' ) : $default;
			$this->assertSame( $expected, $by_key[ $key ], $key );
		}
	}

	public function test_saving_stores_typed_values(): void {
		$this->save(
			array(
				'outage_policy'            => 'reject',
				'cache_ttl_valid_days'     => '30',
				'allow_domestic_exemption' => 'yes',
				'retention_years'          => '8',
				'report_currency'          => 'USD',
			)
		);
		$s = Settings::get();
		$this->assertSame( 'reject', $s->outage_policy() );
		$this->assertSame( 30, $s->cache_ttl_valid_days() );
		$this->assertTrue( $s->allow_domestic_exemption() );
		$this->assertSame( 8, $s->retention_years() );
		$this->assertSame( 'USD', $s->report_currency() );
	}

	public function test_saving_stores_the_registration_field_and_the_exempt_roles(): void {
		$this->save(
			array(
				'registration_vat_field' => 'yes',
				'exempt_roles'           => array( 'customer', 'no_such_role' ),
			)
		);
		$this->assertTrue( Settings::get()->registration_vat_field() );
		$this->assertSame( array( 'customer' ), Settings::get()->exempt_roles() );

		$this->save( array() );
		$this->assertFalse( Settings::get()->registration_vat_field() );
		$this->assertSame( array(), Settings::get()->exempt_roles(), 'Selecting no role clears the list' );
	}

	public function test_exempt_roles_field_lists_roles_and_warns_it_is_not_a_legal_basis(): void {
		update_option( Settings::OPTION, array( 'exempt_roles' => array( 'customer' ) ) );
		Settings::reset();

		ob_start();
		WC_Admin_Settings::output_fields( $this->section->fields() );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<select[^>]+name="vatright_settings\[exempt_roles\]\[\]"[^>]+multiple/s', $html );
		$this->assertMatchesRegularExpression( '/<option value="customer"\s+selected=\'selected\'/', $html );
		$this->assertStringContainsString( 'not a basis for VAT exemption', $html );
		$this->assertStringContainsString( 'responsible', $html );
		$this->assertStringContainsString( 'vatright_settings[registration_vat_field]', $html );
	}

	public function test_hmrc_secret_is_never_rendered(): void {
		$this->save(
			array(
				'hmrc_client_id'     => 'client-abc',
				'hmrc_client_secret' => 'super-secret-value',
			)
		);
		$this->assertSame( 'super-secret-value', Settings::get()->hmrc_client_secret() );

		ob_start();
		WC_Admin_Settings::output_fields( $this->section->fields() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'hmrc_client_secret', $html );
		$this->assertStringNotContainsString( 'super-secret-value', $html );
	}

	public function test_empty_hmrc_secret_keeps_the_saved_one(): void {
		$this->save(
			array(
				'hmrc_client_id'     => 'client-abc',
				'hmrc_client_secret' => 'super-secret-value',
			)
		);
		$this->save(
			array(
				'hmrc_client_id'     => 'client-abc',
				'hmrc_client_secret' => '',
			)
		);
		$this->assertSame( 'super-secret-value', Settings::get()->hmrc_client_secret() );

		$this->save(
			array(
				'hmrc_client_id'     => 'client-abc',
				'hmrc_client_secret' => 'rotated',
			)
		);
		$this->assertSame( 'rotated', Settings::get()->hmrc_client_secret() );
	}

	public function test_clearing_hmrc_client_id_removes_the_secret(): void {
		$this->save(
			array(
				'hmrc_client_id'     => 'client-abc',
				'hmrc_client_secret' => 'super-secret-value',
			)
		);
		$this->save(
			array(
				'hmrc_client_id'     => '',
				'hmrc_client_secret' => '',
			)
		);
		$this->assertSame( '', Settings::get()->hmrc_client_id() );
		$this->assertSame( '', Settings::get()->hmrc_client_secret() );
	}

	public function test_unticked_checkbox_saves_false(): void {
		$this->save( array( 'self_certification' => 'yes' ) );
		$this->assertTrue( Settings::get()->self_certification() );
		$this->save( array() );
		$this->assertFalse( Settings::get()->self_certification() );
	}

	public function test_required_confirmation_saves_and_unticks(): void {
		$this->save( array( 'self_certification_required' => 'yes' ) );
		$this->assertTrue( Settings::get()->self_certification_required() );
		$this->save( array() );
		$this->assertFalse( Settings::get()->self_certification_required() );
	}

	public function test_the_name_mismatch_refusal_is_off_by_default_and_saves(): void {
		$this->assertFalse( Settings::get()->refuse_name_mismatch() );

		$this->save( array( 'refuse_name_mismatch' => 'yes' ) );
		$this->assertTrue( Settings::get()->refuse_name_mismatch() );
		$this->save( array() );
		$this->assertFalse( Settings::get()->refuse_name_mismatch() );
	}

	public function test_the_name_mismatch_checkbox_is_worded_and_explained(): void {
		$field = null;
		foreach ( $this->section->fields() as $candidate ) {
			if ( ( $candidate['id'] ?? '' ) === Settings::OPTION . '[refuse_name_mismatch]' ) {
				$field = $candidate;
			}
		}

		$this->assertNotNull( $field );
		$this->assertSame( 'checkbox', $field['type'] );
		$this->assertSame( 'Refuse the order when the company name doesn\'t match the registry', $field['desc'] );
		$this->assertStringContainsString( 'registered', $field['desc_tip'] );
	}

	public function test_numbers_are_clamped(): void {
		$this->save(
			array(
				'cache_ttl_valid_days' => '0',
				'retention_years'      => '500',
			)
		);
		$this->assertSame( 1, Settings::get()->cache_ttl_valid_days() );
		$this->assertSame( 30, Settings::get()->retention_years() );
	}

	public function test_b2b_only_settings_are_saved(): void {
		$this->save(
			array(
				'b2b_only'           => 'countries',
				'b2b_only_countries' => array( 'DE', 'FR' ),
			)
		);
		$this->assertSame( 'countries', Settings::get()->b2b_only() );
		$this->assertSame( array( 'DE', 'FR' ), Settings::get()->b2b_only_countries() );
	}

	public function test_unknown_b2b_only_mode_is_not_stored(): void {
		$this->save( array( 'b2b_only' => 'nonsense' ) );
		$this->assertSame( 'off', Settings::get()->b2b_only() );
	}

	public function test_b2b_only_help_text_explains_the_outage_policy_interaction(): void {
		ob_start();
		WC_Admin_Settings::output_fields( $this->section->fields() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'vatright_settings[b2b_only]', $html );
		$this->assertStringContainsString( 'vatright_settings[b2b_only_countries][]', $html );
		$this->assertStringContainsString( 'When a registry is unavailable', $html );
		$this->assertStringContainsString( 'never accepted unchecked', $html );
	}

	public function test_threshold_warning_percent_is_a_clamped_number_field(): void {
		$ids = array_column( $this->section->fields(), 'id' );
		$this->assertContains( Settings::OPTION . '[threshold_warning_percent]', $ids );

		$this->save( array( 'threshold_warning_percent' => '65' ) );
		$this->assertSame( 65, Settings::get()->threshold_warning_percent() );

		$this->save( array( 'threshold_warning_percent' => '250' ) );
		$this->assertSame( 99, Settings::get()->threshold_warning_percent() );

		$this->save( array( 'threshold_warning_percent' => '0' ) );
		$this->assertSame( 1, Settings::get()->threshold_warning_percent() );
	}

	public function test_unknown_outage_policy_is_not_stored(): void {
		$this->save( array( 'outage_policy' => 'exempt_pending' ) );
		$this->save( array( 'outage_policy' => 'nonsense' ) );
		$this->assertSame( 'charge', Settings::get()->outage_policy() );
	}

	public function test_store_vat_number_saved_canonical_with_check_result(): void {
		$seen    = array();
		$section = new SettingsSection(
			new SetupCheck(),
			null,
			static function ( $number ) use ( &$seen ) {
				$seen[] = $number->canonical();
				return new ValidationResult( ValidationResult::VALID, 'vies', '2026-10-17T12:00:00Z' );
			}
		);
		remove_all_filters( 'woocommerce_admin_settings_sanitize_option' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$section->register();
		WC_Admin_Settings::save_fields( $section->fields(), array( Settings::OPTION => array( 'store_vat_number' => 'mt 1234-5634' ) ) );
		$this->assertSame( 'MT12345634', Settings::get()->store_vat_number() );
		$this->assertSame( array( 'MT12345634' ), $seen );
		$this->assertStringContainsString( 'MT12345634', $this->notices() );
		$this->assertStringContainsString( 'VIES confirms', $this->notices() );
	}

	public function test_implausible_store_vat_number_keeps_previous_value(): void {
		update_option( Settings::OPTION, array( 'store_vat_number' => 'MT12345634' ) );
		$this->save( array( 'store_vat_number' => 'MT123' ) );
		$this->assertSame( 'MT12345634', Settings::get()->store_vat_number() );
		$this->assertStringContainsString( 'class="error inline"', $this->notices() );
	}

	public function test_setup_check_rendered_at_top(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		ob_start();
		WC_Admin_Settings::output_fields( $this->section->fields() );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertLessThan( strpos( $html, 'vatright_settings[store_vat_number]' ), strpos( $html, 'notice-error' ) );
	}

	public function test_initial_jobs_scheduled_once(): void {
		as_unschedule_all_actions( Backfill::HOOK );
		as_unschedule_all_actions( RateSync::HOOK );
		$this->section->schedule_initial_jobs();
		$this->section->schedule_initial_jobs();
		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => Backfill::HOOK,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			)
		);
		$this->assertTrue( as_has_scheduled_action( RateSync::HOOK ) );
	}

	private function notice_output(): string {
		set_current_screen( 'woocommerce_page_wc-settings' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'woocommerce_calc_taxes', 'no' );
		ob_start();
		$this->section->setup_notice();
		return (string) ob_get_clean();
	}

	public function test_blocking_setup_problem_shows_a_dismissible_notice(): void {
		$this->assertStringContainsString( 'data-issue="taxes_disabled"', $this->notice_output() );
	}

	public function test_setup_notice_stays_off_the_dashboard(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'woocommerce_calc_taxes', 'no' );
		set_current_screen( 'dashboard' );

		ob_start();
		$this->section->setup_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_taxes_off_notice_links_to_the_general_tab_setting(): void {
		$html = $this->notice_output();

		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general#woocommerce_calc_taxes' ) ) . '"', $html );
		$this->assertStringNotContainsString( 'tab=tax', $html );
	}

	public function test_dismissal_is_remembered_for_that_user_and_problem(): void {
		$first = $this->notice_output();
		$this->assertNotSame( '', $first );

		$_POST['issue']       = 'taxes_disabled';
		$_REQUEST['_wpnonce'] = wp_create_nonce( SettingsSection::DISMISS_ACTION );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {};
			}
		);
		ob_start();
		$this->section->dismiss_setup_notice();
		ob_end_clean();

		$this->assertSame( 'taxes_disabled', get_user_meta( get_current_user_id(), SettingsSection::DISMISSED_META, true ) );
		ob_start();
		$this->section->setup_notice();
		$this->assertSame( '', ob_get_clean() );

		update_user_meta( get_current_user_id(), SettingsSection::DISMISSED_META, 'another_problem' );
		ob_start();
		$this->section->setup_notice();
		$this->assertStringContainsString( 'taxes_disabled', (string) ob_get_clean() );

		unset( $_POST['issue'], $_REQUEST['_wpnonce'] );
	}

	public function test_dismissal_without_a_valid_nonce_is_refused(): void {
		$this->notice_output();
		$_POST['issue']       = 'taxes_disabled';
		$_REQUEST['_wpnonce'] = 'bad';
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {
					throw new \RuntimeException( 'refused' );
				};
			}
		);
		$this->expectException( \RuntimeException::class );
		try {
			$this->section->dismiss_setup_notice();
		} finally {
			$this->assertSame( '', get_user_meta( get_current_user_id(), SettingsSection::DISMISSED_META, true ) );
			unset( $_POST['issue'], $_REQUEST['_wpnonce'] );
		}
	}

	public function test_the_uk_store_number_is_saved_canonical_and_clears(): void {
		$this->save( array( 'store_vat_number_gb' => ' gb 980 7806 84 ' ) );
		$this->assertSame( 'GB980780684', Settings::get()->store_vat_number_gb() );

		$this->save( array( 'store_vat_number_gb' => '' ) );
		$this->assertSame( '', Settings::get()->store_vat_number_gb() );
	}

	public function test_a_uk_number_that_is_not_a_gb_number_keeps_the_previous_one(): void {
		$this->save( array( 'store_vat_number_gb' => 'GB980780684' ) );

		foreach ( array( 'DE114103379', 'GB123', 'nonsense' ) as $bad ) {
			$this->save( array( 'store_vat_number_gb' => $bad ) );
			$this->assertSame( 'GB980780684', Settings::get()->store_vat_number_gb(), $bad );
		}
		$this->assertStringContainsString( 'UK store VAT number', $this->notices() );
	}

	public function test_the_uk_store_number_field_explains_itself_beside_the_eu_one(): void {
		$ids = array_column( $this->section->fields(), 'id' );
		$eu  = array_search( Settings::OPTION . '[store_vat_number]', $ids, true );
		$gb  = array_search( Settings::OPTION . '[store_vat_number_gb]', $ids, true );

		$this->assertNotFalse( $gb );
		$this->assertSame( $eu + 1, $gb );
		$field = $this->section->fields()[ $gb ];
		$this->assertSame( 'UK store VAT number', $field['title'] );
		$this->assertStringContainsString( 'HMRC', $field['desc_tip'] );
	}
}
