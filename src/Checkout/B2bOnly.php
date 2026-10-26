<?php

namespace Vatright\Checkout;

use Vatright\Domain\B2bScope;
use Vatright\Domain\Decision;
use Vatright\Domain\ValidationResult;
use Vatright\Settings;

/**
 * The "sell to businesses only" setting. It adds one question to the
 * checkout decision (did the customer give a number the registry confirmed?)
 * and leaves the VAT treatment itself to the rules.
 */
final class B2bOnly {

	public static function applies( string $country ): bool {
		$settings = Settings::get();
		return B2bScope::applies( $settings->b2b_only(), $settings->b2b_only_countries(), $country );
	}

	/**
	 * The code of the error that stops the order, or null when the customer
	 * may order. A registry that failed is left to the outage policy, which
	 * already refuses the order or flags it for a re-check. A decision that
	 * could not be made refuses the order, since the number cannot be vouched
	 * for.
	 */
	public static function error( ?Decision $decision, string $fallback_country = '' ): ?string {
		$record  = null === $decision ? array() : $decision->to_array();
		$country = (string) ( $record['customer']['billing']['country'] ?? $fallback_country );
		if ( ! self::applies( $country ) ) {
			return null;
		}
		if ( null === $decision ) {
			return 'b2b_only_unverified';
		}
		if ( null === $record['vat_number'] ) {
			return 'b2b_only_required';
		}
		switch ( (string) ( $record['validation']['status'] ?? ValidationResult::UNCHECKED ) ) {
			case ValidationResult::VALID:
			case ValidationResult::UNAVAILABLE:
				return null;
			case ValidationResult::INVALID:
			case ValidationResult::FORMAT_INVALID:
				return 'invalid_vat_number';
			default:
				return 'b2b_only_unverified';
		}
	}

	public static function billing_country(): string {
		return strtoupper( WC()->customer->get_billing_country() );
	}

	/**
	 * @param string $code One of the codes error() returns, or registry_unavailable.
	 */
	public static function message( string $code, string $country ): string {
		switch ( $code ) {
			case 'b2b_only_required':
				$message = __( 'This shop sells to businesses only. Enter a valid VAT number to place your order.', 'vatright' );
				break;
			case 'invalid_vat_number':
				$message = __( 'This VAT number is not valid, and this shop sells to businesses only. Correct the number to place your order.', 'vatright' );
				break;
			default:
				$message = __( 'This shop sells to businesses only, and we could not check your VAT number with the tax authority just now. Check the number or try again in a few minutes.', 'vatright' );
		}
		/**
		 * Filters the message shown when business-only mode stops an order.
		 *
		 * @param string $message The default message.
		 * @param string $code    b2b_only_required, invalid_vat_number, b2b_only_unverified or registry_unavailable.
		 * @param string $country The customer's billing country code.
		 */
		return (string) apply_filters( 'vatright_b2b_only_message', $message, $code, $country );
	}
}
