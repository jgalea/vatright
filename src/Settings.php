<?php

namespace Vatright;

use Vatright\Domain\VatNumber;

class Settings {

	const OPTION = 'vatright_settings';

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

	public function hmrc_client_secret(): string {
		return (string) $this->value( 'hmrc_client_secret' );
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
	 * @return string[] Slugs of existing roles whose orders are VAT-exempt.
	 */
	public function exempt_roles(): array {
		$stored = $this->value( 'exempt_roles' );
		$roles  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $role ) {
			if ( is_string( $role ) && wp_roles()->is_role( $role ) ) {
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
