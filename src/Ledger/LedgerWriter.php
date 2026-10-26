<?php

namespace Vatright\Ledger;

use DateTimeImmutable;
use DateTimeZone;
use Vatright\Checkout\TaxLocation;
use Vatright\Domain\Decision;
use Vatright\Domain\Line;
use Vatright\Domain\Scheme;
use WC_Abstract_Order;
use WC_Order;
use WC_Order_Refund;
use WC_Tax;

class LedgerWriter {

	const DECISION_META = '_vatright_decision';
	const FX_META       = '_vatright_fx';
	const FX_RETRY_HOOK = 'vatright_ledger_fx_retry';
	const AS_GROUP      = 'vatright';

	/** @var Fx */
	private $fx;

	public function __construct( Fx $fx ) {
		$this->fx = $fx;
	}

	public function register(): void {
		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'record_refund' ), 10, 2 );
		add_action( self::FX_RETRY_HOOK, array( $this, 'on_payment_complete' ) );
	}

	public function on_payment_complete( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$this->record_order( $order );
		}
	}

	public function on_status_changed( int $order_id, string $from, string $to, WC_Order $order ): void {
		if ( in_array( $to, array( 'processing', 'completed' ), true ) ) {
			$this->record_order( $order );
		} elseif ( in_array( $to, array( 'cancelled', 'failed' ), true ) ) {
			$this->void_order( $order );
		}
	}

	public static function period_for( DateTimeImmutable $utc ): string {
		$local = $utc->setTimezone( wp_timezone() );
		return $local->format( 'Y' ) . '-Q' . (int) ceil( (int) $local->format( 'n' ) / 3 );
	}

	public function record_order( WC_Order $order ): void {
		$decision = $this->decision( $order );
		if ( null === $decision ) {
			return;
		}

		$supply_at = $this->timestamp( $order->get_date_paid() ?? $order->get_date_created() );
		$period    = self::period_for( $supply_at );
		$buckets   = $this->buckets( $order, $order, $decision );

		$years = $this->purge( 'order_id = %d AND entry_type = %s', array( $order->get_id(), 'sale' ) );
		$this->insert_rows( $order, $decision, $buckets, 'sale', null, $supply_at, $period, $period );
		ThresholdTracker::flush( ...array_merge( $years, array( (int) substr( $period, 0, 4 ) ) ) );
		do_action( 'vatright_ledger_recorded', (int) substr( $period, 0, 4 ) );
	}

	public function record_refund( int $order_id, int $refund_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order instanceof WC_Order || ! $refund instanceof WC_Order_Refund ) {
			return;
		}
		$decision = $this->decision( $order );
		if ( null === $decision ) {
			return;
		}
		if ( 0 === $this->count_sale_rows( $order_id ) ) {
			$this->record_order( $order );
		}

		$buckets = $this->buckets( $refund, $order, $decision );
		if ( array() === $buckets ) {
			$buckets = $this->proportional_buckets( $order, $refund, $decision );
		}

		$refunded_at = $this->timestamp( $refund->get_date_created() );
		$sale_period = $this->sale_period( $order_id );
		$period      = self::period_for( $refunded_at );

		$years = $this->purge( 'refund_id = %d', array( $refund_id ) );
		$this->insert_rows( $order, $decision, $buckets, 'refund', $refund_id, $refunded_at, $period, $sale_period );
		ThresholdTracker::flush( ...array_merge( $years, array( (int) substr( $sale_period, 0, 4 ) ) ) );
	}

	public function void_order( WC_Order $order ): void {
		if ( array() !== $order->get_refunds() ) {
			return;
		}
		$years = $this->purge( 'order_id = %d AND entry_type = %s', array( $order->get_id(), 'sale' ) );
		ThresholdTracker::flush( ...$years );
	}

	private function decision( WC_Order $order ): ?Decision {
		$raw = json_decode( (string) $order->get_meta( self::DECISION_META ), true );
		return is_array( $raw ) ? Decision::from_array( $raw ) : null;
	}

	private function timestamp( ?\WC_DateTime $date ): DateTimeImmutable {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		return null === $date ? $now : $now->setTimestamp( $date->getTimestamp() );
	}

	/**
	 * Groups the amounts on $source (an order or one of its refunds) by tax rate
	 * and line decision. An item's net sits on its first rate; every rate gets
	 * its own tax amount.
	 *
	 * @return array<string,array{rate_id:int,rate_percent:float,net:float,vat:float,line:\Vatright\Domain\LineDecision|null}>
	 */
	private function buckets( WC_Abstract_Order $source, WC_Order $order, Decision $decision ): array {
		$percents = array();
		foreach ( $order->get_taxes() as $tax ) {
			$percents[ $tax->get_rate_id() ] = (float) $tax->get_rate_percent();
		}

		$buckets = array();
		/** @var array<\WC_Order_Item_Product|\WC_Order_Item_Shipping|\WC_Order_Item_Fee> $items */
		$items = $source->get_items( array( 'line_item', 'shipping', 'fee' ) );
		foreach ( $items as $item ) {
			$original = $source === $order ? $item->get_id() : (int) $item->get_meta( '_refunded_item_id' );
			$follows  = $item instanceof \WC_Order_Item_Shipping ? TaxLocation::shipping_line( $decision ) : null;
			$line     = $decision->line( (string) $original ) ?? $follows ?? ( $decision->lines()[0] ?? null );
			$taxes    = array_filter(
				(array) ( $item->get_taxes()['total'] ?? array() ),
				static function ( $amount ): bool {
					return '' !== (string) $amount;
				}
			);
			$rates    = array() === $taxes ? array( 0 ) : array_map( 'intval', array_keys( $taxes ) );
			$net      = (float) $item->get_total();

			foreach ( $rates as $index => $rate_id ) {
				$key = $rate_id . '|' . ( null === $line ? '' : $line->supply_type() . $line->place_of_supply() . $line->scheme() );
				if ( ! isset( $buckets[ $key ] ) ) {
					$buckets[ $key ] = array(
						'rate_id'      => $rate_id,
						'rate_percent' => $percents[ $rate_id ] ?? 0.0,
						'net'          => 0.0,
						'vat'          => 0.0,
						'line'         => $line,
					);
				}
				$buckets[ $key ]['net'] += 0 === $index ? $net : 0.0;
				$buckets[ $key ]['vat'] += (float) ( $taxes[ $rate_id ] ?? 0 );
			}
		}

		return array_filter(
			$buckets,
			static function ( array $bucket ): bool {
				return 0.0 !== round( $bucket['net'], 4 ) || 0.0 !== round( $bucket['vat'], 4 );
			}
		);
	}

	/**
	 * A refund entered as an amount carries no items, so it is spread over the
	 * order's sale rows by its share of the order total.
	 *
	 * @return array<string,array{rate_id:int,rate_percent:float,net:float,vat:float,line:\Vatright\Domain\LineDecision|null}>
	 */
	private function proportional_buckets( WC_Order $order, WC_Order_Refund $refund, Decision $decision ): array {
		$total = (float) $order->get_total();
		if ( $total <= 0.0 ) {
			return array();
		}
		$share   = (float) $refund->get_amount() / $total;
		$buckets = $this->buckets( $order, $order, $decision );
		foreach ( $buckets as $key => $bucket ) {
			$buckets[ $key ]['net'] = -$bucket['net'] * $share;
			$buckets[ $key ]['vat'] = -$bucket['vat'] * $share;
		}
		return $buckets;
	}

	/**
	 * @param array<string,array{rate_id:int,rate_percent:float,net:float,vat:float,line:\Vatright\Domain\LineDecision|null}> $buckets
	 */
	private function insert_rows( WC_Order $order, Decision $decision, array $buckets, string $entry_type, ?int $refund_id, DateTimeImmutable $at, string $period, string $relates_to ): void {
		global $wpdb;

		$data     = $decision->to_array();
		$fx       = $this->order_fx( $order );
		$vat_no   = $data['vat_number']['canonical'] ?? null;
		$resolved = $data['evidence']['resolved'] ?? null;
		$billing  = (string) ( $data['customer']['billing']['country'] ?? '' );
		$shipping = (string) ( $data['customer']['shipping']['country'] ?? '' );
		$reverse  = array( Scheme::REVERSE_CHARGE_SERVICES, Scheme::INTRA_EU_GOODS );
		$fallback = in_array( TaxLocation::MISSING_FLAG, $decision->flags(), true );

		foreach ( $buckets as $bucket ) {
			$line     = $bucket['line'];
			$supply   = null === $line ? Line::GOODS : $line->supply_type();
			$scheme   = null === $line ? $decision->scheme() : $line->scheme();
			$place    = null === $line ? ( '' !== $shipping ? $shipping : $billing ) : $line->place_of_supply();
			$customer = Line::GOODS === $supply && '' !== $shipping ? $shipping : ( is_string( $resolved ) && '' !== $resolved ? $resolved : $billing );

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Schema::ledger_table(),
				array(
					'order_id'          => $order->get_id(),
					'refund_id'         => $refund_id,
					'entry_type'        => $entry_type,
					'decision_id'       => $data['id'],
					'scheme'            => $scheme,
					'rule'              => $decision->rule(),
					'supply_type'       => $supply,
					'customer_country'  => $customer,
					'place_of_supply'   => $place,
					'customer_vat'      => in_array( $scheme, $reverse, true ) && is_string( $vat_no ) ? $vat_no : null,
					'rate_percent'      => $bucket['rate_percent'],
					'net'               => round( $bucket['net'], 4 ),
					'vat'               => round( $bucket['vat'], 4 ),
					'currency'          => $order->get_currency(),
					'eur_rate'          => $fx,
					'net_eur'           => null === $fx ? null : round( $bucket['net'] / $fx, 4 ),
					'vat_eur'           => null === $fx ? null : round( $bucket['vat'] / $fx, 4 ),
					'rate_missing'      => $fallback && null !== $line && self::charged_elsewhere( $bucket['rate_id'], $line->tax_country() ) ? 1 : 0,
					'supply_at'         => $at->format( 'Y-m-d H:i:s' ),
					'period'            => $period,
					'relates_to_period' => $relates_to,
					'created_at'        => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}
	}

	/**
	 * Whether the VAT on a row was charged at a rate of another country than
	 * the line's tax country: the store's rate, charged because the tax tables
	 * had no rate for the destination.
	 */
	private static function charged_elsewhere( int $rate_id, string $tax_country ): bool {
		if ( 0 === $rate_id ) {
			return false;
		}
		$rate = WC_Tax::_get_tax_rate( $rate_id );
		return is_array( $rate ) && strtoupper( (string) $rate['tax_rate_country'] ) !== $tax_country;
	}

	/**
	 * The ECB quote (currency units per EUR) for the order date, fixed on the
	 * first ledger write so later writes and refunds convert identically.
	 */
	private function order_fx( WC_Order $order ): ?float {
		$stored = $order->get_meta( self::FX_META );
		if ( is_array( $stored ) && ( $stored['currency'] ?? '' ) === $order->get_currency() ) {
			return (float) $stored['eur_rate'];
		}

		$ymd  = $this->timestamp( $order->get_date_created() )->format( 'Y-m-d' );
		$rate = $this->fx->rate_on_or_before( $order->get_currency(), $ymd );
		if ( null === $rate ) {
			$this->schedule_fx_retry( $order );
			return null;
		}

		$order->update_meta_data(
			self::FX_META,
			array(
				'currency'  => $order->get_currency(),
				'eur_rate'  => $rate,
				'rate_date' => $ymd,
				'source'    => 'ecb',
			)
		);
		$order->save_meta_data();
		return $rate;
	}

	private function schedule_fx_retry( WC_Order $order ): void {
		$created = $order->get_date_created();
		$recent  = null !== $created && $created->getTimestamp() > time() - 14 * DAY_IN_SECONDS;
		if ( $recent && function_exists( 'as_schedule_single_action' ) && ! as_has_scheduled_action( self::FX_RETRY_HOOK, array( $order->get_id() ), self::AS_GROUP ) ) {
			as_schedule_single_action( time() + HOUR_IN_SECONDS, self::FX_RETRY_HOOK, array( $order->get_id() ), self::AS_GROUP );
		}
	}

	private function count_sale_rows( int $order_id ): int {
		global $wpdb;

		$table = Schema::ledger_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND entry_type = 'sale'", $order_id ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	private function sale_period( int $order_id ): string {
		global $wpdb;

		$table = Schema::ledger_table();
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT relates_to_period FROM {$table} WHERE order_id = %d AND entry_type = 'sale' LIMIT 1", $order_id ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * @param array<int,int|string> $args
	 * @return int[] years of the deleted rows
	 */
	private function purge( string $where, array $args ): array {
		global $wpdb;

		$table = Schema::ledger_table();
		$years = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT SUBSTR(relates_to_period, 1, 4) FROM {$table} WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return array_map( 'intval', $years );
	}
}
