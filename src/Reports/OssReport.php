<?php

namespace Vatright\Reports;

use DateTimeImmutable;
use DateTimeZone;
use Vatright\Ledger\Fx;
use Vatright\Ledger\Schema;
use RuntimeException;

class OssReport {

	const RATE_RULE = 'ECB reference rate of the last day of the period';

	/** @var Fx */
	private $fx;

	/** @var array<string,array{currency:string,period:string,rate_date:string,rate:float}> */
	private $rates = array();

	public function __construct( Fx $fx ) {
		$this->fx = $fx;
	}

	/**
	 * Refunds count in the period of the sale they reduce. A refund booked in a
	 * later period is repeated in `corrections` of that later period so the
	 * earlier return can be amended. For a month or a custom range the earlier
	 * period of a correction is the month of its sale.
	 *
	 * Sales whose VAT was charged at the store's own rate because the tax
	 * tables had no rate for the destination are left out of `rows`: their
	 * amounts are not the destination VAT the return declares. They are listed
	 * per order in `needs_correction` so the merchant can settle them.
	 *
	 * @return array{kind:string,year:int,quarter:int|null,period:string,from:string,to:string,currency:string,rate_rule:string,rows:array<int,array<string,mixed>>,corrections:array<int,array<string,mixed>>,needs_correction:array<int,array<string,mixed>>,rates:array<int,array<string,mixed>>}
	 * @throws RuntimeException When a currency has no ECB rate on or after the period end.
	 */
	public function build_for( ReportPeriod $report_period, string $currency = 'EUR' ): array {
		$this->forget_rates();
		$currency = strtoupper( $currency );
		$period   = $report_period->label();

		list( $sale_sql, $sale_args ) = $report_period->sale_condition( Schema::ledger_table(), wp_timezone() );
		$rows                         = $this->convert( $this->groups( $sale_sql, $sale_args ), $period, $currency );
		$needs_correction             = $this->needs_correction( $sale_sql, $sale_args, $period, $currency );

		return array(
			'kind'             => $report_period->kind(),
			'year'             => $report_period->year(),
			'quarter'          => $report_period->quarter_number(),
			'period'           => $period,
			'from'             => $report_period->start(),
			'to'               => $report_period->end(),
			'currency'         => $currency,
			'rate_rule'        => self::RATE_RULE,
			'rows'             => $rows,
			'corrections'      => $this->corrections( $report_period, $currency ),
			'needs_correction' => $needs_correction,
			'rates'            => array_values( $this->rates ),
		);
	}

	/**
	 * @return array{kind:string,year:int,quarter:int|null,period:string,from:string,to:string,currency:string,rate_rule:string,rows:array<int,array<string,mixed>>,corrections:array<int,array<string,mixed>>,needs_correction:array<int,array<string,mixed>>,rates:array<int,array<string,mixed>>}
	 * @throws RuntimeException When a currency has no ECB rate on or after the period end.
	 */
	public function build( int $year, int $quarter, string $currency = 'EUR' ): array {
		return $this->build_for( ReportPeriod::quarter( $year, $quarter ), $currency );
	}

	/**
	 * @param array{currency:string,rate_rule:string,rows:array<int,array<string,mixed>>,corrections:array<int,array<string,mixed>>,needs_correction:array<int,array<string,mixed>>,rates:array<int,array<string,mixed>>} $report
	 * @return array{0:string[],1:array<int,array<mixed>>}
	 */
	public static function csv( array $report ): array {
		$currency = $report['currency'];
		$header   = array(
			__( 'Member state of consumption', 'vatright' ),
			__( 'VAT rate %', 'vatright' ),
			__( 'Supply type', 'vatright' ),
			/* translators: %s: currency code. */
			sprintf( __( 'Taxable amount (%s)', 'vatright' ), $currency ),
			/* translators: %s: currency code. */
			sprintf( __( 'VAT amount (%s)', 'vatright' ), $currency ),
		);

		$lines = array();
		foreach ( $report['rows'] as $row ) {
			$lines[] = array( $row['member_state'], $row['rate_percent'], $row['supply_type'], self::money( $row['taxable'] ), self::money( $row['vat'] ) );
		}

		if ( array() !== $report['corrections'] ) {
			$lines[] = array();
			$lines[] = array( __( 'Corrections to earlier periods', 'vatright' ), __( 'VAT rate %', 'vatright' ), __( 'Supply type', 'vatright' ), __( 'Taxable amount', 'vatright' ), __( 'VAT amount', 'vatright' ), __( 'Period corrected', 'vatright' ) );
			foreach ( $report['corrections'] as $row ) {
				$lines[] = array( $row['member_state'], $row['rate_percent'], $row['supply_type'], self::money( $row['taxable'] ), self::money( $row['vat'] ), $row['relates_to_period'] );
			}
		}

		if ( array() !== $report['needs_correction'] ) {
			$lines[] = array();
			$lines[] = array( self::needs_correction_title(), __( 'Order', 'vatright' ), __( 'Rate charged %', 'vatright' ), __( 'Supply type', 'vatright' ), __( 'Taxable amount', 'vatright' ), __( 'VAT charged', 'vatright' ) );
			foreach ( $report['needs_correction'] as $row ) {
				$lines[] = array( $row['member_state'], $row['order_id'], $row['rate_percent'], $row['supply_type'], self::money( $row['taxable'] ), self::money( $row['vat'] ) );
			}
		}

		if ( array() !== $report['rates'] ) {
			$lines[] = array();
			$lines[] = array(
				/* translators: %s: conversion rule. */
				sprintf( __( 'Exchange rates used: %s', 'vatright' ), $report['rate_rule'] ),
				__( 'Period', 'vatright' ),
				__( 'Rate date', 'vatright' ),
				__( 'Units per EUR', 'vatright' ),
			);
			foreach ( $report['rates'] as $rate ) {
				$lines[] = array( $rate['currency'], $rate['period'], $rate['rate_date'], $rate['rate'] );
			}
		}

		return array( $header, $lines );
	}

