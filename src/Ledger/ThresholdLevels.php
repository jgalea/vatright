<?php

namespace Vatright\Ledger;

final class ThresholdLevels {

	const WARNING = 'warning';
	const CROSSED = 'crossed';

	/**
	 * @param string[] $sent Levels already emailed this year.
	 */
	public static function due( float $sum, float $threshold, int $warning_percent, array $sent ): ?string {
		if ( $sum > $threshold ) {
			return in_array( self::CROSSED, $sent, true ) ? null : self::CROSSED;
		}
		if ( $sum >= $threshold * $warning_percent / 100 && ! in_array( self::WARNING, $sent, true ) ) {
			return self::WARNING;
		}
		return null;
	}

	/**
	 * @return string[]
	 */
	public static function covered( string $level ): array {
		return self::CROSSED === $level ? array( self::WARNING, self::CROSSED ) : array( self::WARNING );
	}
}
