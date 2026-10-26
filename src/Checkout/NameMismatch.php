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

	public static function refuses( ?Decision $decision ): bool {
		if ( null === $decision || ! Settings::get()->refuse_name_mismatch() ) {
			return false;
		}
		$validation = $decision->to_array()['validation'] ?? null;
		return is_array( $validation )
			&& ValidationResult::VALID === ( $validation['status'] ?? '' )
			&& in_array( $validation['detail'] ?? '', array( NameMatching::MISMATCH, NameMatching::MISMATCH_LOCAL ), true );
	}
}
