<?php

namespace Vatright\Reports;

use Generator;
use Vatright\Ledger\Schema;
use Vatright\Orders\DecisionStore;
use WC_Order;

class ComplianceExport {

	const PAGE = 500;

	/**
	 * One row per order sold in the period, with the evidence its VAT
	 * treatment rests on. Net and VAT include refunds. Orders are read a page
	 * at a time, so a long period never sits in memory whole.
	 *
	 * @return Generator<int,array<int,mixed>>
	 */
	public function rows_for( ReportPeriod $period, int $page_size = self::PAGE ): Generator {
		global $wpdb;

		$table                    = Schema::ledger_table();
		list( $sale, $sale_args ) = $period->sale_condition( $table, wp_timezone() );
		$after                    = 0;
		$fetched                  = 0;
		do {
			// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$orders = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT order_id, MIN(supply_at) AS supply_at, MIN(currency) AS currency, SUM(net) AS net, SUM(vat) AS vat
					FROM {$table}
					WHERE ({$sale}) AND order_id > %d
					GROUP BY order_id
					ORDER BY order_id
					LIMIT %d",
					array_merge( $sale_args, array( $after, $page_size ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$fetched = count( $orders );
			foreach ( $orders as $totals ) {
				$after  = (int) $totals['order_id'];
				$order  = wc_get_order( $after );
				$record = $order instanceof WC_Order ? DecisionStore::get( $order ) : null;
				yield self::row( $totals, null === $record ? null : $record->to_array() );
			}
		} while ( $fetched === $page_size );
	}

	/**
	 * @param array<string,mixed>      $totals order_id, supply_at, currency, net, vat.
	 * @param array<string,mixed>|null $record The order's decision record.
	 * @return array<int,mixed>
	 */
	public static function row( array $totals, ?array $record ): array {
		$record = $record ?? array();
		return array(
			(int) $totals['order_id'],
			(string) $totals['supply_at'],
			$record['customer']['billing']['country'] ?? '',
			$record['customer']['shipping']['country'] ?? '',
			$record['evidence']['ip'] ?? '',
			$record['evidence']['self_certified'] ?? '',
			$record['vat_number']['canonical'] ?? '',
			$record['validation']['registry'] ?? '',
			$record['validation']['status'] ?? '',
			$record['validation']['checked_at'] ?? '',
			$record['validation']['consultation'] ?? '',
			$record['scheme'] ?? '',
			$record['rule'] ?? '',
			implode( ' ', (array) ( $record['flags'] ?? array() ) ),
			(string) $totals['currency'],
			number_format( (float) $totals['net'], 2, '.', '' ),
			number_format( (float) $totals['vat'], 2, '.', '' ),
		);
	}

	/**
	 * @param iterable<int,array<int,mixed>> $rows
	 * @return array{0:string[],1:iterable<int,array<int,mixed>>}
	 */
	public static function csv( iterable $rows ): array {
		$header = array(
			__( 'Order', 'vatright' ),
			__( 'Sale date (UTC)', 'vatright' ),
			__( 'Billing country', 'vatright' ),
			__( 'Shipping country', 'vatright' ),
			__( 'IP country', 'vatright' ),
			__( 'Self-certified country', 'vatright' ),
			__( 'VAT number', 'vatright' ),
			__( 'Registry', 'vatright' ),
			__( 'Registry result', 'vatright' ),
			__( 'Checked at', 'vatright' ),
			__( 'Consultation number', 'vatright' ),
			__( 'Scheme', 'vatright' ),
			__( 'Rule', 'vatright' ),
			__( 'Flags', 'vatright' ),
			__( 'Currency', 'vatright' ),
			__( 'Net', 'vatright' ),
			__( 'VAT', 'vatright' ),
		);
		return array( $header, $rows );
	}
}
