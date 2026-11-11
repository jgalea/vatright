<?php

namespace Vatright\Tests\Unit\Orders;

use PHPUnit\Framework\TestCase;
use Vatright\Orders\BulkRecheck;
use Vatright\Orders\Revalidation;
use WC_Order;

require_once dirname( __DIR__, 2 ) . '/Support/WpOptionStubs.php';
require_once dirname( __DIR__, 2 ) . '/Support/WcOrderFakes.php';
require_once dirname( __DIR__, 2 ) . '/Support/TransientStub.php';
require_once dirname( __DIR__, 2 ) . '/Support/WcFunctionStubs.php';

class BulkRecheckTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['vatright_test_async'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['vatright_test_denied'], $GLOBALS['vatright_test_async'] );
	}

	/**
	 * @return array{0: BulkRecheck, 1: \stdClass}
	 */
	private function bulk( string $result = Revalidation::VALID ): array {
		$counter       = new \stdClass();
		$counter->n    = 0;
		$counter->held = 0;
		$revalidation  = $this->createMock( Revalidation::class );
		$revalidation->method( 'recheck' )->willReturnCallback(
			static function () use ( $counter, $result ): string {
				++$counter->n;
				return $result;
			}
		);
		$revalidation->method( 'send_held_emails' )->willReturnCallback(
			static function () use ( $counter ): void {
				++$counter->held;
			}
		);
		return array( new BulkRecheck( $revalidation ), $counter );
	}

	public function test_a_run_queues_small_batches_instead_of_checking_in_the_request(): void {
		list( $bulk, $counter ) = $this->bulk();

		$url = $bulk->handle( 'orders', BulkRecheck::ACTION, range( 1, 12 ) );

		$this->assertSame( 0, $counter->n, 'No registry call happens in the admin request' );
		$this->assertSame( 'orders?vatright_recheck_queued=12', $url );
		$this->assertSame( array( 5, 5, 2 ), array_map( static fn( $call ) => count( $call[1][0] ), $GLOBALS['vatright_test_async'] ) );
		$this->assertSame( array( BulkRecheck::HOOK, array( range( 1, 5 ), 1 ), 'vatright' ), $GLOBALS['vatright_test_async'][0] );
	}

	public function test_orders_the_user_cannot_edit_are_not_queued(): void {
		$GLOBALS['vatright_test_denied'] = array( 2 );
		list( $bulk )                    = $this->bulk();

		$url = $bulk->handle( 'orders', BulkRecheck::ACTION, array( 1, 2, 3 ) );

		$this->assertSame( 'orders?vatright_recheck_queued=2', $url );
		$this->assertSame( array( 1, 3 ), $GLOBALS['vatright_test_async'][0][1][0] );
	}

	public function test_other_bulk_actions_are_left_alone(): void {
		list( $bulk ) = $this->bulk();

		$this->assertSame( 'orders', $bulk->handle( 'orders', 'mark_completed', array( 1 ) ) );
		$this->assertSame( array(), $GLOBALS['vatright_test_async'] );
	}

	public function test_a_batch_checks_its_orders_and_sends_the_held_emails_afterwards(): void {
		list( $bulk, $counter ) = $this->bulk();

		$bulk->run_batch( array( 1, 2, 3 ), 1 );

		$this->assertSame( 3, $counter->n );
		$this->assertSame( 1, $counter->held );
	}

	public function test_a_batch_skips_orders_the_actor_cannot_edit(): void {
		$GLOBALS['vatright_test_denied'] = array( 2 );
		list( $bulk, $counter )          = $this->bulk();

		$bulk->run_batch( array( 1, 2, 3 ), 1 );

		$this->assertSame( 2, $counter->n );
	}
}
