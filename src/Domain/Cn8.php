<?php

namespace Vatright\Domain;

final class Cn8 {

	/**
	 * The eight digits of a Combined Nomenclature code, or null when the text
	 * is anything else.
	 */
	public static function normalise( string $raw ): ?string {
		$digits = preg_replace( '/[\s.]+/', '', $raw );
		return is_string( $digits ) && 1 === preg_match( '/^\d{8}$/', $digits ) ? $digits : null;
	}
}
