<?php

namespace Vatright;

use Vatright\Domain\VatNumber;

class Settings {

	const OPTION = 'vatright_settings';

	const SECRET_OPTION = 'vatright_hmrc_secret';

	const NON_EU_REGISTRATIONS = array( 'GB', 'NO', 'CH' );

	/** @var Settings|null */
	private static $instance = null;

	public static function get(): Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'store_vat_number'             => '',
			'store_vat_number_gb'          => '',
			'outage_policy'                => 'charge',
			'b2b_only'                     => 'off',
			'b2b_only_countries'           => array(),
			'cache_ttl_valid_days'         => 7,
			'allow_domestic_exemption'     => false,
			'oss_registered'               => false,
			'threshold_warning_percent'    => 80,
			'fixed_prices'                 => false,
			'self_certification'           => true,
			'self_certification_required'  => false,
			'notify_admin_on_revalidation' => true,
			'retention_years'              => 10,
			'hmrc_client_id'               => '',
			'hmrc_client_secret'           => '',
			'report_currency'              => 'EUR',
			'delete_data_on_uninstall'     => false,
			'registration_vat_field'       => false,
			'exempt_roles'                 => array(),
			'non_eu_registrations'         => array(),
			'blocked_vat_territories'      => array(),
			'refuse_name_mismatch'         => false,
			'write_compat_keys'            => true,
		);
	}

	public function store_vat_number(): string {
		return (string) $this->value( 'store_vat_number' );
	}

	public function store_vat_number_gb(): string {
		return (string) $this->value( 'store_vat_number_gb' );
	}

	/**
	 * The number the store quotes to a customer of this country: the UK
	 * number for a UK customer, the EU number for everyone else. A store with
	 * only one of the two quotes it to all.
	 */
	public function store_vat_number_for( string $country ): string {
		$eu = $this->store_vat_number();
		$gb = $this->store_vat_number_gb();
		if ( 'GB' === strtoupper( $country ) ) {
			return '' !== $gb ? $gb : $eu;
		}
		return '' !== $eu ? $eu : $gb;
	}

	public function has_separate_store_numbers(): bool {
		return '' !== $this->store_vat_number() && '' !== $this->store_vat_number_gb();
	}

	/**
	 * The store's numbers as parsed VAT numbers, EU first, for registries to
	 * take as the requester.
	 *
	 * @return VatNumber[]
	 */
	public function store_requesters(): array {
		$numbers = array();
		foreach ( array( $this->store_vat_number(), $this->store_vat_number_gb() ) as $raw ) {
			$parsed = VatNumber::parse( $raw, '' );
			if ( null !== $parsed ) {
				$numbers[] = $parsed;
			}
		}
		return $numbers;
	}

	public function outage_policy(): string {
		$policy = (string) $this->value( 'outage_policy' );
		return in_array( $policy, array( 'charge', 'exempt_pending', 'reject' ), true ) ? $policy : 'charge';
	}

	public function b2b_only(): string {
		$mode = (string) $this->value( 'b2b_only' );
		return in_array( $mode, Domain\B2bScope::MODES, true ) ? $mode : 'off';
	}

	/**
	 * @return string[]
	 */
	public function b2b_only_countries(): array {
		$countries = array();
		foreach ( (array) $this->value( 'b2b_only_countries' ) as $country ) {
			$country = strtoupper( trim( (string) $country ) );
			if ( 1 === preg_match( '/^[A-Z]{2}$/', $country ) ) {
				$countries[ $country ] = $country;
			}
		}
		return array_values( $countries );
	}

	public function cache_ttl_valid_days(): int {
		return (int) $this->value( 'cache_ttl_valid_days' );
	}

	public function allow_domestic_exemption(): bool {
		return (bool) $this->value( 'allow_domestic_exemption' );
	}

	public function oss_registered(): bool {
		return (bool) $this->value( 'oss_registered' );
	}

	public function threshold_warning_percent(): int {
		return max( 1, min( 99, (int) $this->value( 'threshold_warning_percent' ) ) );
	}

	public function fixed_prices(): bool {
		return (bool) $this->value( 'fixed_prices' );
	}

	public function self_certification(): bool {
		return (bool) $this->value( 'self_certification' );
	}

	public function self_certification_required(): bool {
		return (bool) $this->value( 'self_certification_required' );
	}

	public function notify_admin_on_revalidation(): bool {
		return (bool) $this->value( 'notify_admin_on_revalidation' );
	}

	public function retention_years(): int {
		return (int) $this->value( 'retention_years' );
	}

	public function hmrc_client_id(): string {
		return (string) $this->value( 'hmrc_client_id' );
	}

	public static function move_secret_out_of_settings(): void {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored['hmrc_client_secret'] ) ) {
			return;
		}
		if ( false === get_option( self::SECRET_OPTION, false ) ) {
			update_option( self::SECRET_OPTION, (string) $stored['hmrc_client_secret'], false );
		}
		$stored['hmrc_client_secret'] = '';
		update_option( self::OPTION, $stored );
		self::reset();
	}

	public function hmrc_client_secret(): string {
		$own = get_option( self::SECRET_OPTION, false );
		return false !== $own ? (string) $own : (string) $this->value( 'hmrc_client_secret' );
	}

	public function report_currency(): string {
		return (string) $this->value( 'report_currency' );
	}

	public function delete_data_on_uninstall(): bool {
		return (bool) $this->value( 'delete_data_on_uninstall' );
	}

	public function registration_vat_field(): bool {
		return (bool) $this->value( 'registration_vat_field' );
	}

	public function refuse_name_mismatch(): bool {
		return (bool) $this->value( 'refuse_name_mismatch' );
	}

	public function write_compat_keys(): bool {
		return (bool) $this->value( 'write_compat_keys' );
	}

	/**
	 * @return string[] Countries VAT-chargeable products can't be sold into.
	 */
	public function blocked_vat_territories(): array {
		$countries = array();
		foreach ( (array) $this->value( 'blocked_vat_territories' ) as $country ) {
			$country = strtoupper( trim( (string) $country ) );
			if ( 1 === preg_match( '/^[A-Z]{2}$/', $country ) ) {
				$countries[ $country ] = $country;
			}
		}
		return array_values( $countries );
	}

	/**
	 * @return string[] Non-EU countries the store is registered for VAT in.
	 */
	public function non_eu_registrations(): array {
		return array_values( array_intersect( self::NON_EU_REGISTRATIONS, array_map( 'strtoupper', array_map( 'strval', (array) $this->value( 'non_eu_registrations' ) ) ) ) );
	}

	/**
	 * @return string[] Every role a visitor can get by signing up: the default
	 *                  role while WordPress or network registration is open, and
	 *                  the role WooCommerce gives new customers while My account
	 *                  or checkout registration is on.
	 */
	public static function self_registered_roles(): array {
		$roles = array();
		if ( get_option( 'users_can_register' ) || ( is_multisite() && 'none' !== get_site_option( 'registration', 'none' ) ) ) {
			$roles[] = (string) get_option( 'default_role', '' );
		}
		if ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) || 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) ) {
			$customer = (array) apply_filters( 'woocommerce_new_customer_data', array( 'role' => 'customer' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
			$roles[]  = (string) ( $customer['role'] ?? 'customer' );
		}
		return array_values( array_unique( array_filter( $roles ) ) );
	}

	/**
	 * @return string[] Slugs of existing roles whose orders are VAT-exempt. Roles
	 *                  a visitor can get by signing up are left out, whenever
	 *                  they were stored, since anyone could then hold them.
	 */
	public function exempt_roles(): array {
		$stored = $this->value( 'exempt_roles' );
		$roles  = array();
		$signup = self::self_registered_roles();
		foreach ( is_array( $stored ) ? $stored : array() as $role ) {
			if ( is_string( $role ) && ! in_array( $role, $signup, true ) && wp_roles()->is_role( $role ) ) {
				$roles[ $role ] = $role;
			}
		}
		return array_values( $roles );
	}

	/**
	 * @return mixed
	 */
	private function value( string $key ) {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
			return $stored[ $key ];
		}
		return $defaults[ $key ];
	}
}