	public static function needs_correction_title(): string {
		return __( 'Charged at your own rate, needs correction (not included above)', 'vatright' );
	}

	private static function money( float $amount ): string {
		return number_format( $amount, 2, '.', '' );
	}

	public static function period( int $year, int $quarter ): string {
		return $year . '-Q' . $quarter;
	}

	public static function period_end( string $period ): string {
		$parsed = ReportPeriod::from_label( $period );
		if ( null === $parsed ) {
			throw new RuntimeException( 'Unknown report period.' );
		}
		return $parsed->end();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function corrections( ReportPeriod $report_period, string $currency ): array {
		if ( ReportPeriod::QUARTER === $report_period->kind() ) {
			return $this->quarter_corrections( $report_period->label(), $currency );
		}

		global $wpdb;

		$table                = Schema::ledger_table();
		list( $from, $until ) = $report_period->utc_bounds( wp_timezone() );
		$sql                  = "SELECT r.order_id, r.place_of_supply AS member_state, r.rate_percent, IF(r.supply_type = 'goods', 'goods', 'services') AS supply_type, r.currency,
				SUM(r.net) AS net, SUM(r.vat) AS vat, s.sale_at
			FROM {$table} r
			JOIN (SELECT order_id, MIN(supply_at) AS sale_at FROM {$table} WHERE entry_type = 'sale' GROUP BY order_id) s ON s.order_id = r.order_id
			WHERE r.scheme = 'oss_destination' AND r.rate_missing = 0 AND r.entry_type = 'refund'
				AND r.supply_at >= %s AND r.supply_at < %s AND (s.sale_at < %s OR s.sale_at >= %s)
			GROUP BY r.order_id, r.place_of_supply, r.rate_percent, IF(r.supply_type = 'goods', 'goods', 'services'), r.currency, s.sale_at";
		$found                = $wpdb->get_results( $wpdb->prepare( $sql, $from, $until, $from, $until ), ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$by_month = array();
		foreach ( $found as $row ) {
			$sold                 = new DateTimeImmutable( (string) $row['sale_at'], new DateTimeZone( 'UTC' ) );
			$month                = $sold->setTimezone( wp_timezone() )->format( 'Y-m' );
			$by_month[ $month ][] = $row;
		}
		ksort( $by_month );

		$corrections = array();
		foreach ( $by_month as $original => $groups ) {
			foreach ( $this->convert( $groups, (string) $original, $currency ) as $row ) {
				$corrections[] = array_merge( array( 'relates_to_period' => (string) $original ), $row );
			}
		}
		return $corrections;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function quarter_corrections( string $period, string $currency ): array {
		global $wpdb;

		$table     = Schema::ledger_table();
		$originals = $wpdb->get_col( // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare( "SELECT DISTINCT relates_to_period FROM {$table} WHERE scheme = 'oss_destination' AND rate_missing = 0 AND entry_type = 'refund' AND period = %s AND relates_to_period <> period ORDER BY relates_to_period", $period ) // phpcs:ignore WordPress.DB
		);

		$corrections = array();
		foreach ( $originals as $original ) {
			$groups = $this->groups( "entry_type = 'refund' AND period = %s AND relates_to_period = %s", array( $period, $original ) );
			foreach ( $this->convert( $groups, $original, $currency ) as $row ) {
				$corrections[] = array_merge( array( 'relates_to_period' => $original ), $row );
			}
		}
		return $corrections;
	}

	/**
	 * @param array<int,string> $args
	 * @return array<int,array<string,mixed>>
	 */
	private function groups( string $where, array $args ): array {
		global $wpdb;

		$table = Schema::ledger_table();
		$sql   = "SELECT place_of_supply AS member_state, rate_percent, IF(supply_type = 'goods', 'goods', 'services') AS supply_type, currency,
				SUM(net) AS net, SUM(vat) AS vat
			FROM {$table} WHERE scheme = 'oss_destination' AND rate_missing = 0 AND {$where}
			GROUP BY place_of_supply, rate_percent, IF(supply_type = 'goods', 'goods', 'services'), currency";
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * OSS sales of the period, net of their refunds, whose VAT was charged at
	 * the store's rate. One row per order, member state, rate and supply type.
	 *
	 * @param array<int,string> $sale_args
	 * @return array<int,array<string,mixed>>
	 */
	private function needs_correction( string $sale_sql, array $sale_args, string $period, string $currency ): array {
		global $wpdb;

		$table  = Schema::ledger_table();
		$sql    = "SELECT order_id, place_of_supply AS member_state, rate_percent, IF(supply_type = 'goods', 'goods', 'services') AS supply_type, currency,
				SUM(net) AS net, SUM(vat) AS vat
			FROM {$table} WHERE scheme = 'oss_destination' AND rate_missing = 1 AND {$sale_sql}
			GROUP BY order_id, place_of_supply, rate_percent, IF(supply_type = 'goods', 'goods', 'services'), currency
			ORDER BY order_id, place_of_supply";
		$groups = $wpdb->get_results( $wpdb->prepare( $sql, $sale_args ), ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$end  = self::period_end( $period );
		$rows = array();
		foreach ( $groups as $group ) {
			$source = (string) $group['currency'];
			$rows[] = array(
				'order_id'     => (int) $group['order_id'],
				'member_state' => $group['member_state'],
				'rate_percent' => (float) $group['rate_percent'],
				'supply_type'  => $group['supply_type'],
				'taxable'      => round( $this->amount( (float) $group['net'], $source, $currency, $period, $end ), 2 ),
				'vat'          => round( $this->amount( (float) $group['vat'], $source, $currency, $period, $end ), 2 ),
			);
		}
		return $rows;
	}

	/**
	 * Converts each currency group at the rate of the period end and merges
	 * groups that land on the same row.
	 *
	 * @param array<int,array<string,mixed>> $groups
	 * @return array<int,array<string,mixed>>
	 */
	private function convert( array $groups, string $period, string $currency ): array {
		$end    = self::period_end( $period );
		$merged = array();
		foreach ( $groups as $group ) {
			$source = (string) $group['currency'];
			$key    = $group['member_state'] . '|' . (float) $group['rate_percent'] . '|' . $group['supply_type'];
			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = array(
					'member_state' => $group['member_state'],
					'rate_percent' => (float) $group['rate_percent'],
					'supply_type'  => $group['supply_type'],
					'taxable'      => 0.0,
					'vat'          => 0.0,
				);
			}
			$merged[ $key ]['taxable'] += $this->amount( (float) $group['net'], $source, $currency, $period, $end );
			$merged[ $key ]['vat']     += $this->amount( (float) $group['vat'], $source, $currency, $period, $end );
		}

		ksort( $merged );
		foreach ( $merged as &$row ) {
			$row['taxable'] = round( $row['taxable'], 2 );
			$row['vat']     = round( $row['vat'], 2 );
		}
		unset( $row );
		return array_values( $merged );
	}

	private function amount( float $amount, string $source, string $currency, string $period, string $end ): float {
		if ( $source === $currency ) {
			return $amount;
		}
		$eur = $amount / $this->rate( $source, $period, $end );
		return 'EUR' === $currency ? $eur : $eur * $this->rate( $currency, $period, $end );
	}

	private function forget_rates(): void {
		$this->rates = array();
	}

	private function rate( string $currency, string $period, string $end ): float {
		$key = $currency . '|' . $period;
		if ( ! isset( $this->rates[ $key ] ) ) {
			$rate = $this->fx->rate_on_or_after( $currency, $end );
			if ( null === $rate ) {
				throw new RuntimeException( sprintf( 'No ECB rate for %s on or after %s.', esc_html( $currency ), esc_html( $end ) ) );
			}
			$this->rates[ $key ] = array(
				'currency'  => $currency,
				'period'    => $period,
				'rate_date' => $end,
				'rate'      => $rate,
			);
		}
		return $this->rates[ $key ]['rate'];
	}
}
