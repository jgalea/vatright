<?php

namespace Vatright\Tests\Integration\Registry;

use Vatright\Registry\LookupThrottle;
use Vatright\Tests\Support\IntegrationTestCase;

class LookupThrottleTest extends IntegrationTestCase {

	/** @var LookupThrottle */
	private $throttle;

	public function set_up() {
		parent::set_up();
		// The e2e mu-plugin, mounted in this environment too, raises the caps under test.
		remove_all_filters( 'vatright_client_lookup_limit' );
		remove_all_filters( 'vatright_global_lookup_limit' );
		remove_all_filters( 'vatright_global_free_lookup_limit' );
		$this->throttle         = new LookupThrottle();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	}

	public function tear_down() {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		parent::tear_down();
	}

	private function allowed( int $attempts, string $bucket = 'update' ): int {
		$allowed = 0;
		for ( $i = 0; $i < $attempts; $i++ ) {
			$allowed += $this->throttle->allow( $bucket ) ? 1 : 0;
		}
		return $allowed;
	}

	public function test_one_client_gets_ten_lookups_per_window_by_default() {
		$this->assertSame( 10, $this->allowed( 15 ) );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$this->assertSame( 10, $this->allowed( 15 ), 'Another client has its own allowance.' );
	}

	public function test_checkout_has_an_allowance_of_its_own() {
		$this->assertSame( 10, $this->allowed( 15, 'update' ) );
		$this->assertSame( 10, $this->allowed( 15, 'checkout' ) );
	}

	public function test_global_cap_holds_across_clients() {
		add_filter(
			'vatright_global_lookup_limit',
			static function () {
				return array( 25, HOUR_IN_SECONDS );
			}
		);
		$allowed = 0;
		foreach ( range( 1, 5 ) as $host ) {
			$_SERVER['REMOTE_ADDR'] = '203.0.' . $host . '.1';
			$allowed               += $this->allowed( 10 );
		}
		$this->assertSame( 25, $allowed );
	}

	public function test_default_global_cap_is_three_hundred_an_hour() {
		$allowed = 0;
		foreach ( range( 1, 60 ) as $host ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.' . $host . '.1';
			$allowed               += $this->allowed( 10 );
		}
		$this->assertSame( 300, $allowed );
	}

	public function test_forwarded_for_header_is_not_trusted_by_default() {
		$this->assertSame( 10, $this->allowed( 10 ) );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.77';
		$this->assertSame( 0, $this->allowed( 1 ) );
	}

	public function test_site_can_name_the_client_address() {
		$this->assertSame( 10, $this->allowed( 10 ) );

		add_filter(
			'vatright_client_ip',
			static function () {
				return '192.0.2.77';
			}
		);
		$this->assertSame( 10, $this->allowed( 15 ) );
	}

	public function test_an_ipv6_client_is_its_48_allocation() {
		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:200::1';
		$this->assertSame( 10, $this->allowed( 10 ) );

		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:300:ffff::9';
		$this->assertSame( 0, $this->allowed( 1 ), 'Same /48, same allowance.' );

		$_SERVER['REMOTE_ADDR'] = '2001:db8:2:200::1';
		$this->assertSame( 1, $this->allowed( 1 ) );
	}

	/**
	 * @return array<string,array{0: string, 1: string}>
	 */
	public function embedded_ipv4(): array {
		return array(
			'mapped'     => array( '::ffff:203.0.113.9', '::ffff:203.0.113.10' ),
			'compatible' => array( '::203.0.113.9', '::203.0.113.10' ),
		);
	}

	/**
	 * Truncating these to a prefix would put every IPv4 client in one bucket.
	 *
	 * @dataProvider embedded_ipv4
	 */
	public function test_an_ipv6_address_carrying_ipv4_counts_as_that_ipv4_client( string $first, string $second ) {
		$_SERVER['REMOTE_ADDR'] = $first;
		$this->assertSame( 10, $this->allowed( 10 ) );

		$_SERVER['REMOTE_ADDR'] = $second;
		$this->assertSame( 10, $this->allowed( 10 ), 'Another IPv4 client has its own allowance.' );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertSame( 0, $this->allowed( 1 ), 'The plain IPv4 form is the same client.' );
	}

	public function test_cart_updates_cannot_use_up_the_checkout_share_of_the_global_cap() {
		add_filter(
			'vatright_global_lookup_limit',
			static function () {
				return array( 5, HOUR_IN_SECONDS );
			}
		);
		foreach ( range( 1, 6 ) as $host ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.' . $host . '.1';
			$this->allowed( 1, 'update' );
		}
		$this->assertSame( 0, $this->allowed( 1, 'update' ) );

		$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
		$this->assertSame( 5, $this->allowed( 10, 'checkout' ) );
	}

	public function test_window_resets_once_it_has_passed() {
		$this->assertSame( 10, $this->allowed( 11 ) );

		$key     = LookupThrottle::PREFIX . md5( 'update|203.0.113.9' );
		$counter = get_transient( $key );
		$this->assertIsArray( $counter );
		$this->assertSame( 10, $counter['count'] );
		$counter['start'] -= 10 * MINUTE_IN_SECONDS;
		set_transient( $key, $counter, 60 );

		$this->assertSame( 10, $this->allowed( 11 ) );
	}

	/**
	 * The counter row expires with its window instead of being pushed back by
	 * every lookup, so a steady stream cannot keep one window open.
	 */
	public function test_counter_expiry_is_the_end_of_its_window() {
		$this->allowed( 1 );
		$key   = LookupThrottle::PREFIX . md5( 'update|203.0.113.9' );
		$first = (int) get_option( '_transient_timeout_' . $key );

		$counter           = get_transient( $key );
		$counter['start'] -= 300;
		set_transient( $key, $counter, $first - time() );
		$this->allowed( 1 );

		$this->assertEqualsWithDelta( $first - 300, (int) get_option( '_transient_timeout_' . $key ), 2 );
	}

	public function test_refused_lookups_write_nothing() {
		add_filter(
			'vatright_global_lookup_limit',
			static function () {
				return array( 0, HOUR_IN_SECONDS );
			}
		);
		global $wpdb;
		$count  = static function () use ( $wpdb ): int {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( LookupThrottle::PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		};
		$before = $count();

		foreach ( range( 1, 50 ) as $host ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.' . $host . '.1';
			$this->assertSame( 0, $this->allowed( 1 ) );
		}

		$this->assertSame( $before, $count() );

		remove_all_filters( 'vatright_global_lookup_limit' );
		$this->assertSame( 1, $this->allowed( 1 ) );
		$this->assertSame( $before + 8, $count(), 'An allowed first lookup writes its client, reserve and free-allowance counters (value and timeout rows), so the count above can move.' );
	}
}
