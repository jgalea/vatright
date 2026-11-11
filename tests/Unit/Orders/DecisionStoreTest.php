<?php

namespace Vatright\Tests\Unit\Orders;

use PHPUnit\Framework\TestCase;
use Vatright\Orders\DecisionStore;
use WC_Order;

require_once dirname( __DIR__, 2 ) . '/Support/WcOrderFakes.php';
require_once dirname( __DIR__, 2 ) . '/Support/WcFunctionStubs.php';

class DecisionStoreTest extends TestCase {

	private function order_with( string $json ): WC_Order {
		$order             = new WC_Order();
		$order->order_meta = array( DecisionStore::META => $json );
		return $order;
	}

	public function test_a_partial_record_reads_as_no_decision(): void {
		$this->assertNull( DecisionStore::get( $this->order_with( '{"id":"x","scheme":"domestic"}' ) ) );
	}

	public function test_a_record_with_the_wrong_types_reads_as_no_decision(): void {
		$keys = array_fill_keys( DecisionStore::REQUIRED_KEYS, 'x' );
		$json = (string) json_encode( $keys ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertNull( DecisionStore::get( $this->order_with( $json ) ) );
	}

	public function test_text_that_is_not_a_record_reads_as_no_decision(): void {
		$this->assertNull( DecisionStore::get( $this->order_with( 'not json' ) ) );
	}
}
