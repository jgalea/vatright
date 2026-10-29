<?php

namespace Vatright\Ledger;

use DateTimeImmutable;
use Vatright\Domain\Decision;
use Vatright\Domain\Line;
use Vatright\Domain\LineDecision;
use Vatright\Domain\Rule;
use Vatright\Domain\Scheme;
use Vatright\Domain\Territory;
use Vatright\Migration\LegacyData;
use Vatright\Settings;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WC_Tax;

class Backfill {

	const HOOK     = 'vatright_backfill_batch';
	const BATCH    = 50;
	const AS_GROUP = 'vatright';

	/** @var LedgerWriter */
	private $writer;

	public function __construct( LedgerWriter $writer ) {
		$this->writer = $writer;
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
	}

	public static function schedule(): void {
		if ( ! as_has_scheduled_action( self::HOOK, array( 0 ), self::AS_GROUP ) ) {
			as_enqueue_async_action( self::HOOK, array( 0 ), self::AS_GROUP );
		}
	}

	public function run_batch( int $offset ): void {
		$since = new DateTimeImmutable( ( (int) wp_date( 'Y' ) - 1 ) . '-01-01', wp_timezone() );
		/** @var WC_Order[] $orders */
		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => array( 'processing', 'completed', 'refunded' ),
				'date_created' => '>=' . $since->getTimestamp(),
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'limit'        => self::BATCH,
				'offset'       => $offset,
			)
		);

		foreach ( $orders as $order ) {
			if ( 0 === $this->ledger_rows( $order->get_id() ) ) {
				$this->backfill( $order );
			}
		}

		if ( count( $orders ) === self::BATCH ) {
			as_enqueue_async_action( self::HOOK, array( $offset + self::BATCH ), self::AS_GROUP );
		}
	}

	private function backfill( WC_Order $order ): void {
		if ( '' === (string) $order->get_meta( LedgerWriter::DECISION_META ) ) {
			$order->update_meta_data( LedgerWriter::DECISION_META, (string) wp_json_encode( $this->infer( $order )->to_array() ) );
			$order->save_meta_data();
		}

		$this->writer->record_order( $order );
		foreach ( $order->get_refunds() as $refund ) {
			$this->writer->record_refund( $order->get_id(), $refund->get_id() );
		}
	}

	private function infer( WC_Order $order ): Decision {
		$settings = Settings::get();
		$store    = WC()->countries->get_base_country();
		$shipping = $order->get_shipping_country();
		$billing  = $order->get_billing_country();
		$customer = $order->needs_shipping_address() && '' !== $shipping ? $shipping : $billing;
		$postcode = $order->needs_shipping_address() && '' !== $shipping ? $order->get_shipping_postcode() : $order->get_billing_postcode();
		$charged  = $this->charged_country( $order );

		list( $scheme, $rule ) = $this->scheme_and_rule( $order, $store, $customer, $postcode, $charged );

		// A number imported from, or still only held by, another VAT plugin goes into the record with the verdict that plugin recorded.
		$imported   = json_decode( (string) $order->get_meta( LegacyData::IMPORTED_META ), true );
		$legacy     = is_array( $imported ) ? $imported : LegacyData::order( $order );
		$number     = LegacyData::number_section( LegacyData::order_number( $order ), $billing );
		$validation = null === $number || ! is_array( $legacy ) ? null : LegacyData::validation_section( (string) ( $legacy['status'] ?? '' ), (string) ( $legacy['source'] ?? '' ) );

		$lines = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$product = $item->get_product();
				$lines[] = new LineDecision(
					(string) $item->get_id(),
					$product instanceof WC_Product && $product->is_virtual() ? Line::DIGITAL : Line::GOODS,
					$customer,
					'' !== $charged ? $charged : $store,
					$scheme
				);
			}
		}

		return new Decision(
			wp_generate_uuid4(),
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			'backfill',
			array(
				'country'        => $store,
				'oss_registered' => $settings->oss_registered(),
				'threshold'      => array(
					'current_eur'  => '0.00',
					'previous_eur' => '0.00',
					'exceeded'     => false,
					'forced'       => false,
				),
			),
			array(
				'billing'      => array(
					'country'  => $billing,
					'postcode' => $order->get_billing_postcode(),
				),
				'shipping'     => array(
					'country'  => $shipping,
					'postcode' => $order->get_shipping_postcode(),
				),
				'local_pickup' => false,
			),
			$number,
			$validation,
			array(
				'billing'        => $billing,
				'shipping'       => $shipping,
				'ip'             => null,
				'self_certified' => null,
				'resolved'       => $customer,
				'conflict'       => false,
				'pieces'         => 1,
			),
			array(
				'outage'                   => $settings->outage_policy(),
				'allow_domestic_exemption' => $settings->allow_domestic_exemption(),
				'fixed_prices'             => $settings->fixed_prices(),
			),
			$lines,
			$scheme,
			$rule,
			'yes' === $order->get_meta( 'is_vat_exempt' ),
			array( 'backfilled' ),
			array(),
			null
		);
	}

	/**
	 * @return array{0:string,1:string}
	 */
	private function scheme_and_rule( WC_Order $order, string $store, string $customer, string $postcode, string $charged ): array {
		if ( 'yes' === $order->get_meta( 'is_vat_exempt' ) ) {
			return array( Scheme::REVERSE_CHARGE_SERVICES, Rule::RC_SERVICES_ART196 );
		}
		if ( ! Territory::in_vat_area( $customer, $postcode ) ) {
			return Territory::is_eu_member( Territory::vat_country( $customer ) )
				? array( Scheme::OUTSIDE_TERRITORY, Rule::OUTSIDE_TERRITORY )
				: array( Scheme::EXPORT, Rule::EXPORT_NON_EU );
		}
		$destination = Territory::vat_country( $customer );
		if ( Territory::vat_country( $store ) === $destination ) {
			return array( Scheme::DOMESTIC, Rule::DOMESTIC );
		}
		return $charged === $destination
			? array( Scheme::OSS_DESTINATION, Rule::B2C_DESTINATION )
			: array( Scheme::ORIGIN, Rule::B2C_ORIGIN_BELOW_THRESHOLD );
	}

	private function charged_country( WC_Order $order ): string {
		foreach ( $order->get_taxes() as $tax ) {
			$rate = WC_Tax::_get_tax_rate( $tax->get_rate_id(), ARRAY_A );
			if ( is_array( $rate ) && '' !== $rate['tax_rate_country'] ) {
				return Territory::vat_country( $rate['tax_rate_country'] );
			}
		}
		return '';
	}

	private function ledger_rows( int $order_id ): int {
		global $wpdb;

		$table = Schema::ledger_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id = %d", $order_id ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
