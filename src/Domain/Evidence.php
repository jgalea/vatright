<?php

namespace Vatright\Domain;

final class Evidence {

	/** @var string|null */
	private $billing;

	/** @var string|null */
	private $shipping;

	/** @var string|null */
	private $ip;

	/** @var string|null */
	private $self_certified;

	public function __construct( ?string $billing, ?string $shipping, ?string $ip, ?string $self_certified ) {
		$this->billing        = $billing;
		$this->shipping       = $shipping;
		$this->ip             = $ip;
		$this->self_certified = $self_certified;
	}

	/**
	 * Self-certification wins when set. Otherwise a country backed by at least
	 * two pieces wins, falling back to billing alone. Conflict is raised
	 * whenever two non-null pieces disagree, even when a majority exists, and
	 * only a self-certification clears it. Territories sharing one VAT area,
	 * such as Monaco and France, count as the same country.
	 *
	 * @return array{country: ?string, conflict: bool, pieces: int}
	 */
	public function resolve(): array {
		$pieces = array_map(
			array( Territory::class, 'vat_country' ),
			array_values( array_filter( array( $this->billing, $this->shipping, $this->ip ), 'is_string' ) )
		);
		$counts = array_count_values( $pieces );

		if ( null !== $this->self_certified ) {
			return array(
				'country'  => $this->self_certified,
				'conflict' => false,
				'pieces'   => 1 + ( $counts[ $this->self_certified ] ?? 0 ),
			);
		}

		$conflict = count( $counts ) > 1;

		arsort( $counts );
		$top = array_key_first( $counts );
		if ( null !== $top && $counts[ $top ] >= 2 ) {
			return array(
				'country'  => (string) $top,
				'conflict' => $conflict,
				'pieces'   => $counts[ $top ],
			);
		}

		return array(
			'country'  => $this->billing,
			'conflict' => $conflict,
			'pieces'   => null === $this->billing ? 0 : 1,
		);
	}

	/**
	 * @return array{billing: ?string, shipping: ?string, ip: ?string, self_certified: ?string}
	 */
	public function to_array(): array {
		return array(
			'billing'        => $this->billing,
			'shipping'       => $this->shipping,
			'ip'             => $this->ip,
			'self_certified' => $this->self_certified,
		);
	}
}
