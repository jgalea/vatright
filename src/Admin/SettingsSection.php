<?php

namespace Vatright\Admin;

use Vatright\Checkout\VatField;
use Vatright\Domain\B2bScope;
use Vatright\Domain\FormatCheck;
use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use Vatright\Ledger\Backfill;
use Vatright\Rates\RateSync;
use Vatright\Settings;
use WC_Admin_Settings;

class SettingsSection {

	const SECTION = 'vatright';

	const JOBS_SCHEDULED = 'vatright_jobs_scheduled';

	const AS_GROUP = 'vatright';

	const DISMISS_ACTION = 'vatright_dismiss_setup_notice';

	const DISMISSED_META = 'vatright_dismissed_setup_notice';

	/** @var SetupCheck */
	private $setup_check;

	/** @var callable|null */
	private $rates_renderer;

	/** @var callable|null */
	private $store_number_check;

	/** @var RateMappingEditor|null */
	private $mapping_editor;

	/** @var string|null The client ID in the form being saved, which comes before the secret. */
	private $posted_client_id = null;

	/**
	 * @param callable|null $rates_renderer     Prints the rates screen below the settings form.
	 * @param callable|null $store_number_check Receives the parsed store VatNumber, returns its ValidationResult or null when no registry covers it.
	 */
	public function __construct( SetupCheck $setup_check, ?callable $rates_renderer = null, ?callable $store_number_check = null, ?RateMappingEditor $mapping_editor = null ) {
		$this->setup_check        = $setup_check;
		$this->rates_renderer     = $rates_renderer;
		$this->store_number_check = $store_number_check;
		$this->mapping_editor     = $mapping_editor;
	}

