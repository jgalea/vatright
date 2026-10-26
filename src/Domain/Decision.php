<?php

namespace Vatright\Domain;

final class Decision {

	const VERSION = 1;

	/** @var string */
	private $id;

	/** @var string */
	private $at;

	/** @var string */
	private $trigger;

	/** @var array<string,mixed> */
	private $store;

	/** @var array<string,mixed> */
	private $customer;

	/** @var array<string,mixed>|null */
	private $vat_number;

	/** @var array<string,mixed>|null */
	private $validation;

	/** @var array<string,mixed> */
	private $evidence;

	/** @var array<string,mixed> */
	private $policy;

	/** @var LineDecision[] */
	private $lines;

	/** @var string */
	private $scheme;

	/** @var string */
	private $rule;

	/** @var bool */
	private $vat_exempt;

	/** @var string[] */
	private $flags;

	/** @var string[] */
	private $legal_text;

	/** @var string|null */
	private $blocking_error;

	/**
	 * The array arguments carry the v1 record sections as documented in the
	 * plan; vat_number and validation are null when the order has none.
	 *
	 * @param array<string,mixed>      $store
	 * @param array<string,mixed>      $customer
	 * @param array<string,mixed>|null $vat_number
	 * @param array<string,mixed>|null $validation
	 * @param array<string,mixed>      $evidence
	 * @param array<string,mixed>      $policy
	 * @param LineDecision[]           $lines
	 * @param string[]                 $flags
	 * @param string[]                 $legal_text
	 */
	public function __construct(
		string $id,
		string $at,
		string $trigger,
		array $store,
		array $customer,
		?array $vat_number,
		?array $validation,
		array $evidence,
		array $policy,
		array $lines,
		string $scheme,
		string $rule,
		bool $vat_exempt,
		array $flags,
		array $legal_text,
		?string $blocking_error
	) {
		$this->id             = $id;
		$this->at             = $at;
		$this->trigger        = $trigger;
		$this->store          = $store;
		$this->customer       = $customer;
		$this->vat_number     = $vat_number;
		$this->validation     = $validation;
		$this->evidence       = $evidence;
		$this->policy         = $policy;
		$this->lines          = $lines;
		$this->scheme         = $scheme;
		$this->rule           = $rule;
		$this->vat_exempt     = $vat_exempt;
		$this->flags          = $flags;
		$this->legal_text     = $legal_text;
		$this->blocking_error = $blocking_error;
	}

	public function id(): string {
		return $this->id;
	}

	public function trigger(): string {
		return $this->trigger;
	}

	public function scheme(): string {
		return $this->scheme;
	}

	public function rule(): string {
		return $this->rule;
	}

	public function is_vat_exempt(): bool {
		return $this->vat_exempt;
	}

	/**
	 * @return string[]
	 */
	public function flags(): array {
		return $this->flags;
	}

	/**
	 * @return LineDecision[]
	 */
	public function lines(): array {
		return $this->lines;
	}

	public function line( string $key ): ?LineDecision {
		foreach ( $this->lines as $line ) {
			if ( $line->key() === $key ) {
				return $line;
			}
		}
		return null;
	}

	public function tax_country(): ?string {
		$countries = array();
		foreach ( $this->lines as $line ) {
			$countries[ $line->tax_country() ] = true;
		}
		return 1 === count( $countries ) ? (string) array_key_first( $countries ) : null;
	}

	/**
	 * Digital B2C lines are taxed where the customer is, which needs two
	 * matching pieces of evidence. A customer whose IP country disagrees with
	 * the billing country, and who has not confirmed the billing country, is
	 * where a confirmation is called for.
	 */
	public function needs_location_confirmation(): bool {
		$evidence = $this->evidence;
		if ( $this->vat_exempt || null !== $this->blocking_error || empty( $evidence['conflict'] ) || empty( $evidence['ip'] ) || ! empty( $evidence['self_certified'] ) ) {
			return false;
		}
		foreach ( $this->lines as $line ) {
			if ( Line::DIGITAL === $line->supply_type() ) {
				return true;
			}
		}
		return false;
	}

	public function blocking_error(): ?string {
		return $this->blocking_error;
	}

	/**
	 * @return string[]
	 */
	public function legal_text(): array {
		return $this->legal_text;
	}

	public function with_flag( string $flag ): Decision {
		if ( in_array( $flag, $this->flags, true ) ) {
			return $this;
		}
		$copy          = clone $this;
		$copy->flags[] = $flag;
		return $copy;
	}

	public function differs_in_tax_from( Decision $other ): bool {
		if ( $this->scheme !== $other->scheme || $this->vat_exempt !== $other->vat_exempt ) {
			return true;
		}
		foreach ( $this->lines as $line ) {
			$counterpart = $other->line( $line->key() );
			if ( null === $counterpart || $counterpart->tax_country() !== $line->tax_country() ) {
				return true;
			}
		}
		return count( $this->lines ) !== count( $other->lines );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'v'              => self::VERSION,
			'id'             => $this->id,
			'at'             => $this->at,
			'trigger'        => $this->trigger,
			'store'          => $this->store,
			'customer'       => $this->customer,
			'vat_number'     => $this->vat_number,
			'validation'     => $this->validation,
			'evidence'       => $this->evidence,
			'policy'         => $this->policy,
			'lines'          => array_map(
				static function ( LineDecision $line ): array {
					return $line->to_array();
				},
				$this->lines
			),
			'scheme'         => $this->scheme,
			'rule'           => $this->rule,
			'vat_exempt'     => $this->vat_exempt,
			'flags'          => $this->flags,
			'legal_text'     => $this->legal_text,
			'blocking_error' => $this->blocking_error,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): Decision {
		return new self(
			$data['id'],
			$data['at'],
			$data['trigger'],
			$data['store'],
			$data['customer'],
			$data['vat_number'],
			$data['validation'],
			$data['evidence'],
			$data['policy'],
			array_map( array( LineDecision::class, 'from_array' ), $data['lines'] ),
			$data['scheme'],
			$data['rule'],
			$data['vat_exempt'],
			$data['flags'],
			$data['legal_text'],
			$data['blocking_error']
		);
	}
}
