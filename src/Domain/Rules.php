<?php

namespace Vatright\Domain;

/**
 * Turns a fully gathered DecisionInput into a Decision. Pure and deterministic
 * apart from the decision id, which comes from the injected factory.
 *
 * Every line gets its own place of supply and rule; the customer-level VAT
 * exemption is only granted when every taxable line is exempt. When a case is
 * ambiguous the engine charges VAT and flags it.
 *
 * @phpstan-type LineResult array{key: string, type: string, rule: string, scheme: string, place: string, tax: string, evidence: bool, blocking: ?string, flags: string[]}
 */
final class Rules {

	const EXEMPT_RULES = array(
		Rule::RC_GOODS_ART138,
		Rule::RC_SERVICES_ART196,
		Rule::DOMESTIC_B2B_ALLOWED,
		Rule::PENDING_EXEMPT,
	);

	/**
	 * Which line rule names the whole decision when lines disagree and the
	 * customer is not exempt: problems first, then the rule that set the tax.
	 */
	const RULE_PRIORITY = array(
		Rule::PENDING_REJECTED,
		Rule::INVALID_NUMBER,
		Rule::PREFIX_MISMATCH_CHARGED,
		Rule::PENDING_CHARGED,
		Rule::DOMESTIC_B2B_GUARD,
		Rule::LOCAL_PICKUP_DOMESTIC,
		Rule::B2C_DESTINATION,
		Rule::B2C_ORIGIN_BELOW_THRESHOLD,
		Rule::B2C_SERVICE_ORIGIN,
		Rule::DOMESTIC,
		Rule::RC_GOODS_ART138,
		Rule::RC_SERVICES_ART196,
		Rule::PENDING_EXEMPT,
		Rule::DOMESTIC_B2B_ALLOWED,
		Rule::OUTSIDE_TERRITORY,
		Rule::NON_EU_NUMBER_RECORDED,
		Rule::EXPORT_NON_EU,
	);

	const FLAG_ORDER = array(
		'prefix_mismatch',
		'validation_pending',
		'evidence_conflict_self_certified',
		'single_evidence',
	);

	/** @var callable(): string */
	private $uuid;

	/**
	 * @param (callable(): string)|null $uuid
	 */
	public function __construct( ?callable $uuid = null ) {
		$this->uuid = $uuid ?? array( self::class, 'uuid4' );
	}

	public function decide( DecisionInput $in ): Decision {
		$results = array();
		foreach ( $in->lines() as $line ) {
			$results[] = $this->decide_line( $in, $line );
		}

		$exempt = $this->all_taxable_lines_exempt( $results );
		if ( ! $exempt ) {
			$results = array_map( array( $this, 'charge_unexpressible_exemption' ), $results );
		}

		return new Decision(
			( $this->uuid )(),
			$in->now_utc(),
			$in->trigger(),
			array(
				'country'        => $in->store_country(),
				'oss_registered' => $in->oss_registered(),
				'threshold'      => $in->threshold(),
			),
			array(
				'billing'      => array(
					'country'  => $in->billing_country(),
					'postcode' => $in->billing_postcode(),
				),
				'shipping'     => array(
					'country'  => $in->shipping_country(),
					'postcode' => $in->shipping_postcode(),
				),
				'local_pickup' => $in->local_pickup(),
			),
			$this->vat_number_section( $in ),
			null === $in->validation() ? null : $in->validation()->to_array(),
			$this->evidence_section( $in->evidence() ),
			array(
				'outage'                   => $in->outage_policy(),
				'allow_domestic_exemption' => $in->allow_domestic_exemption(),
				'fixed_prices'             => $in->fixed_prices(),
			),
			array_map(
				static function ( array $r ): LineDecision {
					return new LineDecision( $r['key'], $r['type'], $r['place'], $r['tax'], $r['scheme'] );
				},
				$results
			),
			$this->decision_scheme( $results ),
			$this->decision_rule( $results, $exempt ),
			$exempt,
			$this->decision_flags( $in, $results ),
			$this->legal_text( $results ),
			$this->blocking_error( $results )
		);
	}

