<?php

namespace Vatright\Registry;

use Vatright\Domain\FormatCheck;
use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use Vatright\Settings;

class Validator {

	/** @var Router */
	private $router;

	/** @var ValidationCache */
	private $cache;

	/** @var Settings */
	private $settings;

	/** @var LookupThrottle */
	private $throttle;

	public function __construct( Router $router, ValidationCache $cache, Settings $settings, ?LookupThrottle $throttle = null ) {
		$this->router   = $router;
		$this->cache    = $cache;
		$this->settings = $settings;
		$this->throttle = $throttle ?? new LookupThrottle();
	}

	/**
	 * @param string $trader_name A company name to compare with the registry's, sent only to a registry that compares names.
	 */
	public function validate( VatNumber $n, bool $allow_network, bool $fresh = false, string $trader_name = '' ): ValidationResult {
		return $this->lookup( $n, $allow_network, $fresh, null, $trader_name );
	}

	/**
	 * For lookups an anonymous visitor sets off (cart updates, checkout). A
	 * cached verdict is returned as usual; a cache miss reaches the registry
	 * only while the throttle allows it, and is UNCHECKED with detail
	 * 'throttled' otherwise, which checkout treats like an outage with VAT
	 * charged.
	 *
	 * @param string $bucket 'update' or 'checkout', each with its own per-client allowance.
	 */
	public function validate_for_visitor( VatNumber $n, string $bucket, string $trader_name = '' ): ValidationResult {
		return $this->lookup( $n, true, false, $bucket, $trader_name );
	}

	/**
	 * A name that can't be compared (no company, or a registry that doesn't
	 * compare names) is a plain lookup. A cached VALID answer is reused as it
	 * is; only a missing verdict for this name sends the registry a new
	 * request, and a lookup that can't make one returns the cached answer
	 * without a mismatch.
	 */
	private function lookup( VatNumber $n, bool $allow_network, bool $fresh, ?string $bucket, string $trader_name ): ValidationResult {
		if ( ! FormatCheck::is_plausible( $n ) ) {
			return new ValidationResult( ValidationResult::FORMAT_INVALID, 'format', gmdate( 'Y-m-d\TH:i:s\Z' ) );
		}

		$registry = $this->router->for( $n );
		if ( null === $registry ) {
			return new ValidationResult( ValidationResult::UNCHECKED, 'none', gmdate( 'Y-m-d\TH:i:s\Z' ), null, null, null, false, 'no_registry' );
		}

		$requester = $this->requester_for( $registry );
		$named     = '' !== $trader_name && $registry instanceof NameMatching;
		$cached    = $fresh ? null : $this->cache->get( $n, $requester );
		if ( null !== $cached ) {
			if ( ! $named || ValidationResult::VALID !== $cached->status() ) {
				return $cached;
			}
			$verdict = $this->cache->get_name_verdict( $n, $requester, $trader_name );
			if ( null !== $verdict ) {
				if ( ValidationCache::NAME_OK === $verdict ) {
					return $cached;
				}
				return $this->with_detail( $cached, ValidationCache::NAME_MISMATCH === $verdict ? NameMatching::MISMATCH : NameMatching::MISMATCH_LOCAL );
			}
		}
		if ( ! $allow_network ) {
			return $cached ?? new ValidationResult( ValidationResult::UNCHECKED, $registry->id(), gmdate( 'Y-m-d\TH:i:s\Z' ), null, null, null, false, 'cache_miss' );
		}
		if ( null !== $bucket && ! $this->throttle->allow( $bucket ) ) {
			return $cached ?? new ValidationResult( ValidationResult::UNCHECKED, $registry->id(), gmdate( 'Y-m-d\TH:i:s\Z' ), null, null, null, false, 'throttled' );
		}

		$result = $this->ask( $registry, $n, $requester, $named ? $trader_name : '' );
		if ( null !== $requester && ValidationResult::UNAVAILABLE === $result->status() && 'INVALID_REQUESTER_INFO' === $result->detail() ) {
			$result = $this->with_detail( $this->ask( $registry, $n, null, $named ? $trader_name : '' ), 'requester_rejected' );
		}

		$mismatch = in_array( $result->detail(), array( NameMatching::MISMATCH, NameMatching::MISMATCH_LOCAL ), true );
		$this->cache->put( $n, $requester, $mismatch ? $this->replace_detail( $result, '' ) : $result );
		if ( $named && ValidationResult::VALID === $result->status() ) {
			$this->cache->put_name_verdict( $n, $requester, $trader_name, $this->name_verdict( $result ) );
		}
		return $result;
	}

	private function name_verdict( ValidationResult $result ): string {
		switch ( $result->detail() ) {
			case NameMatching::MISMATCH:
				return ValidationCache::NAME_MISMATCH;
			case NameMatching::MISMATCH_LOCAL:
				return ValidationCache::NAME_MISMATCH_LOCAL;
			default:
				return ValidationCache::NAME_OK;
		}
	}

	private function ask( Registry $registry, VatNumber $n, ?VatNumber $requester, string $trader_name ): ValidationResult {
		if ( '' !== $trader_name && $registry instanceof NameMatching ) {
			return $registry->check_named( $n, $requester, $trader_name );
		}
		return $registry->check( $n, $requester );
	}

	/**
	 * The first store number the registry accepts as a requester: the EU
	 * number for VIES, the GB number for HMRC.
	 */
	private function requester_for( Registry $registry ): ?VatNumber {
		foreach ( $this->settings->store_requesters() as $store ) {
			if ( $registry->supports( $store ) ) {
				return $store;
			}
		}
		return null;
	}

	private function with_detail( ValidationResult $result, string $detail ): ValidationResult {
		return '' !== $result->detail() ? $result : $this->replace_detail( $result, $detail );
	}

	private function replace_detail( ValidationResult $result, string $detail ): ValidationResult {
		return new ValidationResult(
			$result->status(),
			$result->registry(),
			$result->checked_at(),
			$result->name(),
			$result->address(),
			$result->consultation(),
			$result->from_cache(),
			$detail
		);
	}
}
