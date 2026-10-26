<?php

namespace Vatright\Domain;

final class DecisionInput {

	/** @var string */
	private $store_country;

	/** @var bool */
	private $oss_registered;

	/** @var bool */
	private $threshold_exceeded;

	/** @var array<string,mixed> */
	private $threshold;

	/** @var string */
	private $outage_policy;

	/** @var bool */
	private $allow_domestic_exemption;

	/** @var bool */
	private $fixed_prices;

	/** @var string */
	private $billing_country;

	/** @var string */
	private $billing_postcode;

	/** @var string */
	private $shipping_country;

	/** @var string */
	private $shipping_postcode;

	/** @var bool */
	private $local_pickup;

	/** @var VatNumber|null */
	private $vat_number;

	/** @var string|null */
	private $vat_raw;

	/** @var ValidationResult|null */
	private $validation;

	/** @var Evidence */
	private $evidence;

	/** @var Line[] */
	private $lines;

	/** @var string */
	private $trigger;

	/** @var string */
	private $now_utc;

	/** @var string[] */
	private $exempt_roles;

	/**
	 * Empty shipping country and postcode mean the order has no shipping address.
	 *
	 * @param array<string,mixed> $threshold Keys current_eur, previous_eur, exceeded, forced.
	 * @param Line[]              $lines
	 * @param string[]            $exempt_roles The customer's roles that the merchant treats as VAT-exempt.
	 */
	public function __construct(
		string $store_country,
		bool $oss_registered,
		bool $threshold_exceeded,
		array $threshold,
		string $outage_policy,
		bool $allow_domestic_exemption,
		bool $fixed_prices,
		string $billing_country,
		string $billing_postcode,
		string $shipping_country,
		string $shipping_postcode,
		bool $local_pickup,
		?VatNumber $vat_number,
		?string $vat_raw,
		?ValidationResult $validation,
		Evidence $evidence,
		array $lines,
		string $trigger,
		string $now_utc,
		array $exempt_roles = array()
	) {
		$this->store_country            = $store_country;
		$this->oss_registered           = $oss_registered;
		$this->threshold_exceeded       = $threshold_exceeded;
		$this->threshold                = $threshold;
		$this->outage_policy            = $outage_policy;
		$this->allow_domestic_exemption = $allow_domestic_exemption;
		$this->fixed_prices             = $fixed_prices;
		$this->billing_country          = $billing_country;
		$this->billing_postcode         = $billing_postcode;
		$this->shipping_country         = $shipping_country;
		$this->shipping_postcode        = $shipping_postcode;
		$this->local_pickup             = $local_pickup;
		$this->vat_number               = $vat_number;
		$this->vat_raw                  = $vat_raw;
		$this->validation               = $validation;
		$this->evidence                 = $evidence;
		$this->lines                    = $lines;
		$this->trigger                  = $trigger;
		$this->now_utc                  = $now_utc;
		$this->exempt_roles             = $exempt_roles;
	}

	public function store_country(): string {
		return $this->store_country;
	}

	public function oss_registered(): bool {
		return $this->oss_registered;
	}

	public function threshold_exceeded(): bool {
		return $this->threshold_exceeded;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function threshold(): array {
		return $this->threshold;
	}

	public function outage_policy(): string {
		return $this->outage_policy;
	}

	public function allow_domestic_exemption(): bool {
		return $this->allow_domestic_exemption;
	}

	public function fixed_prices(): bool {
		return $this->fixed_prices;
	}

	public function billing_country(): string {
		return $this->billing_country;
	}

	public function billing_postcode(): string {
		return $this->billing_postcode;
	}

	public function shipping_country(): string {
		return $this->shipping_country;
	}

	public function shipping_postcode(): string {
		return $this->shipping_postcode;
	}

	public function local_pickup(): bool {
		return $this->local_pickup;
	}

	public function vat_number(): ?VatNumber {
		return $this->vat_number;
	}

	public function vat_raw(): ?string {
		return $this->vat_raw;
	}

	public function validation(): ?ValidationResult {
		return $this->validation;
	}

	public function evidence(): Evidence {
		return $this->evidence;
	}

	/**
	 * @return Line[]
	 */
	public function lines(): array {
		return $this->lines;
	}

	public function trigger(): string {
		return $this->trigger;
	}

	public function now_utc(): string {
		return $this->now_utc;
	}

	/**
	 * @return string[]
	 */
	public function exempt_roles(): array {
		return $this->exempt_roles;
	}
}