	/**
	 * @return LineResult
	 */
	private function decide_line( DecisionInput $in, Line $line ): array {
		$type   = $line->supply_type();
		$store  = $in->store_country();
		$number = $in->vat_number();

		if ( Line::GOODS === $type && $in->local_pickup() ) {
			return $this->result( $line, Rule::LOCAL_PICKUP_DOMESTIC, Scheme::DOMESTIC, $store, $store );
		}

		list( $country, $postcode, $from_evidence ) = $this->locate( $in, $line );
		$northern_ireland                           = Line::GOODS === $type && self::is_northern_ireland( $country, $postcode );

		if ( ! $northern_ireland && ! Territory::in_vat_area( $country, $postcode ) ) {
			$result             = Territory::is_eu_member( Territory::vat_country( $country ) )
				? $this->result( $line, Rule::OUTSIDE_TERRITORY, Scheme::OUTSIDE_TERRITORY, $country, $country )
				: $this->result(
					$line,
					null !== $number && ! $this->counts_as_eu_number( $number, $type, $northern_ireland ) ? Rule::NON_EU_NUMBER_RECORDED : Rule::EXPORT_NON_EU,
					Scheme::EXPORT,
					Territory::vat_country( $country ),
					Territory::vat_country( $country )
				);
			$result['evidence'] = $from_evidence;
			return $result;
		}

		$country = Territory::vat_country( $country );
		if ( null !== $number && $this->counts_as_eu_number( $number, $type, $northern_ireland ) ) {
			return $this->business_line( $in, $line, $country, $number );
		}
		return $this->consumer_line( $in, $line, $country, null );
	}

	/**
	 * Goods go where they are shipped (billing when nothing is shipped), digital
	 * services to the country the location evidence points at, and other
	 * services to the store unless a validated business customer sits elsewhere.
	 *
	 * @return array{0: string, 1: string, 2: bool}
	 */
	private function locate( DecisionInput $in, Line $line ): array {
		$store = $in->store_country();

		if ( Line::GOODS === $line->supply_type() ) {
			if ( '' !== $in->shipping_country() ) {
				return array( $in->shipping_country(), $in->shipping_postcode(), false );
			}
			if ( '' !== $in->billing_country() ) {
				return array( $in->billing_country(), $in->billing_postcode(), false );
			}
			return array( $store, '', false );
		}

		if ( Line::DIGITAL === $line->supply_type() ) {
			$country = $in->evidence()->resolve()['country'] ?? '';
			if ( '' === $country ) {
				$country = '' !== $in->billing_country() ? $in->billing_country() : $store;
			}
			return array( $country, $this->postcode_for( $in, $country ), true );
		}

		if ( $this->is_validated_business( $in ) ) {
			return array( $in->billing_country(), $in->billing_postcode(), false );
		}
		return array( $store, '', false );
	}

	private function postcode_for( DecisionInput $in, string $country ): string {
		if ( $country === $in->billing_country() ) {
			return $in->billing_postcode();
		}
		if ( $country === $in->shipping_country() ) {
			return $in->shipping_postcode();
		}
		return '';
	}

	private function is_validated_business( DecisionInput $in ): bool {
		$number     = $in->vat_number();
		$validation = $in->validation();
		return null !== $number
			&& null !== $validation
			&& ValidationResult::VALID === $validation->status()
			&& $number->country() === Territory::vat_country( $in->billing_country() );
	}

	/**
	 * An XI number only identifies a business for goods in Northern Ireland's
	 * EU VAT arrangement (Windsor Framework Art. 8 and Annex 3, which apply EU
	 * VAT law concerning goods only); for services it is a UK number.
	 */
	private function counts_as_eu_number( VatNumber $number, string $type, bool $northern_ireland ): bool {
		if ( 'XI' === $number->prefix() ) {
			return Line::GOODS === $type && $northern_ireland;
		}
		return $number->is_eu();
	}

	private static function is_northern_ireland( string $country, string $postcode ): bool {
		return 'GB' === strtoupper( $country ) && 0 === strpos( strtoupper( ltrim( $postcode ) ), 'BT' );
	}

