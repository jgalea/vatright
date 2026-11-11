<?php

namespace Vatright\Admin;

use Vatright\Checkout\TaxLocation;
use Vatright\Domain\Territory;
use Vatright\Domain\VatNumber;
use Vatright\Settings;
use WC_Tax;

class SetupCheck {

	const ERROR = 'error';

	const WARNING = 'warning';

	const INFO = 'info';

	const VIES_TRANSIENT = 'vatright_vies_status';

	/** @var callable|null */
	private $vies_status;

	/**
	 * @param callable|null $vies_status Returns country code => availability, as Vies::status() does.
	 */
	public function __construct( ?callable $vies_status = null ) {
		$this->vies_status = $vies_status;
	}

	/**
	 * @return array<int,array{id:string,severity:string,message:string,fix_url:string}>
	 */
	public function issues( bool $include_registry_status = true ): array {
		$issues = array();
		$tax    = admin_url( 'admin.php?page=wc-settings&tab=tax' );

		if ( ! wc_tax_enabled() ) {
			$issues[] = $this->issue( 'taxes_disabled', self::ERROR, __( 'Taxes are turned off in WooCommerce, so no VAT is calculated.', 'vatright' ), admin_url( 'admin.php?page=wc-settings&tab=general#woocommerce_calc_taxes' ) );
		}

		if ( 'base' === get_option( 'woocommerce_tax_based_on' ) ) {
			$issues[] = $this->issue( 'tax_based_on_shop_base', self::ERROR, __( 'Tax is calculated on the shop base address. Cross-border VAT needs "Customer shipping address" or "Customer billing address".', 'vatright' ), $tax );
		}

		if ( ! Territory::is_eu_member( WC()->countries->get_base_country() ) ) {
			$issues[] = $this->issue( 'base_country_outside_eu', self::WARNING, __( 'The store base country is outside the EU. This version is built for EU-established stores.', 'vatright' ), admin_url( 'admin.php?page=wc-settings&tab=general' ) );
		}

		$elsewhere = $this->store_number_country_elsewhere();
		if ( null !== $elsewhere && ! Settings::get()->oss_registered() ) {
			$issues[] = $this->issue(
				'threshold_may_not_apply',
				self::WARNING,
				sprintf(
					/* translators: 1: country code of the store VAT number, 2: store base country code. */
					__( 'Your store VAT number is from %1$s but the store is based in %2$s. If your business is established in more than one member state, or ships goods from one other than %2$s, the EUR 10,000 threshold does not apply to you (VAT Directive Art. 59c) and every cross-border sale to consumers is taxed where the customer is. Turn on the OSS setting.', 'vatright' ),
					$elsewhere,
					WC()->countries->get_base_country()
				),
				SettingsSection::url()
			);
		}

		if ( wc_prices_include_tax() && ! $this->fixed_prices_chosen() ) {
			$issues[] = $this->issue( 'fixed_prices_undecided', self::WARNING, __( 'Prices are entered with tax, but you have not chosen whether customers pay the same price in every EU country.', 'vatright' ), SettingsSection::url() );
		}

		$missing = $this->countries_without_standard_rate();
		if ( $missing ) {
			$issues[] = $this->issue(
				'standard_rates_missing',
				self::WARNING,
				sprintf(
					/* translators: %s: comma-separated country codes. */
					__( 'No standard-rate tax row for: %s.', 'vatright' ),
					implode( ', ', $missing )
				),
				SettingsSection::url()
			);
		}

		$fallen_back = $this->rates_charged_at_origin();
		if ( $fallen_back ) {
			$issues[] = $this->issue(
				'destination_rates_missing',
				self::ERROR,
				sprintf(
					/* translators: %s: comma-separated country codes, with the tax class in brackets when not standard. */
					__( 'Orders needed a VAT rate your tax tables do not have, so your own country\'s rate was charged instead: %s. Add the missing rates.', 'vatright' ),
					implode( ', ', $fallen_back )
				),
				admin_url( 'admin.php?page=wc-settings&tab=tax' )
			);
		}

		$no_rows = array_diff( Settings::get()->non_eu_registrations(), $this->countries_with_rows() );
		if ( $no_rows ) {
			$issues[] = $this->issue(
				'non_eu_rates_missing',
				self::ERROR,
				sprintf(
					/* translators: %s: comma-separated country codes. */
					__( 'The store is marked as registered for VAT in %s, but your tax tables have no rate for it, so sales there are charged no VAT. Add the rate.', 'vatright' ),
					implode( ', ', $no_rows )
				),
				$tax
			);
		}

		$ignored = $this->non_eu_rows_not_registered();
		if ( $ignored ) {
			$issues[] = $this->issue(
				'non_eu_rates_not_registered',
				self::WARNING,
				sprintf(
					/* translators: %s: comma-separated country codes. */
					__( 'Your tax tables have rates for %s, but the store is not marked as registered for VAT there, so sales to these countries are charged no VAT. Mark the store as registered under Tax treatment if you are.', 'vatright' ),
					implode( ', ', $ignored )
				),
				SettingsSection::url()
			);
		}

		if ( $this->has_wildcard_rate() ) {
			$issues[] = $this->issue( 'wildcard_rate_taxes_exports', self::ERROR, __( 'A tax rate applies to every country (*). It would charge VAT on sales outside the EU.', 'vatright' ), admin_url( 'admin.php?page=wc-settings&tab=tax&section=standard' ) );
		}

		if ( '' === Settings::get()->store_vat_number() ) {
			$issues[] = $this->issue( 'store_vat_number_missing', self::WARNING, __( 'Your store VAT number is not set, so VIES cannot give you consultation numbers as proof of validation.', 'vatright' ), SettingsSection::url() );
		}

		if ( ! in_array( get_option( 'woocommerce_default_customer_address' ), array( 'geolocation', 'geolocation_ajax' ), true ) ) {
			$issues[] = $this->issue( 'geolocation_disabled', self::WARNING, __( 'Geolocation is off, so digital sales have one piece of location evidence fewer.', 'vatright' ), admin_url( 'admin.php?page=wc-settings&tab=general' ) );
		}

		$settings = Settings::get();
		if ( $settings->self_certification() && ! $settings->self_certification_required() ) {
			$issues[] = $this->issue( 'self_certification_optional', self::INFO, __( 'Customers whose location evidence conflicts are asked to confirm their country, but checkout does not wait for the answer. Turn on "Require confirmation" to hold the order until they do.', 'vatright' ), SettingsSection::url() );
		}

		$down = $include_registry_status ? $this->vies_unavailable_countries() : array();
		if ( $down ) {
			$issues[] = $this->issue(
				'vies_outage',
				self::WARNING,
				sprintf(
					/* translators: %s: comma-separated country codes. */
					__( 'VIES reports these member states as unavailable right now: %s.', 'vatright' ),
					implode( ', ', $down )
				),
				SettingsSection::url()
			);
		}

		if ( '' === Settings::get()->hmrc_client_id() || '' === Settings::get()->hmrc_client_secret() ) {
			$issues[] = $this->issue( 'hmrc_credentials_missing', self::INFO, __( 'No HMRC credentials, so GB VAT numbers are format-checked only. This never changes the VAT charged.', 'vatright' ), SettingsSection::url() );
		}

		return $issues;
	}

