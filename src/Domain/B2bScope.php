<?php

namespace Vatright\Domain;

final class B2bScope {

	const OFF        = 'off';
	const EVERYWHERE = 'everywhere';
	const EU         = 'eu';
	const COUNTRIES  = 'countries';

	const MODES = array( self::OFF, self::EVERYWHERE, self::EU, self::COUNTRIES );

	/**
	 * Whether a customer billing from $country must give a valid VAT number.
	 *
	 * @param string[] $countries Used by the 'countries' mode only.
	 */
	public static function applies( string $mode, array $countries, string $country ): bool {
		$country = strtoupper( $country );
		switch ( $mode ) {
			case self::EVERYWHERE:
				return true;
			case self::EU:
				return Territory::is_eu_member( $country );
			case self::COUNTRIES:
				return '' !== $country && in_array( $country, array_map( 'strtoupper', $countries ), true );
			default:
				return false;
		}
	}
}
