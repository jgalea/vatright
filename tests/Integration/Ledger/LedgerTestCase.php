<?php

namespace Vatright\Tests\Integration\Ledger;

use Vatright\Checkout\InputGatherer;
use Vatright\Domain\Decision;
use Vatright\Domain\LineDecision;
use Vatright\Domain\Rule;
use Vatright\Domain\Scheme;
use Vatright\Ledger\Fx;
use Vatright\Ledger\LedgerWriter;
use Vatright\Ledger\Schema;
use Vatright\Ledger\ThresholdTracker;
use Vatright\Settings;
use Vatright\Tests\Support\IntegrationTestCase;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Item_Tax;
use WC_Tax;

require_once __DIR__ . '/EcbFixtureTransport.php';

abstract class LedgerTestCase extends IntegrationTestCase {

	/** @var EcbFixtureTransport */
	protected $transport;

	/** @var LedgerWriter */
	protected $writer;

	protected function storage_is_hpos(): bool {
		return true;
	}

	public function set_up() {
		parent::set_up();
		if ( ! $this->storage_is_hpos() ) {
			add_filter(
				'pre_option_woocommerce_custom_orders_table_enabled',
				static function () {
					return 'no';
				},
				20
			);
		}
		$this->install_schema();
		Settings::reset();
		delete_option( Settings::OPTION );
		update_option( 'woocommerce_default_country', 'MT' );
		update_option( 'timezone_string', 'UTC' );
		ThresholdTracker::flush();

		// The booted plugin's own writer (live transport) listens on these too.
		remove_all_actions( LedgerWriter::FX_RETRY_HOOK );
		remove_all_actions( \Vatright\Ledger\Backfill::HOOK );

		$this->transport = new EcbFixtureTransport();
		$this->writer    = new LedgerWriter( new Fx( $this->transport ) );
	}