	/**
	 * @return array{id:string,severity:string,message:string,fix_url:string}
	 */
	private function issue( string $id, string $severity, string $message, string $fix_url ): array {
		return array(
			'id'       => $id,
			'severity' => $severity,
			'message'  => $message,
			'fix_url'  => $fix_url,
		);
	}

	/**
	 * The member state of the store VAT number when it isn't the base country:
	 * a sign the business may be established in more than one member state,
	 * which Art. 59c(1)(a) and (c) of the VAT Directive require for the
	 * threshold to apply.
	 */
	private function store_number_country_elsewhere(): ?string {
		$base   = WC()->countries->get_base_country();
		$number = VatNumber::parse( Settings::get()->store_vat_number(), $base );
		if ( null === $number || 'XI' === $number->prefix() || ! $number->is_eu() || ! Territory::is_eu_member( $base ) ) {
			return null;
		}
		return $number->country() === $base ? null : $number->country();
	}

	private function fixed_prices_chosen(): bool {
		$stored = get_option( Settings::OPTION );
		return is_array( $stored ) && array_key_exists( 'fixed_prices', $stored );
	}

	/**
	 * @return array<int,string>
	 */
	private function countries_without_standard_rate(): array {
		$covered = array();
		foreach ( (array) WC_Tax::get_rates_for_tax_class( '' ) as $rate ) {
			if ( '' === $rate->tax_rate_state && ! $rate->postcode_count && ! $rate->city_count ) {
				$covered[ $rate->tax_rate_country ] = true;
			}
		}
		return array_values( array_filter( Territory::EU, static fn( $iso ) => ! isset( $covered[ $iso ] ) ) );
	}

