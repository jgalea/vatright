<?php

namespace Vatright\Reports;

use DateTimeImmutable;
use DateTimeZone;
use Vatright\Domain\Line;
use Vatright\Domain\Scheme;
use Vatright\Domain\Territory;
use Vatright\Checkout\InputGatherer;
use Vatright\Intrastat\ProductData;
use Vatright\Ledger\LedgerWriter;
use Vatright\Ledger\Schema;
use Vatright\Orders\DecisionStore;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/**
 * The physical goods an order dispatched to another member state, grouped
 * the way an INTRASTAT dispatch declaration is. Services and digital goods
 * never appear. Amounts are EUR at the order-date ECB rate the ledger fixed
 * for the order, and a refund reduces the month of the sale it belongs to.
 */
class IntrastatReport {

	const OUTRIGHT_SALE = '11';
	const CONSUMER_SALE = '12';

	/**
	 * @return array{year:int,month:int,period:string,rows:array<int,array<string,mixed>>,missing:array<int,array<string,mixed>>}
	 * @throws IncompleteReport When an order in the month has no EUR amount.
	 */
	public function build( int $year, int $month ): array {
		$rows       = array();
		$missing    = array();
		$incomplete = array();

		foreach ( $this->orders( $year, $month ) as $order_id => $unconverted ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$lines = $this->dispatched_lines( $order );
			if ( array() === $lines ) {
				continue;
			}
			$rate = $this->eur_rate( $order );
			if ( $unconverted || null === $rate ) {
				$incomplete[] = $order_id;
				continue;
			}
			foreach ( $lines as $line ) {
				$key = implode( '|', array( $line['partner_country'], $line['cn8'], $line['nature'], $line['origin'], $line['partner_vat'] ) );
				if ( ! isset( $rows[ $key ] ) ) {
					$rows[ $key ] = array(
						'partner_country' => $line['partner_country'],
						'cn8'             => $line['cn8'],
						'nature'          => $line['nature'],
						'origin'          => $line['origin'],
						'partner_vat'     => $line['partner_vat'],
						'net_mass'        => 0.0,
						'value_eur'       => 0.0,
						'supp_units'      => null,
					);
				}
				$rows[ $key ]['net_mass']  += $line['net_mass'];
				$rows[ $key ]['value_eur'] += $line['value'] / $rate;
				if ( null !== $line['supp_units'] ) {
					$rows[ $key ]['supp_units'] = ( $rows[ $key ]['supp_units'] ?? 0.0 ) + $line['supp_units'];
				}
				if ( array() !== $line['missing'] ) {
					$missing[ $line['product_id'] ] = array(
						'product_id' => $line['product_id'],
						'name'       => $line['name'],
						'fields'     => $line['missing'],
					);
				}
			}
		}

		if ( array() !== $incomplete ) {
			throw new IncompleteReport( array_map( 'intval', $incomplete ) );
		}

		ksort( $rows );
		ksort( $missing );
		foreach ( $rows as &$row ) {
			$row['net_mass']  = round( $row['net_mass'], 3 );
			$row['value_eur'] = round( $row['value_eur'], 2 );
			if ( null !== $row['supp_units'] ) {
				$row['supp_units'] = round( $row['supp_units'], 3 );
			}
		}
		unset( $row );

		return array(
			'year'    => $year,
			'month'   => $month,
			'period'  => self::period( $year, $month ),
			'rows'    => array_values( $rows ),
			'missing' => array_values( $missing ),
		);
	}

	/**
	 * @param array{year:int,month:int,period:string,rows:array<int,array<string,mixed>>,missing:array<int,array<string,mixed>>} $report
	 * @return array{0:string[],1:array<int,array<mixed>>}
	 */
	public static function csv( array $report ): array {
		$header = array(
			__( 'Partner member state', 'vatright' ),
			__( 'CN8 commodity code', 'vatright' ),
			__( 'Nature of transaction', 'vatright' ),
			__( 'Country of origin', 'vatright' ),
			__( 'Partner VAT number', 'vatright' ),
			__( 'Net mass (kg)', 'vatright' ),
			__( 'Invoice value (EUR)', 'vatright' ),
			__( 'Supplementary units', 'vatright' ),
		);
		$lines  = array();
		foreach ( $report['rows'] as $row ) {
			$lines[] = array(
				$row['partner_country'],
				$row['cn8'],
				$row['nature'],
				$row['origin'],
				$row['partner_vat'],
				number_format( $row['net_mass'], 3, '.', '' ),
				number_format( $row['value_eur'], 2, '.', '' ),
				null === $row['supp_units'] ? '' : rtrim( rtrim( number_format( $row['supp_units'], 3, '.', '' ), '0' ), '.' ),
			);
		}
		return array( $header, $lines );
	}