	/**
	 * @return LineResult
	 */
	private function business_line( DecisionInput $in, Line $line, string $country, VatNumber $number ): array {
		$store  = $in->store_country();
		$goods  = Line::GOODS === $line->supply_type();
		$status = null === $in->validation() ? ValidationResult::UNCHECKED : $in->validation()->status();

		if ( $number->country() !== Territory::vat_country( $in->billing_country() ) ) {
			$result            = $this->consumer_line( $in, $line, $country, Rule::PREFIX_MISMATCH_CHARGED );
			$result['flags'][] = 'prefix_mismatch';
			return $result;
		}

		$place    = $goods ? $country : $number->country();
		$domestic = $number->country() === $store || $place === $store;

		if ( $domestic && ! $in->allow_domestic_exemption() ) {
			return $this->result( $line, Rule::DOMESTIC_B2B_GUARD, Scheme::DOMESTIC, $store, $store );
		}

		if ( ValidationResult::VALID === $status ) {
			return $this->exempt_line( $line, $domestic ? Rule::DOMESTIC_B2B_ALLOWED : null, $domestic ? $store : $place );
		}

		if ( ValidationResult::INVALID === $status || ValidationResult::FORMAT_INVALID === $status ) {
			$result = $this->consumer_line( $in, $line, $country, Rule::INVALID_NUMBER );
			if ( in_array( $in->trigger(), array( 'checkout', 'cart' ), true ) ) {
				$result['blocking'] = 'invalid_vat_number';
			}
			return $result;
		}

		// The outage policy only covers a registry that was tried and failed; a number never checked is charged.
		if ( 'exempt_pending' === $in->outage_policy() && ValidationResult::UNAVAILABLE === $status ) {
			$result = $this->exempt_line( $line, Rule::PENDING_EXEMPT, $domestic ? $store : $place );
			if ( $domestic ) {
				$result['scheme'] = Scheme::DOMESTIC;
			}
		} elseif ( 'reject' === $in->outage_policy() && 'checkout' === $in->trigger() ) {
			$result             = $this->consumer_line( $in, $line, $country, Rule::PENDING_REJECTED );
			$result['blocking'] = 'registry_unavailable';
		} else {
			$result = $this->consumer_line( $in, $line, $country, Rule::PENDING_CHARGED );
		}
		$result['flags'][] = 'validation_pending';
		return $result;
	}

	/**
	 * A reverse-charged line: VAT is due in the place of supply but accounted
	 * for by the customer, so tax_country names that place.
	 *
	 * @return LineResult
	 */
	private function exempt_line( Line $line, ?string $rule, string $place ): array {
		$goods = Line::GOODS === $line->supply_type();
		if ( Rule::DOMESTIC_B2B_ALLOWED === $rule ) {
			return $this->result( $line, $rule, Scheme::DOMESTIC, $place, $place );
		}
		return $this->result(
			$line,
			$rule ?? ( $goods ? Rule::RC_GOODS_ART138 : Rule::RC_SERVICES_ART196 ),
			$goods ? Scheme::INTRA_EU_GOODS : Scheme::REVERSE_CHARGE_SERVICES,
			$place,
			$place
		);
	}

	/**
	 * B2C treatment, also used when a business claim fails. $rule overrides the
	 * B2C rule name but never the tax it computes.
	 *
	 * @return LineResult
	 */
	private function consumer_line( DecisionInput $in, Line $line, string $country, ?string $rule ): array {
		$store = $in->store_country();

		if ( Line::SERVICE === $line->supply_type() ) {
			$customer = '' === $in->billing_country() ? $store : Territory::vat_country( $in->billing_country() );
			$own_rule = $customer === $store ? Rule::DOMESTIC : Rule::B2C_SERVICE_ORIGIN;
			$scheme   = $customer === $store ? Scheme::DOMESTIC : Scheme::ORIGIN;
			return $this->result( $line, $rule ?? $own_rule, $scheme, $store, $store );
		}

		$evidence = Line::DIGITAL === $line->supply_type();
		if ( $country === $store ) {
			$result = $this->result( $line, $rule ?? Rule::DOMESTIC, Scheme::DOMESTIC, $store, $store );
		} elseif ( $in->oss_registered() || $in->threshold_exceeded() ) {
			$result = $this->result( $line, $rule ?? Rule::B2C_DESTINATION, Scheme::OSS_DESTINATION, $country, $country );
		} else {
			$result = $this->result( $line, $rule ?? Rule::B2C_ORIGIN_BELOW_THRESHOLD, Scheme::ORIGIN, $store, $store );
		}
		$result['evidence'] = $evidence;
		return $result;
	}

	/**
	 * @return LineResult
	 */
	private function result( Line $line, string $rule, string $scheme, string $place, string $tax ): array {
		return array(
			'key'      => $line->key(),
			'type'     => $line->supply_type(),
			'rule'     => $rule,
			'scheme'   => $scheme,
			'place'    => $place,
			'tax'      => $tax,
			'evidence' => false,
			'blocking' => null,
			'flags'    => array(),
		);
	}