	/**
	 * Countries (and tax classes) checkout had to tax at the store's own rate,
	 * still without any rate row of their own.
	 *
	 * @return array<int,string>
	 */
	private function rates_charged_at_origin(): array {
		$seen   = get_option( TaxLocation::MISSING_OPTION, array() );
		$labels = array();
		foreach ( is_array( $seen ) ? array_keys( $seen ) : array() as $key ) {
			list( $country, $class ) = array_pad( explode( '|', (string) $key, 2 ), 2, '' );
			$has_row                 = false;
			foreach ( (array) WC_Tax::get_rates_for_tax_class( $class ) as $rate ) {
				$has_row = $has_row || $country === $rate->tax_rate_country;
			}
			if ( ! $has_row ) {
				$labels[] = '' === $class ? $country : $country . ' (' . $class . ')';
			}
		}
		return $labels;
	}

	/**
	 * @return array<int,string>
	 */
	private function non_eu_rows_not_registered(): array {
		$unregistered = array_diff( Settings::NON_EU_REGISTRATIONS, Settings::get()->non_eu_registrations() );
		return array_values( array_intersect( $unregistered, $this->countries_with_rows() ) );
	}

	/**
	 * @return array<int,string>
	 */
	private function countries_with_rows(): array {
		$with_rows = array();
		foreach ( array_merge( array( '' ), WC_Tax::get_tax_class_slugs() ) as $class ) {
			foreach ( (array) WC_Tax::get_rates_for_tax_class( $class ) as $rate ) {
				$with_rows[ $rate->tax_rate_country ] = true;
			}
		}
		return array_keys( $with_rows );
	}

	private function has_wildcard_rate(): bool {
		foreach ( array_merge( array( '' ), WC_Tax::get_tax_class_slugs() ) as $class ) {
			foreach ( (array) WC_Tax::get_rates_for_tax_class( $class ) as $rate ) {
				if ( '' === $rate->tax_rate_country && (float) $rate->tax_rate > 0 ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @return array<int,string>
	 */
	private function vies_unavailable_countries(): array {
		if ( null === $this->vies_status ) {
			return array();
		}
		$status = get_transient( self::VIES_TRANSIENT );
		if ( ! is_array( $status ) ) {
			$status = ( $this->vies_status )();
			if ( $status ) {
				set_transient( self::VIES_TRANSIENT, $status, 5 * MINUTE_IN_SECONDS );
			}
		}
		$down = array();
		foreach ( $status as $country => $availability ) {
			if ( 'available' !== strtolower( (string) $availability ) ) {
				$down[] = (string) $country;
			}
		}
		return $down;
	}
}
