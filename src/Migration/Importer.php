<?php

namespace Vatright\Migration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Vatright\Checkout\InputGatherer;
use Vatright\Domain\FormatCheck;
use Vatright\Domain\VatNumber;
use Vatright\Ledger\Backfill;
use Vatright\Ledger\Schema;
use Vatright\Orders\CompatKeys;
use Vatright\Orders\DecisionStore;
use Vatright\Privacy\Retention;
use Vatright\Settings;
use WC_Order;

/**
 * Copies VAT numbers other VAT plugins left behind into VAT Right's own keys:
 * order numbers with the verdict the plugin recorded, customers' saved
 * numbers, and the store's own VAT number. Data is found by its meta keys,
 * so it is found with the other plugin inactive or deleted. Nothing VAT
 * Right already holds is replaced and the other plugin's keys are never
 * changed, so running it again only picks up what is still missing.
 */
class Importer {

	const HOOK     = 'vatright_import_batch';
	const AS_GROUP = 'vatright';
	const BATCH    = 100;

	const STATE         = 'vatright_import_state';
	const DRY_RUN_CACHE = 'vatright_import_dry_run';

	const ORDER_TYPES = array( 'shop_order', 'shop_subscription' );

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ), 10, 2 );
	}

	/**
	 * What an import would do now, per source plugin with data on this site.
	 *
	 * @return array{sources: array<string,array{name:string,active:bool,orders:int,orders_to_import:int}>, orders:int, orders_to_import:int, customers:int, customers_to_import:int, settings: array<string,string>}
	 */
	public function dry_run( ?string $only = null ): array {
		$sources = array();
		foreach ( self::sources( $only ) as $source ) {
			$keys  = LegacyData::SOURCES[ $source ]['order_number'];
			$found = $this->count_orders( $keys, false );
			if ( 0 === $found ) {
				continue;
			}
			$sources[ $source ] = array(
				'name'             => LegacyData::SOURCES[ $source ]['name'],
				'active'           => LegacyData::is_active( $source ),
				'orders'           => $found,
				'orders_to_import' => $this->count_orders( $keys, true ),
			);
		}
		$order_keys = self::keys( $only, 'order_number' );
		$user_keys  = self::keys( $only, 'user_number' );
		return array(
			'sources'             => $sources,
			'orders'              => $this->count_orders( $order_keys, false ),
			'orders_to_import'    => $this->count_orders( $order_keys, true ),
			'customers'           => $this->count_customers( $user_keys, false ),
			'customers_to_import' => $this->count_customers( $user_keys, true ),
			'settings'            => $this->import_settings( $only, false ),
		);
	}

	/**
	 * Hands the import to Action Scheduler, one batch at a time.
	 */
	public function start(): void {
		delete_transient( self::DRY_RUN_CACHE );
		update_option(
			self::STATE,
			array(
				'status'    => 'running',
				'started'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'orders'    => 0,
				'customers' => 0,
				'settings'  => array(),
			),
			false
		);
		as_unschedule_all_actions( self::HOOK, array(), self::AS_GROUP );
		as_enqueue_async_action( self::HOOK, array( 'orders', 0 ), self::AS_GROUP );
	}

	/**
	 * @return array{status?:string,started?:string,finished?:string,orders?:int,customers?:int,settings?:array<string,string>}
	 */
	public static function state(): array {
		$state = get_option( self::STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	public function run_batch( string $phase, int $after ): void {
		$state = self::state();
		if ( 'orders' === $phase ) {
			$batch           = $this->import_orders( $after, self::BATCH );
			$state['orders'] = (int) ( $state['orders'] ?? 0 ) + $batch['imported'];
			update_option( self::STATE, $state, false );
			if ( $batch['done'] ) {
				as_enqueue_async_action( self::HOOK, array( 'customers', 0 ), self::AS_GROUP );
			} else {
				as_enqueue_async_action( self::HOOK, array( 'orders', $batch['last'] ), self::AS_GROUP );
			}
			return;
		}

		$batch              = $this->import_customers( $after, self::BATCH );
		$state['customers'] = (int) ( $state['customers'] ?? 0 ) + $batch['imported'];
		if ( ! $batch['done'] ) {
			update_option( self::STATE, $state, false );
			as_enqueue_async_action( self::HOOK, array( 'customers', $batch['last'] ), self::AS_GROUP );
			return;
		}
		$state['settings'] = $this->import_settings( null, true );
		delete_transient( self::DRY_RUN_CACHE );
		$state['status']   = 'done';
		$state['finished'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		update_option( self::STATE, $state, false );
		Backfill::schedule();
	}

	/**
	 * Imports the next batch of orders after the order ID $after.
	 *
	 * @return array{imported:int,last:int,done:bool}
	 */
	public function import_orders( int $after, int $limit, ?string $only = null ): array {
		$ids      = $this->order_ids( self::keys( $only, 'order_number' ), $after, $limit );
		$imported = 0;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof WC_Order && $this->import_order( $order, $only ) ) {
				++$imported;
			}
		}
		return array(
			'imported' => $imported,
			'last'     => array() === $ids ? $after : (int) end( $ids ),
			'done'     => count( $ids ) < $limit,
		);
	}

	/**
	 * @return array{imported:int,last:int,done:bool}
	 */
	public function import_customers( int $after, int $limit, ?string $only = null ): array {
		global $wpdb;

		list( $where, $args ) = self::customer_where( self::keys( $only, 'user_number' ), true );
		$ids                  = array() === $args ? array() : array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.user_id {$where} AND m.user_id > %d ORDER BY m.user_id LIMIT %d", array_merge( $args, array( $after, $limit ) ) ) ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$imported = 0;
		foreach ( $ids as $user_id ) {
			$legacy = LegacyData::customer( $user_id );
			if ( null === $legacy || metadata_exists( 'user', $user_id, InputGatherer::NUMBER_META ) || ( null !== $only && $only !== $legacy['source'] ) ) {
				continue;
			}
			$parsed = VatNumber::parse( $legacy['number'], (string) get_user_meta( $user_id, 'billing_country', true ) );
			update_user_meta( $user_id, InputGatherer::NUMBER_META, null === $parsed ? $legacy['number'] : $parsed->canonical() );
			++$imported;
		}
		return array(
			'imported' => $imported,
			'last'     => array() === $ids ? $after : (int) end( $ids ),
			'done'     => count( $ids ) < $limit,
		);
	}

	/**
	 * Fills the store VAT numbers VAT Right has no value for. Returns the
	 * settings it set, or would set when $apply is false.
	 *
	 * @return array<string,string>
	 */
	public function import_settings( ?string $only, bool $apply ): array {
		$settings = Settings::get();
		$base     = WC()->countries->get_base_country();
		$found    = array();
		foreach ( self::sources( $only ) as $source ) {
			$numbers = LegacyData::store_numbers( $source, 'get_option' );
			$eu      = VatNumber::parse( $numbers['eu'], $base );
			$gb      = VatNumber::parse( $numbers['gb'], 'GB' );
			if ( '' === $settings->store_vat_number() && ! isset( $found['store_vat_number'] ) && null !== $eu && FormatCheck::is_plausible( $eu ) ) {
				$found['store_vat_number'] = $eu->canonical();
			}
			if ( '' === $settings->store_vat_number_gb() && ! isset( $found['store_vat_number_gb'] ) && null !== $gb && 'GB' === $gb->prefix() && FormatCheck::is_plausible( $gb ) ) {
				$found['store_vat_number_gb'] = $gb->canonical();
			}
		}
		if ( $apply && array() !== $found ) {
			$stored = get_option( Settings::OPTION, array() );
			update_option( Settings::OPTION, array_merge( is_array( $stored ) ? $stored : array(), $found ) );
			Settings::reset();
		}
		return $found;
	}

	private function import_order( WC_Order $order, ?string $only ): bool {
		foreach ( array( InputGatherer::NUMBER_META, LegacyData::IMPORTED_META, Retention::SCRUBBED_META ) as $key ) {
			if ( $order->meta_exists( $key ) ) {
				return false;
			}
		}
		$record     = json_decode( (string) $order->get_meta( DecisionStore::META ), true );
		$backfilled = is_array( $record ) && in_array( 'backfilled', (array) ( $record['flags'] ?? array() ), true );
		$legacy     = LegacyData::order( $order, $only );
		if ( null === $legacy || ( is_array( $record ) && ! $backfilled ) ) {
			return false;
		}

		$section = (array) LegacyData::number_section( $legacy['number'], $order->get_billing_country() );
		$number  = '' === $section['canonical'] ? $legacy['number'] : $section['canonical'];
		$order->update_meta_data( InputGatherer::NUMBER_META, $number );
		$order->update_meta_data(
			LegacyData::IMPORTED_META,
			(string) wp_json_encode(
				array(
					'source' => $legacy['source'],
					'number' => $legacy['number'],
					'status' => $legacy['status'],
					'at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
				)
			)
		);

		// A decision the backfill inferred before the import has no number; it gets the imported one, and so do its ledger rows.
		if ( $backfilled && empty( $record['vat_number'] ) ) {
			$record['vat_number'] = $section;
			$record['validation'] = LegacyData::validation_section( $legacy['status'], $legacy['source'] );
			$order->update_meta_data( DecisionStore::META, (string) wp_json_encode( $record ) );
			if ( '' !== $section['canonical'] ) {
				global $wpdb;
				$table = Schema::ledger_table();
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET customer_vat = %s WHERE order_id = %d AND customer_vat IS NULL", $section['canonical'], $order->get_id() ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
		}
		$order->save_meta_data();
		return true;
	}

	/**
	 * @param string[] $keys
	 */
	private function count_orders( array $keys, bool $pending ): int {
		global $wpdb;

		list( $where, $args ) = self::order_where( $keys, $pending );
		$column               = OrderUtil::custom_orders_table_usage_is_enabled() ? 'order_id' : 'post_id';
		return array() === $keys ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.{$column}) {$where}", $args ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * @param string[] $keys
	 * @return int[]
	 */
	private function order_ids( array $keys, int $after, int $limit ): array {
		global $wpdb;

		if ( array() === $keys ) {
			return array();
		}
		list( $where, $args ) = self::order_where( $keys, true );
		$column               = OrderUtil::custom_orders_table_usage_is_enabled() ? 'order_id' : 'post_id';
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.{$column} {$where} AND m.{$column} > %d ORDER BY m.{$column} LIMIT %d", array_merge( $args, array( $after, $limit ) ) ) ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * @param string[] $keys
	 */
	private function count_customers( array $keys, bool $pending ): int {
		global $wpdb;

		list( $where, $args ) = self::customer_where( $keys, $pending );
		return array() === $args ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.user_id) {$where}", $args ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Orders of the given types with one of the keys set, leaving out those
	 * whose key VAT Right wrote itself. Pending ones also have no number,
	 * import mark or removed-data mark of VAT Right's, and no decision other
	 * than an inferred one.
	 *
	 * @param string[] $keys
	 * @return array{0:string,1:array<int,string>}
	 */
	private static function order_where( array $keys, bool $pending ): array {
		global $wpdb;

		$hpos   = OrderUtil::custom_orders_table_usage_is_enabled();
		$meta   = $hpos ? OrderUtil::get_table_for_order_meta() : $wpdb->postmeta;
		$orders = $hpos ? OrderUtil::get_table_for_orders() : $wpdb->posts;
		$id     = $hpos ? 'order_id' : 'post_id';
		$key    = $hpos ? 'id' : 'ID';
		$type   = $hpos ? 'type' : 'post_type';

		$where = "FROM {$meta} m INNER JOIN {$orders} o ON o.{$key} = m.{$id}
			WHERE m.meta_key IN (" . self::placeholders( $keys ) . ") AND m.meta_value <> ''
			AND o.{$type} IN (" . self::placeholders( self::ORDER_TYPES ) . ")
			AND NOT EXISTS ( SELECT 1 FROM {$meta} c WHERE c.{$id} = m.{$id} AND c.meta_key = %s )";
		$args  = array_merge( $keys, self::ORDER_TYPES, array( CompatKeys::OWNED_META ) );
		if ( $pending ) {
			$where .= " AND NOT EXISTS ( SELECT 1 FROM {$meta} x WHERE x.{$id} = m.{$id} AND ( x.meta_key IN (%s, %s, %s) OR ( x.meta_key = %s AND x.meta_value NOT LIKE %s ) ) )";
			$args   = array_merge( $args, array( InputGatherer::NUMBER_META, LegacyData::IMPORTED_META, Retention::SCRUBBED_META, DecisionStore::META, '%' . $wpdb->esc_like( '"backfilled"' ) . '%' ) );
		}
		return array( $where, $args );
	}

	/**
	 * @param string[] $keys
	 * @return array{0:string,1:array<int,string>}
	 */
	private static function customer_where( array $keys, bool $pending ): array {
		global $wpdb;

		if ( array() === $keys ) {
			return array( '', array() );
		}
		$where = "FROM {$wpdb->usermeta} m WHERE m.meta_key IN (" . self::placeholders( $keys ) . ") AND m.meta_value <> ''";
		$args  = $keys;
		if ( $pending ) {
			$where .= " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->usermeta} x WHERE x.user_id = m.user_id AND x.meta_key = %s )";
			$args[] = InputGatherer::NUMBER_META;
		}
		return array( $where, $args );
	}

	/**
	 * @param string[] $values
	 */
	private static function placeholders( array $values ): string {
		return implode( ', ', array_fill( 0, count( $values ), '%s' ) );
	}

	/**
	 * @return string[]
	 */
	private static function sources( ?string $only ): array {
		return null === $only ? array_keys( LegacyData::SOURCES ) : array_intersect( array_keys( LegacyData::SOURCES ), array( $only ) );
	}

	/**
	 * @return string[]
	 */
	private static function keys( ?string $only, string $kind ): array {
		$keys = array();
		foreach ( self::sources( $only ) as $source ) {
			$definition = LegacyData::SOURCES[ $source ];
			$keys       = array_merge( $keys, 'user_number' === $kind ? $definition['user_number'] : $definition['order_number'] );
		}
		return array_values( array_unique( $keys ) );
	}
}
