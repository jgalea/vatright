<?php

namespace Vatright\Checkout;

use Vatright\Domain\Decision;
use Vatright\Domain\ValidationResult;
use Vatright\Registry\NameMatching;
use Vatright\Settings;

/**
 * The "refuse the order when the company name doesn't match the registry"
 * setting. A mismatch is the registry's own verdict (Spain) or a comparison
 * with the name the registry returned; a registry with neither never produces
 * one, so the order is accepted.
 */
final class NameMismatch {

	const CODE = 'name_mismatch';

	const MISSING_CODE = 'name_missing';

	const MISSING_DETAIL = 'name_missing';

	/**
	 * The company name to compare with the registry, empty when the setting
	 * is off or no name was entered.
	 */
	public static function lookup_name( string $company ): string {
		if ( ! Settings::get()->refuse_name_mismatch() ) {
			return '';
		}
		return trim( (string) preg_replace( '/\s+/u', ' ', $company ) );
	}

	/**
	 * VIES compares names, so a valid number checked with no company name
	 * can't be held to the registered one.
	 */
	public static function requires_name( string $trigger, ValidationResult $validation ): bool {
		return Settings::get()->refuse_name_mismatch()
			&& in_array( $trigger, array( 'cart', 'checkout' ), true )
			&& ValidationResult::VALID === $validation->status()
			&& 'vies' === $validation->registry()
			&& '' === $validation->detail();
	}

	/**
	 * The error code that refuses the order, or null when it stands.
	 */
	public static function refusal( ?Decision $decision ): ?string {
		if ( null === $decision || ! Settings::get()->refuse_name_mismatch() ) {
			return null;
		}
		$validation = $decision->to_array()['validation'] ?? null;
		if ( ! is_array( $validation ) || ValidationResult::VALID !== ( $validation['status'] ?? '' ) ) {
			return null;
		}
		$detail = $validation['detail'] ?? '';
		if ( in_array( $detail, array( NameMatching::MISMATCH, NameMatching::MISMATCH_LOCAL ), true ) ) {
			return self::CODE;
		}
		return self::MISSING_DETAIL === $detail ? self::MISSING_CODE : null;
	}
}