	public static function period( int $year, int $month ): string {
		return sprintf( '%04d-%02d', $year, $month );
	}

	/**
	 * Orders with a goods sale in the month (in the site's timezone), each
	 * flagged when any of its ledger rows has no EUR amount.
	 *
	 * @return array<int,bool> Order id => has a row without an EUR amount.
	 */
	private function orders( int $year, int $month ): array {
		global $wpdb;

		$start = new DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), wp_timezone() );
		$utc   = new DateTimeZone( 'UTC' );
		$table = Schema::ledger_table();
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$found = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_id, MAX(net_eur IS NULL) AS unconverted FROM {$table}
				WHERE entry_type = 'sale' AND supply_type = 'goods' AND supply_at >= %s AND supply_at < %s
				GROUP BY order_id ORDER BY order_id",
				$start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				$start->modify( '+1 month' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$orders = array();
		foreach ( $found as $row ) {
			$orders[ (int) $row['order_id'] ] = '1' === (string) $row['unconverted'];
		}
		return $orders;
	}

	/**
	 * @return array<int,array{partner_country:string,cn8:string,nature:string,origin:string,partner_vat:string,net_mass:float,value:float,supp_units:?float,product_id:int,name:string,missing:string[]}>
	 */
	private function dispatched_lines( WC_Order $order ): array {
		$decision = DecisionStore::get( $order );
		if ( null === $decision ) {
			return array();
		}
		$record = $decision->to_array();
		$store  = strtoupper( (string) ( $record['store']['country'] ?? '' ) );
		$dest   = strtoupper( (string) ( $record['customer']['shipping']['country'] ?? '' ) );
		if ( '' === $dest ) {
			$dest = strtoupper( (string) ( $record['customer']['billing']['country'] ?? '' ) );
		}
		if ( '' === $dest || $dest === $store || ! Territory::is_eu_member( $dest ) ) {
			return array();
		}

		$lines = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$key      = (string) $item->get_meta( InputGatherer::LINE_KEY_META );
			$decided  = $decision->line( '' === $key ? (string) $item->get_id() : $key ) ?? ( $decision->lines()[0] ?? null );
			$quantity = (float) $item->get_quantity() + (float) $order->get_qty_refunded_for_item( $item->get_id() );
			if ( null === $decided || Line::GOODS !== $decided->supply_type() || $quantity <= 0 ) {
				continue;
			}

			$product = $item->get_product();
			$product = $product instanceof WC_Product ? $product : null;
			$data    = $product ? ProductData::for_product( $product ) : array(
				'cn8'        => '',
				'origin'     => '',
				'net_mass'   => null,
				'supp_units' => null,
			);
			$absent  = array();
			foreach ( array(
				'cn8'      => '' === $data['cn8'],
				'origin'   => '' === $data['origin'],
				'net_mass' => null === $data['net_mass'],
			) as $field => $is_absent ) {
				if ( $is_absent ) {
					$absent[] = $field;
				}
			}

			$vat_no = Scheme::INTRA_EU_GOODS === $decided->scheme() ? (string) ( $record['vat_number']['canonical'] ?? '' ) : '';

			/**
			 * Filters the INTRASTAT nature of transaction code of a line.
			 *
			 * @param string                $code  Two digits; 11 for a sale to a business, 12 for a sale to a private consumer.
			 * @param WC_Order_Item_Product $item  The order line.
			 * @param WC_Order              $order The order.
			 */
			$nature = (string) apply_filters( 'vatright_intrastat_nature_of_transaction', '' === $vat_no ? self::CONSUMER_SALE : self::OUTRIGHT_SALE, $item, $order );

			$lines[] = array(
				'partner_country' => Territory::vat_prefix( $dest ),
				'cn8'             => $data['cn8'],
				'nature'          => $nature,
				'origin'          => '' === $data['origin'] ? '' : Territory::vat_prefix( $data['origin'] ),
				'partner_vat'     => $vat_no,
				'net_mass'        => (float) $data['net_mass'] * $quantity,
				'value'           => (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item->get_id() ),
				'supp_units'      => null === $data['supp_units'] ? null : $data['supp_units'] * $quantity,
				'product_id'      => $product ? $product->get_id() : 0,
				'name'            => $item->get_name(),
				'missing'         => $absent,
			);
		}
		return $lines;
	}

	private function eur_rate( WC_Order $order ): ?float {
		$fx = $order->get_meta( LedgerWriter::FX_META );
		if ( is_array( $fx ) && ( $fx['currency'] ?? '' ) === $order->get_currency() && (float) $fx['eur_rate'] > 0 ) {
			return (float) $fx['eur_rate'];
		}
		return 'EUR' === $order->get_currency() ? 1.0 : null;
	}
}
