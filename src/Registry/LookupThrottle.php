<?php

namespace Vatright\Registry;

/**
 * Caps the registry lookups an anonymous visitor can set off, per client and
 * across the site, so a script typing VAT numbers into the cart cannot spend
 * the store's registry quota or fill the options table.
 *
 * Each counter is a transient holding a fixed window and expiring with it. A
 * counter is only written when a lookup is let through, so the rows that can
 * exist at once are bounded by the global cap. Reading and writing a counter
 * is not atomic: concurrent requests can overshoot a cap by a few lookups,
 * which still stops a flood without needing a lock.
 *
 * The site-wide cap (the vatright_global_lookup_limit filter) is split in
 * two: a reserve that only a client's first few lookups each hour can use,
 * and the rest. The reserve is also rationed per network (IPv6 /48, IPv4
 * /24), so one party rotating through the addresses it owns cannot empty it.
 * Every lookup counts against exactly one site-wide counter and a client's
 * own counters are only written once that check passes, so registry calls and
 * stored rows stay bounded by the filter's value however many client
 * addresses are used.
 */
class LookupThrottle {

	const PREFIX = 'vatright_t_';

	const FREE_LOOKUPS = 2;

	const FREE_NETWORK_LOOKUPS = 6;

	/**
	 * Each bucket has its own per-client and site-wide counters, so cart
	 * updates, which anyone can send, cannot use up what checkout is allowed.
	 *
	 * @param string $bucket Separates the cart-update allowance from the checkout one.
	 */
	public function allow( string $bucket ): bool {
		$now    = time();
		$ip     = self::address();
		$client = $this->counter( self::PREFIX . md5( $bucket . '|' . self::client( $ip ) ), (array) apply_filters( 'vatright_client_lookup_limit', array( 10, 10 * MINUTE_IN_SECONDS ) ), $now );
		if ( $client['count'] >= $client['limit'] ) {
			return false;
		}

		$limits  = (array) apply_filters( 'vatright_global_lookup_limit', array( 300, HOUR_IN_SECONDS ), $bucket );
		$total   = max( 0, (int) ( $limits[0] ?? 0 ) );
		$reserve = (array) apply_filters( 'vatright_global_free_lookup_limit', array( intdiv( $total, 3 ), (int) ( $limits[1] ?? HOUR_IN_SECONDS ) ), $bucket );
		$reserve = array( min( $total, max( 0, (int) ( $reserve[0] ?? 0 ) ) ), $reserve[1] ?? HOUR_IN_SECONDS );

		$free_limits = (array) apply_filters( 'vatright_free_lookup_window', array( max( 0, (int) apply_filters( 'vatright_free_client_lookups', self::FREE_LOOKUPS ) ), HOUR_IN_SECONDS ) );
		$own_free    = $this->counter( self::PREFIX . 'free_' . md5( $bucket . '|' . self::client( $ip ) ), $free_limits, $now );
		$net_free    = $this->counter( self::PREFIX . 'net_' . md5( $bucket . '|' . self::network( $ip ) ), (array) apply_filters( 'vatright_network_free_lookup_limit', array( self::FREE_NETWORK_LOOKUPS, HOUR_IN_SECONDS ) ), $now );

		$global   = $this->counter( self::PREFIX . 'global_free_' . $bucket, $reserve, $now );
		$counters = array( $client, $global, $own_free, $net_free );
		if ( $own_free['count'] >= $own_free['limit'] || $net_free['count'] >= $net_free['limit'] || $global['count'] >= $global['limit'] ) {
			$global   = $this->counter( self::PREFIX . 'global_' . $bucket, array( $total - $reserve[0], $limits[1] ?? HOUR_IN_SECONDS ), $now );
			$counters = array( $client, $global );
		}
		if ( $global['count'] >= $global['limit'] ) {
			return false;
		}

		foreach ( $counters as $counter ) {
			set_transient(
				$counter['key'],
				array(
					'count' => $counter['count'] + 1,
					'start' => $counter['start'],
				),
				max( 1, $counter['start'] + $counter['window'] - $now )
			);
		}
		return true;
	}

	/**
	 * @param array<mixed> $limits Lookups allowed, window in seconds.
	 * @return array{key: string, count: int, start: int, limit: int, window: int}
	 */
	private function counter( string $key, array $limits, int $now ): array {
		$limit  = max( 0, (int) ( $limits[0] ?? 0 ) );
		$window = max( 1, (int) ( $limits[1] ?? 1 ) );

		$stored = get_transient( $key );
		$fresh  = ! is_array( $stored ) || (int) ( $stored['start'] ?? 0 ) + $window <= $now;
		return array(
			'key'    => $key,
			'count'  => $fresh ? 0 : (int) ( $stored['count'] ?? 0 ),
			'start'  => $fresh ? $now : (int) $stored['start'],
			'limit'  => $limit,
			'window' => $window,
		);
	}

	/**
	 * REMOTE_ADDR unless the site says which proxy header to trust; a
	 * forwarded-for header is set by the client and never trusted by default.
	 * An IPv4-mapped (::ffff:0:0/96) or IPv4-compatible (::/96) address is
	 * that IPv4 client.
	 */
	private static function address(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = (string) apply_filters( 'vatright_client_ip', $remote );
		$packed = false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? false : inet_pton( $ip );
		if ( false === $packed ) {
			return $ip;
		}
		$head = substr( $packed, 0, 12 );
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === $head || str_repeat( "\0", 12 ) === $head ) {
			return (string) inet_ntop( substr( $packed, 12 ) );
		}
		return $ip;
	}

	/**
	 * A native IPv6 address counts as its /48, the block a single subscriber
	 * or site can be given, which would otherwise be 256 separate /56
	 * allowances. Two subscribers sharing a /48 only share a cap whose worst
	 * case is VAT charged and the number checked later.
	 */
	private static function client( string $ip ): string {
		return self::mask( $ip, 6 );
	}

	/**
	 * The /48 (IPv6) or /24 (IPv4) a client sits in, which is what a single
	 * party can rotate through for free, so the reserve is rationed per network.
	 */
	private static function network( string $ip ): string {
		return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? self::mask_v4( $ip ) : self::mask( $ip, 6 );
	}

	private static function mask( string $ip, int $bytes ): string {
		$packed = false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? false : inet_pton( $ip );
		return false === $packed ? $ip : (string) inet_ntop( substr( $packed, 0, $bytes ) . str_repeat( "\0", 16 - $bytes ) );
	}

	private static function mask_v4( string $ip ): string {
		$parts = explode( '.', $ip );
		return 4 === count( $parts ) ? implode( '.', array( $parts[0], $parts[1], $parts[2], '0' ) ) : $ip;
	}
}
