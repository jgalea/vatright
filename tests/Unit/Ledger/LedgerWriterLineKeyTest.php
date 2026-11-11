<?php

namespace Vatright\Tests\Unit\Ledger;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Vatright\Checkout\InputGatherer;
use Vatright\Domain\Decision;
use Vatright\Domain\LineDecision;
use Vatright\Ledger\LedgerWriter;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Refund;

require_once dirname( __DIR__, 2 ) . '/Support/WcOrderFakes.php';

class LedgerWriterLineKeyTest extends TestCase {

	private function item( int $id, string $cart_key, float $net, int $rate ): WC_Order_Item_Product {
		$item                          = new WC_Order_Item_Product();
		$item->id                      = $id;
		$item->meta                    = array( InputGatherer::LINE_KEY_META => $cart_key );
		$item->total                   = $net;
		$item->taxes['total'][ $rate ] = '0';
		return $item;
	}

	private function decision(): Decision {
		return new Decision(
			'id-1',
			'2026-10-17T12:00:00Z',
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
			null,
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
			array(
				new LineDecision( 'cartkeygoods', 'goods', 'MT', 'MT', 'domestic' ),
				new LineDecision( 'cartkeybook', 'services', 'DE', 'DE', 'reverse_charge' ),
			),
			'mixed',
			'mixed',
			false,
			array(),
			array(),
			null
		);
	}

	private function buckets( $source, WC_Order $order ): array {
		$writer = ( new \ReflectionClass( LedgerWriter::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( LedgerWriter::class, 'buckets' );
		$method->setAccessible( true );
		return $method->invoke( $writer, $source, $order, $this->decision() );
	}

	public function test_order_items_resolve_their_line_by_the_cart_key_checkout_stores(): void {
		$order        = new WC_Order();
		$order->items = array(
			41 => $this->item( 41, 'cartkeygoods', 10.0, 1 ),
			42 => $this->item( 42, 'cartkeybook', 20.0, 2 ),
		);

		$schemes = array_map(
			static function ( array $bucket ): string {
				return $bucket['line']->scheme();
			},
			array_values( $this->buckets( $order, $order ) )
		);

		$this->assertSame( array( 'domestic', 'reverse_charge' ), $schemes );
	}

	public function test_refund_items_resolve_through_the_original_items_cart_key(): void {
		$order                                  = new WC_Order();
		$order->items                           = array(
			41 => $this->item( 41, 'cartkeygoods', 10.0, 1 ),
			42 => $this->item( 42, 'cartkeybook', 20.0, 2 ),
		);
		$refund_item                            = $this->item( 99, '', -20.0, 2 );
		$refund_item->meta['_refunded_item_id'] = '42';
		$refund                                 = new WC_Order_Refund();
		$refund->items                          = array( 99 => $refund_item );

		$buckets = array_values( $this->buckets( $refund, $order ) );

		$this->assertCount( 1, $buckets );
		$this->assertSame( 'reverse_charge', $buckets[0]['line']->scheme() );
	}
}