	protected function tax_rate( string $country, string $percent ): int {
		return (int) WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => $country,
				'tax_rate'          => $percent,
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => '',
			)
		);
	}

	/**
	 * Amounts are net; $rate_id and $percent give the tax on each. An item
	 * without a rate id carries no tax.
	 *
	 * @param array<string,mixed> $args items, shipping, fees (each array{name,net,rate_id?,percent?}), currency, created, paid, status, billing_country
	 */
	protected function make_order( array $args = array() ): WC_Order {
		$order = new WC_Order();
		$order->set_currency( $args['currency'] ?? 'EUR' );
		$order->set_billing_country( $args['billing_country'] ?? 'DE' );
		$order->set_date_created( strtotime( ( $args['created'] ?? '2026-09-15 10:00:00' ) . ' UTC' ) );
		$order->set_date_paid( strtotime( ( $args['paid'] ?? $args['created'] ?? '2026-09-15 10:00:00' ) . ' UTC' ) );

		$total = 0.0;
		$vat   = array();
		$sets  = array(
			'items'    => WC_Order_Item_Product::class,
			'shipping' => WC_Order_Item_Shipping::class,
			'fees'     => WC_Order_Item_Fee::class,
		);
		foreach ( $sets as $key => $class ) {
			foreach ( $args[ $key ] ?? array() as $spec ) {
				$item = new $class();
				if ( 'items' === $key ) {
					$item->set_name( $spec['name'] );
					$item->set_quantity( 1 );
					$item->set_subtotal( (string) $spec['net'] );
				} elseif ( 'shipping' === $key ) {
					$item->set_method_title( $spec['name'] );
				} else {
					$item->set_name( $spec['name'] );
				}
				$item->set_total( (string) $spec['net'] );

				$tax = 0.0;
				if ( isset( $spec['rate_id'] ) ) {
					$tax = round( $spec['net'] * $spec['percent'] / 100, 2 );
					$item->set_taxes(
						array(
							'total'    => array( $spec['rate_id'] => (string) $tax ),
							'subtotal' => array( $spec['rate_id'] => (string) $tax ),
						)
					);
					$vat[ $spec['rate_id'] ] = $vat[ $spec['rate_id'] ] ?? array(
						'percent'  => $spec['percent'],
						'tax'      => 0.0,
						'shipping' => 0.0,
					);
					$vat[ $spec['rate_id'] ][ 'shipping' === $key ? 'shipping' : 'tax' ] += $tax;
				}
				$order->add_item( $item );
				$total += $spec['net'] + $tax;
			}
		}
		foreach ( $vat as $rate_id => $sum ) {
			$tax_item = new WC_Order_Item_Tax();
			$tax_item->set_rate_id( $rate_id );
			$tax_item->set_rate_code( 'VAT-' . $rate_id );
			$tax_item->set_label( 'VAT' );
			$tax_item->set_compound( false );
			$tax_item->set_tax_total( (string) $sum['tax'] );
			$tax_item->set_shipping_tax_total( (string) $sum['shipping'] );
			$tax_item->set_rate_percent( $sum['percent'] );
			$order->add_item( $tax_item );
		}
		$order->set_total( (string) $total );
		$order->set_status( $args['status'] ?? 'processing' );
		$order->save();

		return $order;
	}

	/**
	 * Gives every product line the same treatment unless $lines names one per
	 * item name.
	 *
	 * @param array<string,array{0:string,1:string,2:string}> $lines item name => [supply_type, place_of_supply, scheme]
	 */
	protected function seed_decision( WC_Order $order, string $scheme = Scheme::OSS_DESTINATION, string $rule = Rule::B2C_DESTINATION, array $lines = array(), ?string $vat_number = null ): Decision {
		$line_decisions = array();
		foreach ( $order->get_items() as $item ) {
			$spec     = $lines[ $item->get_name() ] ?? array( 'goods', 'DE', $scheme );
			$cart_key = 'cartkey' . $item->get_id();
			$item->update_meta_data( InputGatherer::LINE_KEY_META, $cart_key );
			$item->save();
			$line_decisions[] = new LineDecision( $cart_key, $spec[0], $spec[1], $spec[1], $spec[2] );
		}

		$decision = new Decision(
			'3f1c1f0e-0000-4000-8000-000000000001',
			'2026-09-15T10:00:00Z',
			'checkout',
			array(
				'country'        => 'MT',
				'oss_registered' => false,
				'threshold'      => array(
					'current_eur'  => '0.00',
					'previous_eur' => '0.00',
					'exceeded'     => false,
					'forced'       => false,
				),
			),
			array(
				'billing'      => array(
					'country'  => 'DE',
					'postcode' => '10115',
				),
				'shipping'     => array(
					'country'  => 'DE',
					'postcode' => '10115',
				),
				'local_pickup' => false,
			),
			null === $vat_number ? null : array(
				'raw'       => $vat_number,
				'canonical' => $vat_number,
				'prefix'    => substr( $vat_number, 0, 2 ),
				'country'   => substr( $vat_number, 0, 2 ),
			),
			null,
			array(
				'billing'        => 'DE',
				'shipping'       => 'DE',
				'ip'             => 'DE',
				'self_certified' => null,
				'resolved'       => 'DE',
				'conflict'       => false,
				'pieces'         => 3,
			),
			array(
				'outage'                   => 'charge',
				'allow_domestic_exemption' => false,
				'fixed_prices'             => false,
			),
			$line_decisions,
			$scheme,
			$rule,
			Scheme::REVERSE_CHARGE_SERVICES === $scheme,
			array(),
			array(),
			null
		);

		$order->update_meta_data( LedgerWriter::DECISION_META, wp_json_encode( $decision->to_array() ) );
		$order->save_meta_data();
		return $decision;
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	protected function ledger_rows( string $where = '1=1' ): array {
		global $wpdb;

		$table = Schema::ledger_table();
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB
	}
}
