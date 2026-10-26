<?php

namespace Vatright\Tests\Integration\Orders;

use Vatright\Domain\ValidationResult;
use Vatright\Orders\DecisionStore;
use Vatright\Orders\OrdersColumn;
use WC_Order;

require_once __DIR__ . '/OrderTestCase.php';

class OrdersColumnTest extends OrderTestCase {

	const NUMBER = 'DE114103379';

	public function tear_down() {
		unset( $_GET[ OrdersColumn::FILTER ] );
		parent::tear_down();
	}

	private function column(): OrdersColumn {
		return new OrdersColumn();
	}

	private function cell( WC_Order $order ): string {
		ob_start();
		$this->column()->render_hpos( OrdersColumn::KEY, $order );
		return (string) ob_get_clean();
	}

	private function decided( string $status, bool $pending = false ): WC_Order {
		$order    = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		$decision = $this->decide( 'DE', 'DE', '10115', array( 'digital' ), false, self::NUMBER, $status );
		DecisionStore::apply( $order, $pending ? $decision->with_flag( 'validation_pending' ) : $decision, 'checkout' );
		$order->save();
		return $order;
	}

	/**
	 * @return int[]
	 */
	private function filtered_ids( string $filter ): array {
		$_GET[ OrdersColumn::FILTER ] = $filter;
		$args                         = $this->column()->filter_hpos_args( array() );
		$ids                          = wc_get_orders(
			array_merge(
				$args,
				array(
					'return' => 'ids',
					'limit'  => -1,
				)
			)
		);
		$ids                          = array_map( 'intval', $ids );
		sort( $ids );
		return $ids;
	}

	public function test_column_is_added_before_the_total(): void {
		$columns = $this->column()->add_column(
			array(
				'cb'           => '',
				'order_number' => 'Order',
				'order_status' => 'Status',
				'order_total'  => 'Total',
				'wc_actions'   => 'Actions',
			)
		);
		$keys    = array_keys( $columns );
		$this->assertSame( array_search( OrdersColumn::KEY, $keys, true ) + 1, array_search( 'order_total', $keys, true ) );
		$this->assertSame( 'VAT', $columns[ OrdersColumn::KEY ] );
	}

	public function test_hooks_cover_hpos_and_legacy_screens(): void {
		$this->column()->register();
		$this->assertNotFalse( has_filter( 'manage_woocommerce_page_wc-orders_columns' ) );
		$this->assertNotFalse( has_action( 'manage_woocommerce_page_wc-orders_custom_column' ) );
		$this->assertNotFalse( has_filter( 'manage_edit-shop_order_columns' ) );
		$this->assertNotFalse( has_action( 'manage_shop_order_posts_custom_column' ) );
	}

	public function test_valid_reverse_charge_shows_number_status_and_treatment(): void {
		$html = $this->cell( $this->decided( ValidationResult::VALID ) );
		$this->assertStringContainsString( self::NUMBER, $html );
		$this->assertStringContainsString( 'Valid', $html );
		$this->assertStringContainsString( 'Reverse charge', $html );
	}

	public function test_invalid_number_is_labelled_invalid(): void {
		$this->assertStringContainsString( 'Invalid', $this->cell( $this->decided( ValidationResult::INVALID ) ) );
	}

	public function test_pending_flag_shows_pending_validation(): void {
		$this->assertStringContainsString( 'Pending validation', $this->cell( $this->decided( ValidationResult::UNAVAILABLE, true ) ) );
	}

	public function test_consumer_order_shows_treatment_without_a_number(): void {
		$order = $this->order( 'FR', 'FR', '75001', array( 'goods' ) );
		DecisionStore::apply( $order, $this->decide( 'FR', 'FR', '75001', array( 'goods' ), true ), 'checkout' );
		$order->save();
		$html = $this->cell( $order );
		$this->assertStringContainsString( 'OSS destination', $html );
		$this->assertStringNotContainsString( 'Valid', $html );
	}

