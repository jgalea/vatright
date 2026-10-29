<?php

namespace Vatright\Privacy;

use Vatright\Checkout\InputGatherer;
use Vatright\Checkout\VatField;
use Vatright\Domain\VatNumber;
use Vatright\Migration\LegacyData;
use Vatright\Orders\DecisionStore;
use Vatright\Registry\ValidationCache;
use Vatright\Ledger\Schema;
use Vatright\Settings;
use WC_Order;

class Privacy {

	const GROUP    = 'vatright';
	const PER_PAGE = 25;

	/**
	 * Where the customer's last-used number is saved on the account: ours,
	 * and the copy WooCommerce keeps for the additional checkout field.
	 */
	const ACCOUNT_META = array( InputGatherer::NUMBER_META, '_wc_other/' . VatField::FIELD_ID );

	/** @var ValidationCache */
	private $cache;

	public function __construct( ValidationCache $cache ) {
		$this->cache = $cache;
	}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * @param array<string,mixed> $exporters
	 * @return array<string,mixed>
	 */
	public function add_exporter( array $exporters ): array {
		$exporters[ self::GROUP ] = array(
			'exporter_friendly_name' => __( 'EU VAT records', 'vatright' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string,mixed> $erasers
	 * @return array<string,mixed>
	 */
	public function add_eraser( array $erasers ): array {
		$erasers[ self::GROUP ] = array(
			'eraser_friendly_name' => __( 'EU VAT records', 'vatright' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @return array{data: array<int,array<string,mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$orders  = $this->orders_for( $email, $page );
		$items   = array();
		$user    = 1 === $page ? get_user_by( 'email', $email ) : false;
		$user_id = false === $user ? 0 : $user->ID;
		$saved   = 0 === $user_id ? '' : $this->saved_number( $user_id );
		$legacy  = '' === $saved ? LegacyData::customer( $user_id ) : null;
		$saved   = null === $legacy ? $saved : $legacy['number'];

		if ( '' !== $saved ) {
			$items[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'EU VAT records', 'vatright' ),
				'item_id'     => 'vatright-account-' . $user_id,
				'data'        => array(
					array(
						'name'  => __( 'Saved VAT number', 'vatright' ),
						'value' => $saved,
					),
				),
			);
		}

		foreach ( $orders as $order ) {
			$record  = json_decode( (string) $order->get_meta( DecisionStore::META ), true );
			$record  = is_array( $record ) ? $record : array();
			$data    = array(
				array(
					'name'  => __( 'Order', 'vatright' ),
					'value' => $order->get_order_number(),
				),
				array(
					'name'  => __( 'VAT number', 'vatright' ),
					'value' => LegacyData::order_number( $order ),
				),
				array(
					'name'  => __( 'Registered name', 'vatright' ),
					'value' => (string) ( $record['validation']['name'] ?? '' ),
				),
				array(
					'name'  => __( 'Registered address', 'vatright' ),
					'value' => (string) ( $record['validation']['address'] ?? '' ),
				),
				array(
					'name'  => __( 'Consultation number', 'vatright' ),
					'value' => (string) ( $record['validation']['consultation'] ?? '' ),
				),
				array(
					'name'  => __( 'Country of IP address', 'vatright' ),
					'value' => (string) ( $record['evidence']['ip'] ?? '' ),
				),
			);
			$items[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'EU VAT records', 'vatright' ),
				'item_id'     => 'vatright-order-' . $order->get_id(),
				'data'        => array_values(
					array_filter(
						$data,
						static function ( array $row ): bool {
							return '' !== $row['value'];
						}
					)
				),
			);

			foreach ( $this->ledger_rows( $order->get_id() ) as $row ) {
				$items[] = array(
					'group_id'    => self::GROUP . '-ledger',
					'group_label' => __( 'EU VAT ledger', 'vatright' ),
					'item_id'     => 'vatright-ledger-' . $row['id'],
					'data'        => array(
						array(
							'name'  => __( 'Order', 'vatright' ),
							'value' => $order->get_order_number(),
						),
						array(
							'name'  => __( 'Period', 'vatright' ),
							'value' => $row['period'],
						),
						array(
							'name'  => __( 'Scheme', 'vatright' ),
							'value' => $row['scheme'],
						),
						array(
							'name'  => __( 'VAT number', 'vatright' ),
							'value' => (string) $row['customer_vat'],
						),
						array(
							'name'  => __( 'Net', 'vatright' ),
							'value' => $row['net'] . ' ' . $row['currency'],
						),
						array(
							'name'  => __( 'VAT', 'vatright' ),
							'value' => $row['vat'] . ' ' . $row['currency'],
						),
					),
				);
			}
		}

		return array(
			'data' => $items,
			'done' => count( $orders ) < self::PER_PAGE,
		);
	}

	/**
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		$orders   = $this->orders_for( $email, $page );
		$removed  = false;
		$retained = false;
		$messages = array();
		$stores   = Settings::get()->store_requesters();
		$user     = get_user_by( 'email', $email );

		// The account copy is not a VAT record (each order keeps its own), so it goes regardless of retention.
		$legacy = false === $user ? null : LegacyData::customer( $user->ID );
		if ( 1 === $page && false !== $user && ( '' !== $this->saved_number( $user->ID ) || null !== $legacy ) ) {
			foreach ( array( $this->saved_number( $user->ID ), null === $legacy ? '' : $legacy['number'] ) as $saved ) {
				$number = VatNumber::parse( $saved, '' );
				if ( null === $number ) {
					continue;
				}
				$this->cache->forget( $number, null );
				foreach ( $stores as $store ) {
					$this->cache->forget( $number, $store );
				}
			}
			foreach ( self::ACCOUNT_META as $key ) {
				delete_user_meta( $user->ID, $key );
			}
			foreach ( LegacyData::SOURCES as $definition ) {
				foreach ( $definition['user_number'] as $key ) {
					delete_user_meta( $user->ID, $key );
				}
			}
			// An empty value is a cleared number, so no older key can bring it back.
			update_user_meta( $user->ID, InputGatherer::NUMBER_META, '' );
			$removed = true;
		}

		foreach ( $orders as $order ) {
			if ( ! Retention::holds_personal_data( $order ) ) {
				continue;
			}

			$number = VatNumber::parse( LegacyData::order_number( $order ), $order->get_billing_country() );
			if ( null !== $number ) {
				$this->cache->forget( $number, null );
				foreach ( $stores as $store ) {
					$this->cache->forget( $number, $store );
				}
			}

			if ( Retention::is_retained( $order ) ) {
				$retained   = true;
				$messages[] = sprintf(
					/* translators: 1: order number, 2: date */
					__( 'The VAT number and registry check for order %1$s are kept until %2$s because tax law requires VAT records to be kept.', 'vatright' ),
					$order->get_order_number(),
					wp_date( get_option( 'date_format' ), Retention::retained_until( $order )->getTimestamp() )
				);
				continue;
			}

			$removed = Retention::scrub( $order ) || $removed;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => count( $orders ) < self::PER_PAGE,
		);
	}

	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'VAT Right – EU VAT & OSS for WooCommerce', wp_kses_post( wpautop( $this->policy_text() ) ) );
	}

	public function policy_text(): string {
		return sprintf(
			/* translators: %d: number of years */
			__(
				'When a customer enters a VAT number at checkout, we check it with the official registry for its country: VIES (European Commission) for EU numbers, and HMRC (UK), the Swiss UID register or the Brønnøysund Register (Norway) for those countries. The registry receives the VAT number and, for VIES, our own VAT number as the requester. The registry returns whether the number is valid and, where it publishes them, the registered name and address.

We store the VAT number, the registry result including that name and address and the consultation number, and the country of the customer\'s IP address with the order. We use the IP country, together with the billing and shipping country, as evidence of where the sale takes place. Registry results are also cached for a short period.

EU VAT rules require us to keep these records for %d years after the end of the year of the sale. Within that period a request to erase them cannot be met, and we keep only what the VAT records need. After that period the VAT number, registered name and address, consultation number and IP country are removed from the order and the VAT ledger automatically.',
				'vatright'
			),
			Settings::get()->retention_years()
		);
	}

	private function saved_number( int $user_id ): string {
		foreach ( self::ACCOUNT_META as $key ) {
			$value = (string) get_user_meta( $user_id, $key, true );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * @return WC_Order[]
	 */
	private function orders_for( string $email, int $page ): array {
		$customers = array( $email );
		$user      = get_user_by( 'email', $email );
		if ( false !== $user ) {
			$customers[] = $user->ID;
		}

		/** @var WC_Order[] $orders */
		$orders = wc_get_orders(
			array(
				'type'     => 'shop_order',
				'status'   => 'any',
				'customer' => $customers,
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'limit'    => self::PER_PAGE,
				'paged'    => max( 1, $page ),
			)
		);
		return $orders;
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function ledger_rows( int $order_id ): array {
		global $wpdb;

		$table = Schema::ledger_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, period, scheme, customer_vat, net, vat, currency FROM {$table} WHERE order_id = %d ORDER BY id", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
