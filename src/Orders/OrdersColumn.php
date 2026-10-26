<?php

namespace Vatright\Orders;

use Vatright\Domain\Rule;
use Vatright\Domain\Scheme;
use Vatright\Domain\ValidationResult;
use WC_Order;

class OrdersColumn {

	const KEY = 'vatright_vat';

	const FILTER = 'vatright_vat_filter';

	const PENDING = 'pending';

	const FLAGGED = 'flagged';

	public function register(): void {
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy' ), 10, 2 );

		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_dropdown' ), 10, 2 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_hpos_args' ) );
		add_action( 'restrict_manage_posts', array( $this, 'render_dropdown' ), 10, 2 );
		add_filter( 'request', array( $this, 'filter_legacy_request' ) );
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function add_column( $columns ): array {
		$columns = (array) $columns;
		$result  = array();
		foreach ( $columns as $key => $label ) {
			if ( 'order_total' === $key ) {
				$result[ self::KEY ] = __( 'VAT', 'vatright' );
			}
			$result[ $key ] = $label;
		}
		if ( ! isset( $result[ self::KEY ] ) ) {
			$result[ self::KEY ] = __( 'VAT', 'vatright' );
		}
		return $result;
	}

	/**
	 * @param mixed $order
	 */
	public function render_hpos( string $column, $order ): void {
		if ( self::KEY === $column && $order instanceof WC_Order ) {
			$this->render( $order );
		}
	}

	/**
	 * @param int|string $post_id
	 */
	public function render_legacy( string $column, $post_id ): void {
		if ( self::KEY !== $column ) {
			return;
		}
		$order = wc_get_order( (int) $post_id );
		if ( $order instanceof WC_Order ) {
			$this->render( $order );
		}
	}

	public function render_dropdown( string $order_type, string $which = 'top' ): void {
		if ( 'shop_order' !== $order_type || 'top' !== $which ) {
			return;
		}
		$current = $this->selected_filter();
		echo '<select name="' . esc_attr( self::FILTER ) . '">';
		echo '<option value="">' . esc_html__( 'All VAT statuses', 'vatright' ) . '</option>';
		echo '<option value="' . esc_attr( self::PENDING ) . '" ' . selected( $current, self::PENDING, false ) . '>' . esc_html__( 'VAT pending validation', 'vatright' ) . '</option>';
		echo '<option value="' . esc_attr( self::FLAGGED ) . '" ' . selected( $current, self::FLAGGED, false ) . '>' . esc_html__( 'VAT flagged', 'vatright' ) . '</option>';
		echo '</select>';
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function filter_hpos_args( $args ): array {
		$args  = (array) $args;
		$query = $this->meta_query();
		if ( array() === $query ) {
			return $args;
		}
		$args['meta_query'] = array_merge( (array) ( $args['meta_query'] ?? array() ), $query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $args;
	}

	/**
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	public function filter_legacy_request( $vars ): array {
		$vars = (array) $vars;
		if ( ( $vars['post_type'] ?? '' ) !== 'shop_order' ) {
			return $vars;
		}
		$query = $this->meta_query();
		if ( array() !== $query ) {
			$vars['meta_query'] = array_merge( (array) ( $vars['meta_query'] ?? array() ), $query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		return $vars;
	}

	public static function treatment( string $rule, string $scheme ): string {
		switch ( $rule ) {
			case Rule::RC_GOODS_ART138:
			case Rule::RC_SERVICES_ART196:
				return __( 'Reverse charge', 'vatright' );
			case Rule::B2C_DESTINATION:
				return __( 'OSS destination', 'vatright' );
			case Rule::B2C_ORIGIN_BELOW_THRESHOLD:
			case Rule::B2C_SERVICE_ORIGIN:
				return __( 'Origin', 'vatright' );
			case Rule::DOMESTIC:
			case Rule::DOMESTIC_B2B_GUARD:
			case Rule::DOMESTIC_B2B_ALLOWED:
			case Rule::LOCAL_PICKUP_DOMESTIC:
				return __( 'Domestic', 'vatright' );
			case Rule::EXPORT_NON_EU:
			case Rule::OUTSIDE_TERRITORY:
			case Rule::NON_EU_NUMBER_RECORDED:
				return __( 'No EU VAT', 'vatright' );
			case Rule::PENDING_EXEMPT:
				return __( 'Exempt', 'vatright' );
			case Rule::PENDING_CHARGED:
			case Rule::INVALID_NUMBER:
			case Rule::PREFIX_MISMATCH_CHARGED:
				return __( 'VAT charged', 'vatright' );
		}
		switch ( $scheme ) {
			case Scheme::OSS_DESTINATION:
				return __( 'OSS destination', 'vatright' );
			case Scheme::ORIGIN:
				return __( 'Origin', 'vatright' );
			case Scheme::DOMESTIC:
				return __( 'Domestic', 'vatright' );
			case Scheme::REVERSE_CHARGE_SERVICES:
			case Scheme::INTRA_EU_GOODS:
				return __( 'Reverse charge', 'vatright' );
		}
		return $rule;
	}

	private function render( WC_Order $order ): void {
		$decision = DecisionStore::get( $order );
		if ( null === $decision ) {
			return;
		}
		$record = $decision->to_array();
		$flags  = (array) $record['flags'];
		$number = (string) ( $record['vat_number']['canonical'] ?? '' );

		echo '<div class="vatright-vat">';
		if ( '' !== $number ) {
			echo '<strong>' . esc_html( $number ) . '</strong> <span class="vatright-vat-status">' . esc_html( $this->status( $record, $flags ) ) . '</span><br />';
		}
		echo '<span class="description">' . esc_html( self::treatment( (string) $record['rule'], (string) $record['scheme'] ) ) . '</span>';
		if ( array() !== $flags && ! in_array( 'validation_pending', $flags, true ) ) {
			$titles = implode( ' ', array_map( array( MetaBox::class, 'flag_text' ), $flags ) );
			echo ' <abbr class="vatright-vat-flagged" title="' . esc_attr( $titles ) . '">' . esc_html__( 'Flagged', 'vatright' ) . '</abbr>';
		}
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $record
	 * @param string[]            $flags
	 */
	private function status( array $record, array $flags ): string {
		if ( in_array( 'validation_pending', $flags, true ) ) {
			return __( 'Pending validation', 'vatright' );
		}
		switch ( (string) ( $record['validation']['status'] ?? '' ) ) {
			case ValidationResult::VALID:
				return __( 'Valid', 'vatright' );
			case ValidationResult::INVALID:
			case ValidationResult::FORMAT_INVALID:
				return __( 'Invalid', 'vatright' );
		}
		return __( 'Not checked', 'vatright' );
	}

	private function selected_filter(): string {
		$value = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $value, array( self::PENDING, self::FLAGGED ), true ) ? $value : '';
	}

	/**
	 * Matches the JSON the decision is stored as; flags are a list of quoted strings.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function meta_query(): array {
		$filter = $this->selected_filter();
		if ( '' === $filter ) {
			return array();
		}
		return array(
			array(
				'key'     => DecisionStore::META,
				'value'   => self::PENDING === $filter ? '"validation_pending"' : '"flags":["',
				'compare' => 'LIKE',
			),
		);
	}
}