	public function test_origin_treatment_label(): void {
		$order = $this->order( 'FR', 'FR', '75001', array( 'goods' ) );
		DecisionStore::apply( $order, $this->decide( 'FR', 'FR', '75001', array( 'goods' ) ), 'checkout' );
		$order->save();
		$this->assertStringContainsString( 'Origin', $this->cell( $order ) );
	}

	public function test_order_without_a_decision_renders_nothing(): void {
		$order = $this->order( 'DE', 'DE', '10115', array( 'goods' ) );
		$this->assertSame( '', trim( $this->cell( $order ) ) );
	}

	public function test_stored_text_is_escaped(): void {
		$order                             = $this->decided( ValidationResult::VALID );
		$record                            = json_decode( (string) $order->get_meta( DecisionStore::META ), true );
		$record['vat_number']['canonical'] = '<script>alert(1)</script>';
		$order->update_meta_data( DecisionStore::META, wp_json_encode( $record ) );
		$order->save();
		$html = $this->cell( $order );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_legacy_renderer_loads_the_order_by_post_id(): void {
		$order = $this->decided( ValidationResult::VALID );
		ob_start();
		$this->column()->render_legacy( OrdersColumn::KEY, $order->get_id() );
		$this->assertStringContainsString( 'Reverse charge', (string) ob_get_clean() );
	}

	public function test_other_columns_are_left_alone(): void {
		$order = $this->decided( ValidationResult::VALID );
		ob_start();
		$this->column()->render_hpos( 'order_total', $order );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_no_filter_leaves_the_query_alone(): void {
		$this->assertSame( array( 'limit' => 20 ), $this->column()->filter_hpos_args( array( 'limit' => 20 ) ) );
		$_GET[ OrdersColumn::FILTER ] = 'nonsense';
		$this->assertSame( array( 'limit' => 20 ), $this->column()->filter_hpos_args( array( 'limit' => 20 ) ) );
	}

	public function test_filter_selects_the_right_orders(): void {
		$pending = $this->decided( ValidationResult::UNAVAILABLE, true );
		$clean   = $this->decided( ValidationResult::VALID );
		$other   = $this->order( 'DE', 'DE', '10115', array( 'goods' ) );
		DecisionStore::apply( $other, $this->decide( 'DE', 'DE', '10115', array( 'goods' ) )->with_flag( 'single_evidence' ), 'checkout' );
		$other->save();

		$ids = $this->filtered_ids( 'pending' );
		$this->assertContains( $pending->get_id(), $ids );
		$this->assertNotContains( $clean->get_id(), $ids );
		$this->assertNotContains( $other->get_id(), $ids );

		$ids = $this->filtered_ids( 'flagged' );
		$this->assertContains( $pending->get_id(), $ids );
		$this->assertContains( $other->get_id(), $ids );
		$this->assertNotContains( $clean->get_id(), $ids );
	}

	public function test_legacy_request_filter_only_touches_the_order_list(): void {
		$_GET[ OrdersColumn::FILTER ] = 'pending';
		$vars                         = $this->column()->filter_legacy_request( array( 'post_type' => 'shop_order' ) );
		$this->assertStringContainsString( 'validation_pending', wp_json_encode( $vars['meta_query'] ) );
		$this->assertSame( array( 'post_type' => 'post' ), $this->column()->filter_legacy_request( array( 'post_type' => 'post' ) ) );
	}

	public function test_dropdown_lists_the_options_and_marks_the_selection(): void {
		$_GET[ OrdersColumn::FILTER ] = 'flagged';
		ob_start();
		$this->column()->render_dropdown( 'shop_order', 'top' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="' . OrdersColumn::FILTER . '"', $html );
		$this->assertMatchesRegularExpression( '/value="flagged"\s+selected/', $html );

		ob_start();
		$this->column()->render_dropdown( 'post', 'top' );
		$this->assertSame( '', ob_get_clean() );
	}
}
