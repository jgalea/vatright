<?php

namespace Vatright\Domain;

final class ValidationResult {

	const VALID          = 'valid';
	const INVALID        = 'invalid';
	const UNAVAILABLE    = 'unavailable';
	const UNCHECKED      = 'unchecked';
	const FORMAT_INVALID = 'format_invalid';

	/** @var string */
	private $status;

	/** @var string */
	private $registry;

	/** @var string */
	private $checked_at;

	/** @var string|null */
	private $name;

	/** @var string|null */
	private $address;

	/** @var string|null */
	private $consultation;

	/** @var bool */
	private $from_cache;

	/** @var string */
	private $detail;

	public function __construct(
		string $status,
		string $registry,
		string $checked_at,
		?string $name = null,
		?string $address = null,
		?string $consultation = null,
		bool $from_cache = false,
		string $detail = ''
	) {
		$this->status       = $status;
		$this->registry     = $registry;
		$this->checked_at   = $checked_at;
		$this->name         = $name;
		$this->address      = $address;
		$this->consultation = $consultation;
		$this->from_cache   = $from_cache;
		$this->detail       = $detail;
	}

	public function status(): string {
		return $this->status;
	}

	public function registry(): string {
		return $this->registry;
	}

	public function checked_at(): string {
		return $this->checked_at;
	}

	public function name(): ?string {
		return $this->name;
	}

	public function address(): ?string {
		return $this->address;
	}

	public function consultation(): ?string {
		return $this->consultation;
	}

	public function from_cache(): bool {
		return $this->from_cache;
	}

	public function detail(): string {
		return $this->detail;
	}

	public function with_detail( string $detail ): ValidationResult {
		$copy         = clone $this;
		$copy->detail = $detail;
		return $copy;
	}

	public function with_from_cache( bool $from_cache ): ValidationResult {
		$copy             = clone $this;
		$copy->from_cache = $from_cache;
		return $copy;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'status'       => $this->status,
			'registry'     => $this->registry,
			'checked_at'   => $this->checked_at,
			'from_cache'   => $this->from_cache,
			'name'         => $this->name,
			'address'      => $this->address,
			'consultation' => $this->consultation,
			'detail'       => $this->detail,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): ValidationResult {
		return new self(
			(string) $data['status'],
			(string) $data['registry'],
			(string) $data['checked_at'],
			isset( $data['name'] ) ? (string) $data['name'] : null,
			isset( $data['address'] ) ? (string) $data['address'] : null,
			isset( $data['consultation'] ) ? (string) $data['consultation'] : null,
			! empty( $data['from_cache'] ),
			(string) ( $data['detail'] ?? '' )
		);
	}
}
