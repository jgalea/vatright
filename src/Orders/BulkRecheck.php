<?php

namespace Vatright\Orders;

use WC_Order;

class BulkRecheck {

	const ACTION = 'vatright_recheck';

	const HOOK = 'vatright_bulk_recheck';

	const AS_GROUP = 'vatright';

	const BATCH = 5;

	const QUEUED_ARG = 'vatright_recheck_queued';

	/** @var Revalidation */
	private $revalidation;

	public function __construct( Revalidation $revalidation ) {
		$this->revalidation = $revalidation;
	}

	public function register(): void {
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_action' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle' ), 10, 3 );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_action' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle' ), 10, 3 );
		add_action( self::HOOK, array( $this, 'run_batch' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public function add_action( $actions ): array {
		$actions                 = (array) $actions;
		$actions[ self::ACTION ] = __( 'Re-check VAT numbers', 'vatright' );
		return $actions;
	}

	/**
	 * Each re-check asks the registry, so the orders are queued and checked a
	 * few at a time in the background.
	 *
	 * @param string           $redirect
	 * @param string           $action
	 * @param array<int|string> $ids
	 */
	public function handle( $redirect, $action, $ids ): string {
		$redirect = (string) $redirect;
		if ( self::ACTION !== $action ) {
			return $redirect;
		}
		$allowed = array_values(
			array_filter(
				array_map( 'absint', (array) $ids ),
				static function ( int $id ): bool {
					return current_user_can( 'edit_shop_order', $id ); // phpcs:ignore WordPress.WP.Capabilities.Unknown
				}
			)
		);
		foreach ( array_chunk( $allowed, self::BATCH ) as $batch ) {
			as_enqueue_async_action( self::HOOK, array( $batch, get_current_user_id() ), self::AS_GROUP );
		}
		return add_query_arg( array( self::QUEUED_ARG => count( $allowed ) ), $redirect );
	}

	/**
	 * @param int[] $ids
	 */
	public function run_batch( array $ids, int $actor ): void {
		foreach ( $ids as $id ) {
			$order = wc_get_order( (int) $id );
			if ( $order instanceof WC_Order && user_can( $actor, 'edit_shop_order', $id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
				$this->revalidation->recheck( $order, 'admin', $actor, true );
			}
		}
		$this->revalidation->send_held_emails();
	}

	public function notice(): void {
		if ( ! isset( $_GET[ self::QUEUED_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$queued  = absint( $_GET[ self::QUEUED_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = sprintf(
			/* translators: %d: number of orders. */
			_n( '%d order is queued for a VAT number re-check. The result is added to its order notes.', '%d orders are queued for a VAT number re-check. The results are added to their order notes.', $queued, 'vatright' ),
			$queued
		);
		echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
	}
}