	public function register(): void {
		add_filter( 'woocommerce_get_sections_tax', array( $this, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_tax', array( $this, 'settings_for_section' ), 10, 2 );
		add_filter( 'woocommerce_admin_settings_sanitize_option', array( $this, 'sanitize' ), 10, 2 );
		add_action( 'woocommerce_admin_field_vatright_setup_check', array( $this, 'render_setup_check' ) );
		add_action( 'woocommerce_after_settings_tax', array( $this, 'render_rates' ) );
		if ( null !== $this->mapping_editor ) {
			add_action( 'woocommerce_admin_field_vatright_rate_mapping', array( $this->mapping_editor, 'render' ) );
			add_action( 'woocommerce_update_options_tax_' . self::SECTION, array( $this->mapping_editor, 'save' ) );
		}
		add_action( 'admin_notices', array( $this, 'setup_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_script' ) );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( $this, 'dismiss_setup_notice' ) );
		add_action( 'admin_init', array( $this, 'schedule_initial_jobs' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=tax&section=' . self::SECTION );
	}

	/**
	 * @param array<string,string> $sections
	 * @return array<string,string>
	 */
	public function add_section( $sections ): array {
		$sections[ self::SECTION ] = __( 'VAT Right', 'vatright' );
		return $sections;
	}

	/**
	 * @param mixed  $settings
	 * @param string $current_section
	 * @return mixed
	 */
	public function settings_for_section( $settings, $current_section ) {
		if ( self::SECTION !== $current_section ) {
			return $settings;
		}
		return $this->fields();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function fields(): array {
		$s = Settings::get();

		$mapping = null === $this->mapping_editor ? array() : array(
			array(
				'title' => __( 'Tax class mapping', 'vatright' ),
				'desc'  => __( 'Choose which EU rate each tax class follows. For reduced rates, pick the rate for each country from the EU list. Rates are only applied when you review and apply them in the rates table below.', 'vatright' ),
				'type'  => 'title',
				'id'    => 'vatright_mapping',
			),
			array(
				'type'      => 'vatright_rate_mapping',
				'id'        => 'vatright_rate_mapping',
				'is_option' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'vatright_mapping',
			),
		);

		return array_merge(
			$this->main_fields( $s ),
			$mapping,
			$this->data_fields( $s )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function main_fields( Settings $s ): array {
		return array(
			array(
				'type'      => 'vatright_setup_check',
				'id'        => 'vatright_setup_check',
				'is_option' => false,
			),
			array(
				'title' => __( 'Store', 'vatright' ),
				'type'  => 'title',
				'id'    => 'vatright_store',
			),
			$this->field(
				'store_vat_number',
				'text',
				__( 'Store VAT number', 'vatright' ),
				$s->store_vat_number(),
				array( 'desc_tip' => __( 'Sent to VIES as the requester, which returns a consultation number as proof of each check.', 'vatright' ) )
			),
			$this->field(
				'store_vat_number_gb',
				'text',
				__( 'UK store VAT number', 'vatright' ),
				$s->store_vat_number_gb(),
				array( 'desc_tip' => __( 'Optional. Shown on orders from UK customers instead of the number above, and sent to HMRC as the requester. Leave it empty to use one number everywhere.', 'vatright' ) )
			),
			$this->field(
				'report_currency',
				'select',
				__( 'Report currency', 'vatright' ),
				$s->report_currency(),
				array( 'options' => get_woocommerce_currencies() )
			),
			array(
				'type' => 'sectionend',
				'id'   => 'vatright_store',
			),
			array(
				'title' => __( 'VAT number validation', 'vatright' ),
				'type'  => 'title',
				'id'    => 'vatright_validation',
			),
			$this->field(
				'outage_policy',
				'select',
				__( 'When a registry is unavailable', 'vatright' ),
				$s->outage_policy(),
				array(
					'options'  => array(
						'charge'         => __( 'Accept the order, charge VAT and flag it', 'vatright' ),
						'exempt_pending' => __( 'Accept the order, exempt VAT and re-check later', 'vatright' ),
						'reject'         => __( 'Do not accept the order', 'vatright' ),
					),
					'desc_tip' => __( 'Charging VAT and flagging the order is the safe choice: you can refund the VAT once the number validates. Exempting while a registry is down lets anyone enter a made-up number for that country, and the VAT is yours to recover if the re-check fails.', 'vatright' ),
				)
			),
			$this->field(
				'b2b_only',
				'select',
				__( 'Sell to businesses only', 'vatright' ),
				$s->b2b_only(),
				array(
					'options' => array(
						B2bScope::OFF        => __( 'Off: a VAT number is optional', 'vatright' ),
						B2bScope::EVERYWHERE => __( 'Require a valid VAT number from every customer', 'vatright' ),
						B2bScope::EU         => __( 'Require a valid VAT number from customers billing in the EU', 'vatright' ),
						B2bScope::COUNTRIES  => __( 'Require a valid VAT number from customers billing in the countries chosen below', 'vatright' ),
					),
					'desc'    => __( 'The customer has to enter a VAT number that its registry confirms before the order is accepted, on the block and the classic checkout. A registry outage follows "When a registry is unavailable" above: "Do not accept the order" refuses the order, while the two other choices accept it with VAT charged or exempted and flagged for a re-check. A number the registry has not checked, because no registry covers its country or the lookup was turned away for load, is never accepted unchecked.', 'vatright' ),
				)
			),
			$this->field(
				'b2b_only_countries',
				'multiselect',
				__( 'Business-only countries', 'vatright' ),
				$s->b2b_only_countries(),
				array(
					'options'  => WC()->countries->get_countries(),
					'class'    => 'wc-enhanced-select',
					'css'      => 'min-width: 350px;',
					'desc_tip' => __( 'Used when the setting above is "chosen countries". The country is the customer\'s billing country.', 'vatright' ),
				)
			),
			$this->field(
				'cache_ttl_valid_days',
				'number',
				__( 'Keep valid results for (days)', 'vatright' ),
				$s->cache_ttl_valid_days(),
				array(
					'css'               => 'width: 80px;',
					'custom_attributes' => array(
						'min' => '1',
						'max' => '90',
					),
				)
			),
			$this->checkbox( 'self_certification', __( 'Self-certification', 'vatright' ), __( 'Ask digital customers to confirm their country when the evidence conflicts', 'vatright' ), $s->self_certification() ),
			$this->checkbox( 'self_certification_required', __( 'Require confirmation', 'vatright' ), __( 'Block checkout until the customer confirms their country (needs Self-certification)', 'vatright' ), $s->self_certification_required() ),
			$this->checkbox( 'allow_domestic_exemption', __( 'Domestic B2B', 'vatright' ), __( 'Allow VAT exemption for valid numbers from the store country', 'vatright' ), $s->allow_domestic_exemption() ),
			$this->field(
				'refuse_name_mismatch',
				'checkbox',
				__( 'Company name', 'vatright' ),
				$s->refuse_name_mismatch() ? 'yes' : 'no',
				array(
					'desc'     => __( 'Refuse the order when the company name doesn\'t match the registry', 'vatright' ),
					'desc_tip' => __( 'The company name from the billing address is sent to VIES with the VAT number, and the customer is asked to use the name registered for that number. Spain\'s registry judges the name itself; for other countries the name is compared with the one VIES returns, ignoring case, accents, punctuation and legal forms such as GmbH or S.A. When the registry returns no name, or no company name is entered, the order is accepted.', 'vatright' ),
				)
			),
			$this->checkbox( 'registration_vat_field', __( 'Registration form', 'vatright' ), __( 'Add an optional VAT number field to the My Account registration form', 'vatright' ), $s->registration_vat_field() ),
			$this->checkbox( 'notify_admin_on_revalidation', __( 'Revalidation emails', 'vatright' ), __( 'Email the admin when a number validates after the order was placed', 'vatright' ), $s->notify_admin_on_revalidation() ),
			array(
				'type' => 'sectionend',
				'id'   => 'vatright_validation',
			),
			array(
				'title' => __( 'Tax treatment', 'vatright' ),
				'type'  => 'title',
				'id'    => 'vatright_treatment',
			),
			$this->checkbox( 'oss_registered', __( 'OSS', 'vatright' ), __( 'I am registered for the One Stop Shop (always charge destination VAT)', 'vatright' ), $s->oss_registered() ),
			$this->field(
				'threshold_warning_percent',
				'number',
				__( 'OSS threshold warning (%)', 'vatright' ),
				$s->threshold_warning_percent(),
				array(
					'css'               => 'width: 80px;',
					'desc_tip'          => __( 'Email the store admin when cross-border EU consumer sales reach this share of the EUR 10,000 threshold. A second email goes out when they pass it. Each email is sent once a year. Edit or turn them off under WooCommerce > Settings > Emails.', 'vatright' ),
					'custom_attributes' => array(
						'min' => '1',
						'max' => '99',
					),
				)
			),
			$this->checkbox( 'fixed_prices', __( 'Fixed prices', 'vatright' ), __( 'Keep gross prices the same in every EU country (prices entered with tax)', 'vatright' ), $s->fixed_prices() ),
			$this->field(
				'exempt_roles',
				'multiselect',
				__( 'VAT-exempt roles', 'vatright' ),
				$s->exempt_roles(),
				array(
					'options' => self::role_options(),
					'class'   => 'wc-enhanced-select',
					'css'     => 'min-width: 300px;',
					'desc'    => __( 'Orders from customers with these roles are VAT-exempt. A user role is not a basis for VAT exemption under EU VAT law on its own, so you are responsible for making sure each exemption is correct, for example that these accounts are checked VAT-registered businesses. The role is recorded on every order it exempts. Leave empty to exempt nobody.', 'vatright' ),
				)
			),
			array(
				'type' => 'sectionend',
				'id'   => 'vatright_treatment',
			),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function data_fields( Settings $s ): array {
		return array(
			array(
				'title' => __( 'HMRC (optional)', 'vatright' ),
				'desc'  => __( 'Credentials from your own HMRC Developer Hub application. Without them GB numbers are format-checked only.', 'vatright' ),
				'type'  => 'title',
				'id'    => 'vatright_hmrc',
			),
			$this->field( 'hmrc_client_id', 'text', __( 'Client ID', 'vatright' ), $s->hmrc_client_id() ),
			$this->field(
				'hmrc_client_secret',
				'password',
				__( 'Client secret', 'vatright' ),
				'',
				array(
					'placeholder'       => '' === $s->hmrc_client_secret() ? '' : __( 'Saved. Leave empty to keep it.', 'vatright' ),
					'desc_tip'          => __( 'The saved secret is never shown. Clear the client ID to remove it.', 'vatright' ),
					'custom_attributes' => array( 'autocomplete' => 'new-password' ),
				)
			),
			array(
				'type' => 'sectionend',
				'id'   => 'vatright_hmrc',
			),
			array(
				'title' => __( 'Data', 'vatright' ),
				'type'  => 'title',
				'id'    => 'vatright_data',
			),
			$this->field(
				'retention_years',
				'number',
				__( 'Keep VAT records for (years)', 'vatright' ),
				$s->retention_years(),
				array(
					'css'               => 'width: 80px;',
					'custom_attributes' => array(
						'min' => '1',
						'max' => '30',
					),
				)
			),
			$this->checkbox( 'delete_data_on_uninstall', __( 'Uninstall', 'vatright' ), __( 'Delete all plugin data when the plugin is deleted', 'vatright' ), $s->delete_data_on_uninstall() ),
			array(
				'type' => 'sectionend',
				'id'   => 'vatright_data',
			),
		);
	}

	/**
	 * @param mixed                $value
	 * @param array<string,mixed> $option
	 * @return mixed
	 */
	public function sanitize( $value, $option ) {
		$id = isset( $option['id'] ) ? (string) $option['id'] : '';
		if ( 0 !== strpos( $id, Settings::OPTION . '[' ) ) {
			return $value;
		}
		$key = substr( $id, strlen( Settings::OPTION ) + 1, -1 );

		if ( 'checkbox' === $option['type'] ) {
			return 'yes' === $value;
		}
		if ( 'number' === $option['type'] ) {
			$attributes = $option['custom_attributes'];
			return max( (int) $attributes['min'], min( (int) $attributes['max'], (int) $value ) );
		}
		if ( 'b2b_only' === $key ) {
			return in_array( $value, B2bScope::MODES, true ) ? $value : B2bScope::OFF;
		}
		if ( 'b2b_only_countries' === $key ) {
			return $this->sanitize_countries( $value );
		}
		if ( 'exempt_roles' === $key ) {
			return array_values(
				array_filter(
					(array) $value,
					static function ( $role ): bool {
						return is_string( $role ) && wp_roles()->is_role( $role );
					}
				)
			);
		}
		if ( 'store_vat_number' === $key ) {
			return $this->sanitize_store_vat_number( (string) $value );
		}
		if ( 'store_vat_number_gb' === $key ) {
			return $this->sanitize_uk_store_vat_number( (string) $value );
		}
		if ( 'hmrc_client_id' === $key ) {
			$this->posted_client_id = (string) $value;
		}
		if ( 'hmrc_client_secret' === $key ) {
			return $this->sanitize_hmrc_secret( (string) $value );
		}
		return $value;
	}

	public function render_setup_check(): void {
		$issues = $this->setup_check->issues();
		echo '<h2>' . esc_html__( 'Setup check', 'vatright' ) . '</h2>';
		if ( ! $issues ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Nothing to fix.', 'vatright' ) . '</p></div>';
			return;
		}
		foreach ( $issues as $issue ) {
			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
				esc_attr( self::notice_class( $issue['severity'] ) ),
				esc_html( $issue['message'] ),
				esc_url( $issue['fix_url'] ),
				esc_html__( 'Fix', 'vatright' )
			);
		}
	}

	public function render_rates(): void {
		global $current_section;
		if ( self::SECTION !== $current_section || null === $this->rates_renderer ) {
			return;
		}
		echo '<div class="vatright-rates">';
		( $this->rates_renderer )();
		echo '</div>';
	}

	public function setup_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
			return;
		}
		$on_section = isset( $_GET['section'] ) && self::SECTION === $_GET['section']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		if ( $on_section ) {
			return;
		}
		$blocking = array_filter(
			$this->setup_check->issues( false ),
			static function ( $issue ) {
				return SetupCheck::ERROR === $issue['severity'];
			}
		);
		if ( ! $blocking ) {
			return;
		}
		$first = reset( $blocking );
		if ( get_user_meta( get_current_user_id(), self::DISMISSED_META, true ) === $first['id'] ) {
			return;
		}
		// WooCommerce hides the Tax tab while taxes are off, so the VAT Right section can't be reached yet.
		$taxes_off = 'taxes_disabled' === $first['id'];
		printf(
			'<div class="notice notice-error is-dismissible vatright-setup-notice" data-issue="%1$s"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
			esc_attr( $first['id'] ),
			esc_html( $first['message'] ),
			esc_url( $taxes_off ? $first['fix_url'] : self::url() ),
			$taxes_off ? esc_html__( 'Turn on "Enable tax rates and calculations"', 'vatright' ) : esc_html__( 'Open the VAT Right setup check', 'vatright' )
		);
	}

	public function enqueue_script(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return;
		}
		$style = VatField::asset( 'assets/admin.css' );
		wp_enqueue_style( 'vatright-admin', $style['url'], array(), $style['version'] );
		$script = VatField::asset( 'assets/admin.js' );
		wp_enqueue_script( 'vatright-admin', $script['url'], array(), $script['version'], true );
		wp_localize_script(
			'vatright-admin',
			'vatrightAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::DISMISS_ACTION,
				'nonce'   => wp_create_nonce( self::DISMISS_ACTION ),
			)
		);
	}

	/**
	 * Remembers, per user, which setup problem they dismissed. A different
	 * problem shows the notice again.
	 */
	public function dismiss_setup_notice(): void {
		check_ajax_referer( self::DISMISS_ACTION );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( null, 403 );
		}
		$issue = isset( $_POST['issue'] ) ? sanitize_key( wp_unslash( $_POST['issue'] ) ) : '';
		update_user_meta( get_current_user_id(), self::DISMISSED_META, $issue );
		wp_send_json_success();
	}

	public function schedule_initial_jobs(): void {
		if ( get_option( self::JOBS_SCHEDULED ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		Backfill::schedule();
		if ( ! as_has_scheduled_action( RateSync::HOOK, array(), self::AS_GROUP ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, WEEK_IN_SECONDS, RateSync::HOOK, array(), self::AS_GROUP );
		}
		update_option( self::JOBS_SCHEDULED, 1, false );
	}

	/**
	 * @return array<string,string>
	 */
	private static function role_options(): array {
		$options = array();
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$options[ $slug ] = translate_user_role( $name );
		}
		return $options;
	}

	private static function notice_class( string $severity ): string {
		return SetupCheck::ERROR === $severity ? 'error' : ( SetupCheck::WARNING === $severity ? 'warning' : 'info' );
	}

	/**
	 * @param mixed $value
	 * @return string[]
	 */
	private function sanitize_countries( $value ): array {
		$known = array_keys( WC()->countries->get_countries() );
		return array_values( array_unique( array_intersect( array_map( 'strtoupper', array_map( 'strval', (array) $value ) ), $known ) ) );
	}

	private function sanitize_uk_store_vat_number( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$parsed = VatNumber::parse( $raw, 'GB' );
		if ( null === $parsed || 'GB' !== $parsed->prefix() || ! FormatCheck::is_plausible( $parsed ) ) {
			WC_Admin_Settings::add_error( __( 'The UK store VAT number does not look like a GB number, so it was not saved.', 'vatright' ) );
			return Settings::get()->store_vat_number_gb();
		}
		return $parsed->canonical();
	}

	private function sanitize_store_vat_number( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$parsed = VatNumber::parse( $raw, WC()->countries->get_base_country() );
		if ( null === $parsed || ! FormatCheck::is_plausible( $parsed ) ) {
			WC_Admin_Settings::add_error( __( 'The store VAT number does not look valid, so it was not saved.', 'vatright' ) );
			return Settings::get()->store_vat_number();
		}
		$result  = null === $this->store_number_check ? null : ( $this->store_number_check )( $parsed );
		$message = $result instanceof ValidationResult ? $this->registry_message( $result ) : '';
		WC_Admin_Settings::add_message(
			trim(
				sprintf(
					/* translators: 1: canonical VAT number, 2: registry result. */
					__( 'Store VAT number saved as %1$s. %2$s', 'vatright' ),
					$parsed->canonical(),
					$message
				)
			)
		);
		return $parsed->canonical();
	}

	/**
	 * The form never carries the saved secret, so an empty field keeps it.
	 * Clearing the client ID removes both.
	 */
	private function sanitize_hmrc_secret( string $value ): string {
		$client_id              = $this->posted_client_id;
		$this->posted_client_id = null;
		if ( '' !== $value ) {
			return $value;
		}
		if ( null !== $client_id && '' === trim( $client_id ) ) {
			return '';
		}
		return Settings::get()->hmrc_client_secret();
	}

	private function registry_message( ValidationResult $result ): string {
		switch ( $result->status() ) {
			case ValidationResult::VALID:
				return __( 'VIES confirms the number is registered.', 'vatright' );
			case ValidationResult::INVALID:
				return __( 'VIES does not know this number. Check it before you rely on it.', 'vatright' );
			case ValidationResult::UNAVAILABLE:
				return __( 'VIES could not be reached, so the number was not checked.', 'vatright' );
			default:
				return '';
		}
	}

	/**
	 * @param string               $key
	 * @param string               $type
	 * @param string               $title
	 * @param mixed                $value
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function field( string $key, string $type, string $title, $value, array $extra = array() ): array {
		return array_merge(
			array(
				'title'   => $title,
				'type'    => $type,
				'id'      => Settings::OPTION . '[' . $key . ']',
				'value'   => $value,
				'default' => Settings::defaults()[ $key ],
			),
			$extra
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function checkbox( string $key, string $title, string $label, bool $checked ): array {
		return $this->field( $key, 'checkbox', $title, $checked ? 'yes' : 'no', array( 'desc' => $label ) );
	}
}
