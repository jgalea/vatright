<?php

namespace Vatright\Privacy;

use DateTimeImmutable;
use Vatright\Ledger\Schema;
use Vatright\Migration\LegacyData;
use Vatright\Orders\CompatKeys;
use Vatright\Orders\DecisionStore;
use Vatright\Settings;
use WC_Order;

class Retention {

	const HOOK          = 'vatright_retention';
	const AS_GROUP      = 'vatright';
	const BATCH         = 100;
	const SCRUBBED_META = '_vatright_scrubbed';

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
		add_action( 'action_scheduler_init', array( $this, 'schedule' ) );
	}

	public function schedule(): void {
		if ( ! as_has_scheduled_action( self::HOOK, null, self::AS_GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), self::AS_GROUP );
		}
	}

	/**
	 * Skipped orders stay in the result set, so the offset moves past them;
	 * scrubbed orders drop out of it.
	 */
	public function run_batch( int $offset = 0 ): void {
		$cutoff = new DateTimeImmutable( ( (int) wp_date( 'Y' ) - Settings::get()->retention_years() ) . '-01-01', wp_timezone() );
		/** @var WC_Order[] $orders */
		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => 'any',
				'date_created' => '<' . $cutoff->getTimestamp(),
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => DecisionStore::META,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::SCRUBBED_META,
						'compare' => 'NOT EXISTS',
					),
				),
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'limit'        => self::BATCH,
				'offset'       => $offset,
			)
		);

		$skipped = 0;
		foreach ( $orders as $order ) {
			if ( self::is_retained( $order ) ) {
				++$skipped;
				continue;
			}
			self::scrub( $order );
		}

		if ( count( $orders ) === self::BATCH ) {
			as_enqueue_async_action( self::HOOK, array( $offset + $skipped ), self::AS_GROUP );
		}
	}

	public static function retained_until( WC_Order $order ): DateTimeImmutable {
		$supplied = $order->get_date_paid() ?? $order->get_date_created();
		$created  = $order->get_date_created();
		if ( null !== $created && ( null === $supplied || $created > $supplied ) ) {
			$supplied = $created;
		}
		$year = null === $supplied ? (int) wp_date( 'Y' ) : (int) wp_date( 'Y', $supplied->getTimestamp() );

		return new DateTimeImmutable( ( $year + Settings::get()->retention_years() + 1 ) . '-01-01', wp_timezone() );
	}

	public static function is_retained( WC_Order $order ): bool {
		return self::retained_until( $order )->getTimestamp() > time();
	}

	public static function holds_personal_data( WC_Order $order ): bool {
		if ( '' !== (string) $order->get_meta( DecisionStore::NUMBER_META ) || '' !== (string) $order->get_meta( LegacyData::IMPORTED_META ) ) {
			return true;
		}
		if ( ! $order->meta_exists( self::SCRUBBED_META ) && null !== LegacyData::order( $order ) ) {
			return true;
		}
		$record = json_decode( (string) $order->get_meta( DecisionStore::META ), true );
		if ( ! is_array( $record ) ) {
			return false;
		}
		return ! empty( $record['vat_number'] )
			|| ! empty( $record['validation']['name'] )
			|| ! empty( $record['validation']['address'] )
			|| ! empty( $record['validation']['consultation'] )
			|| ! empty( $record['evidence']['ip'] );
	}

	/**
	 * Removes the personal data from the order's decision record and the
	 * ledger rows. The scheme, rule, amounts and country evidence the tax
	 * return rests on stay.
	 */
	public static function scrub( WC_Order $order ): bool {
		$changed = self::holds_personal_data( $order );

		$order->delete_meta_data( DecisionStore::NUMBER_META );
		$order->delete_meta_data( LegacyData::IMPORTED_META );
		CompatKeys::remove( $order );
		foreach ( LegacyData::SOURCES as $definition ) {
			foreach ( $definition['order_number'] as $key ) {
				$order->delete_meta_data( $key );
			}
		}
		$record = json_decode( (string) $order->get_meta( DecisionStore::META ), true );
		if ( is_array( $record ) ) {
			$record['vat_number'] = null;
			foreach ( array( 'name', 'address', 'consultation' ) as $field ) {
				if ( isset( $record['validation'][ $field ] ) ) {
					$record['validation'][ $field ] = null;
				}
			}
			if ( isset( $record['evidence']['ip'] ) ) {
				$record['evidence']['ip'] = null;
			}
			$order->update_meta_data( DecisionStore::META, (string) wp_json_encode( $record ) );
		}
		$order->update_meta_data( self::SCRUBBED_META, gmdate( 'Y-m-d\TH:i:s\Z' ) );
		$order->save();

		global $wpdb;
		$wpdb->update( Schema::ledger_table(), array( 'customer_vat' => null ), array( 'order_id' => $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $changed;
	}
}
