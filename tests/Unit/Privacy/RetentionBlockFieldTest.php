<?php

namespace Vatright\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Vatright\Privacy\Retention;
use WC_Order;

require_once dirname( __DIR__, 2 ) . '/Support/WcOrderFakes.php';

class RetentionBlockFieldTest extends TestCase {

	public function test_the_block_checkout_copy_of_the_number_counts_as_personal_data(): void {
		$order             = new WC_Order();
		$order->order_meta = array( '_wc_other/vatright/vat-number' => 'DE123456789' );

		$this->assertTrue( Retention::holds_personal_data( $order ) );
	}

	public function test_an_order_with_no_number_anywhere_holds_none(): void {
		$this->assertFalse( Retention::holds_personal_data( new WC_Order() ) );
	}
}
