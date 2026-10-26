<?php

namespace Vatright\Ledger;

use Vatright\Domain\Line;
use Vatright\Domain\Scheme;
use Vatright\Settings;

class ThresholdTracker {

	const THRESHOLD_EUR = 10000.00;
	const TRANSIENT     = 'vatright_threshold_';

	/** @var array<int,float> */
	private static $sums = array();

	public function status( int $year ): ThresholdStatus {
		$threshold = $this->threshold();
		$current   = $this->sum( $year );
		$previous  = $this->sum( $year - 1 );

		return new ThresholdStatus(
			$current,
			$previous,
			$previous > $threshold || $current > $threshold,
			Settings::get()->oss_registered()
		);
	}

	public function crossing( float $order_net_eur ): bool {
		$year = (int) wp_date( 'Y' );
		return round( $this->sum( $year ) + $order_net_eur, 2 ) > $this->threshold();
	}

	public static function flush( int ...$years ): void {
		self::$sums = array();
		foreach ( $years as $year ) {
			delete_transient( self::TRANSIENT . $year );
		}
	}

	public function threshold(): float {
		return (float) apply_filters( 'vatright_threshold_eur', self::THRESHOLD_EUR );
	}

	private function sum( int $year ): float {
		if ( isset( self::$sums[ $year ] ) ) {
			return self::$sums[ $year ];
		}
		$cached = get_transient( self::TRANSIENT . $year );
		if ( false !== $cached ) {
			self::$sums[ $year ] = (float) $cached;
			return self::$sums[ $year ];
		}

		global $wpdb;
		$table = Schema::ledger_table();
		$total = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare(
				"SELECT COALESCE(SUM(net_eur), 0) FROM {$table} WHERE relates_to_period LIKE %s AND scheme IN (%s, %s) AND supply_type IN (%s, %s) AND customer_country <> %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$year . '-%',
				Scheme::ORIGIN,
				Scheme::OSS_DESTINATION,
				Line::GOODS,
				Line::DIGITAL,
				WC()->countries->get_base_country()
			)
		);

		self::$sums[ $year ] = round( (float) $total, 2 );
		set_transient( self::TRANSIENT . $year, self::$sums[ $year ], DAY_IN_SECONDS );
		return self::$sums[ $year ];
	}
}