	/**
	 * @param LineResult[] $results
	 */
	private function all_taxable_lines_exempt( array $results ): bool {
		$taxable = array_filter(
			$results,
			static function ( array $r ): bool {
				return ! in_array( $r['scheme'], array( Scheme::EXPORT, Scheme::OUTSIDE_TERRITORY ), true );
			}
		);
		foreach ( $taxable as $r ) {
			if ( ! in_array( $r['rule'], self::EXEMPT_RULES, true ) ) {
				return false;
			}
		}
		return array() !== $taxable;
	}

	/**
	 * Without the customer-level flag a line can only be zero-rated through a
	 * reverse-charge scheme. A domestic exemption has no per-line form, so in a
	 * partly exempt cart that line is charged.
	 *
	 * @param LineResult $r
	 * @return LineResult
	 */
	private function charge_unexpressible_exemption( array $r ): array {
		if ( Scheme::DOMESTIC === $r['scheme'] && in_array( $r['rule'], self::EXEMPT_RULES, true ) ) {
			$r['rule'] = Rule::DOMESTIC_B2B_GUARD;
		}
		return $r;
	}

	/**
	 * @param LineResult[] $results
	 */
	private function decision_scheme( array $results ): string {
		$schemes = array_unique( array_column( $results, 'scheme' ) );
		if ( array() === $schemes ) {
			return Scheme::DOMESTIC;
		}
		return 1 === count( $schemes ) ? (string) reset( $schemes ) : Scheme::MIXED;
	}

	/**
	 * @param LineResult[] $results
	 */
	private function decision_rule( array $results, bool $exempt ): string {
		$rules = array_column( $results, 'rule' );
		if ( $exempt ) {
			foreach ( $rules as $rule ) {
				if ( in_array( $rule, self::EXEMPT_RULES, true ) ) {
					return $rule;
				}
			}
		}
		foreach ( self::RULE_PRIORITY as $rule ) {
			if ( in_array( $rule, $rules, true ) ) {
				return $rule;
			}
		}
		return Rule::DOMESTIC;
	}

	/**
	 * @param LineResult[] $results
	 * @return string[]
	 */
	private function decision_flags( DecisionInput $in, array $results ): array {
		$flags = array_merge( array(), ...array_column( $results, 'flags' ) );

		if ( in_array( true, array_column( $results, 'evidence' ), true ) ) {
			$evidence = $in->evidence()->to_array();
			if ( null !== $evidence['self_certified'] ) {
				$flags[] = 'evidence_conflict_self_certified';
			} elseif ( $in->evidence()->resolve()['pieces'] <= 1 ) {
				$flags[] = 'single_evidence';
			}
		}

		return array_values( array_intersect( self::FLAG_ORDER, $flags ) );
	}

	/**
	 * @param LineResult[] $results
	 * @return string[]
	 */
	private function legal_text( array $results ): array {
		$schemes = array_column( $results, 'scheme' );
		$text    = array();
		if ( in_array( Scheme::REVERSE_CHARGE_SERVICES, $schemes, true ) ) {
			$text[] = 'art196';
		}
		if ( in_array( Scheme::INTRA_EU_GOODS, $schemes, true ) ) {
			$text[] = 'art138';
		}
		return $text;
	}

	/**
	 * @param LineResult[] $results
	 */
	private function blocking_error( array $results ): ?string {
		foreach ( $results as $r ) {
			if ( null !== $r['blocking'] ) {
				return $r['blocking'];
			}
		}
		return null;
	}

	/**
	 * @return array<string,string>|null
	 */
	private function vat_number_section( DecisionInput $in ): ?array {
		$number = $in->vat_number();
		$raw    = (string) $in->vat_raw();
		if ( null === $number ) {
			if ( '' === trim( $raw ) ) {
				return null;
			}
			return array(
				'raw'       => $raw,
				'canonical' => '',
				'prefix'    => '',
				'country'   => '',
			);
		}
		return array(
			'raw'       => '' === $raw ? $number->canonical() : $raw,
			'canonical' => $number->canonical(),
			'prefix'    => $number->prefix(),
			'country'   => $number->country(),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function evidence_section( Evidence $evidence ): array {
		$resolved = $evidence->resolve();
		return array_merge(
			$evidence->to_array(),
			array(
				'resolved' => $resolved['country'],
				'conflict' => $resolved['conflict'],
				'pieces'   => $resolved['pieces'],
			)
		);
	}

	private static function uuid4(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ord( $bytes[6] ) & 0x0f | 0x40 );
		$bytes[8] = chr( ord( $bytes[8] ) & 0x3f | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}
