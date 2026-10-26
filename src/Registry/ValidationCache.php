<?php

namespace Vatright\Registry;

use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use Vatright\Settings;

class ValidationCache {

	const PREFIX              = 'vatright_v_';
	const NAME_OK             = 'ok';
	const NAME_MISMATCH       = 'mismatch';
	const NAME_MISMATCH_LOCAL = 'mismatch_local';

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function get( VatNumber $n, ?VatNumber $requester ): ?ValidationResult {
		$stored = get_transient( $this->key( $n, $requester ) );
		if ( ! is_array( $stored ) ) {
			return null;
		}
		return ValidationResult::from_array( $stored )->with_from_cache( true );
	}

	public function put( VatNumber $n, ?VatNumber $requester, ValidationResult $result ): void {
		$ttl = $this->ttl( $result->status() );
		if ( 0 === $ttl ) {
			return;
		}
		set_transient( $this->key( $n, $requester ), $result->to_array(), $ttl );
	}

	/**
	 * What the registry said about a company name for a number: 'ok',
	 * 'mismatch' (its own verdict) or 'mismatch_local' (found by comparing with
	 * the name it returned). Kept apart from the validation result so a lookup that
	 * carries no name never sees it.
	 */
	public function get_name_verdict( VatNumber $n, ?VatNumber $requester, string $name ): ?string {
		$stored = get_transient( $this->name_key( $n, $requester, $name ) );
		return in_array( $stored, array( self::NAME_OK, self::NAME_MISMATCH, self::NAME_MISMATCH_LOCAL ), true ) ? $stored : null;
	}

	public function put_name_verdict( VatNumber $n, ?VatNumber $requester, string $name, string $verdict ): void {
		$ttl = self::NAME_OK !== $verdict ? HOUR_IN_SECONDS : $this->settings->cache_ttl_valid_days() * DAY_IN_SECONDS;
		set_transient( $this->name_key( $n, $requester, $name ), $verdict, $ttl );
	}

	public function forget( VatNumber $n, ?VatNumber $requester ): void {
		delete_transient( $this->key( $n, $requester ) );
	}

	private function ttl( string $status ): int {
		switch ( $status ) {
			case ValidationResult::VALID:
				return $this->settings->cache_ttl_valid_days() * DAY_IN_SECONDS;
			case ValidationResult::INVALID:
				return HOUR_IN_SECONDS;
			case ValidationResult::UNAVAILABLE:
				return 5 * MINUTE_IN_SECONDS;
			default:
				return 0;
		}
	}

	private function name_key( VatNumber $n, ?VatNumber $requester, string $name ): string {
		return self::PREFIX . 'n_' . md5( $n->canonical() . '|' . ( null === $requester ? '' : $requester->canonical() ) . '|' . strtolower( trim( $name ) ) );
	}

	private function key( VatNumber $n, ?VatNumber $requester ): string {
		return self::PREFIX . md5( $n->canonical() . '|' . ( null === $requester ? '' : $requester->canonical() ) );
	}
}
