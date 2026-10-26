<?php

namespace Vatright\Registry;

use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use Throwable;

class Vies implements Registry {

	const BASE_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api';

	/** @var HttpTransport */
	private $transport;

	/** @var int */
	private $timeout;

	public function __construct( HttpTransport $transport, int $timeout = 5 ) {
		$this->transport = $transport;
		$this->timeout   = $timeout;
	}

	public function id(): string {
		return 'vies';
	}

	public function supports( VatNumber $n ): bool {
		return $n->is_eu();
	}

	public function check( VatNumber $n, ?VatNumber $requester ): ValidationResult {
		return $this->check_named( $n, $requester, '' );
	}

	public function check_named( VatNumber $n, ?VatNumber $requester, string $trader_name ): ValidationResult {
		try {
			$payload = array(
				'countryCode' => $n->prefix(),
				'vatNumber'   => $n->number(),
			);
			if ( null !== $requester ) {
				$payload['requesterMemberStateCode'] = $requester->prefix();
				$payload['requesterNumber']          = $requester->number();
			}
			if ( '' !== $trader_name ) {
				$payload['traderName'] = $trader_name;
			}

			$response = $this->transport->request(
				'POST',
				self::BASE_URL . '/check-vat-number',
				(string) json_encode( $payload ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				$this->timeout
			);
			return $this->parse( $response );
		} catch ( Throwable $e ) {
			return $this->unavailable( 'exception' );
		}
	}

	/**
	 * Per-country availability keyed by VIES country code (EL for Greece), or
	 * an empty array when the endpoint can't be read.
	 *
	 * @return array<string,string>
	 */
	public function status(): array {
		try {
			$response = $this->transport->request( 'GET', self::BASE_URL . '/check-status', '', array( 'Accept' => 'application/json' ), $this->timeout );
		} catch ( Throwable $e ) {
			return array();
		}
		$data = json_decode( $response['body'], true );
		if ( 200 !== $response['status'] || ! is_array( $data ) || ! isset( $data['countries'] ) || ! is_array( $data['countries'] ) ) {
			return array();
		}

		$status = array();
		foreach ( $data['countries'] as $country ) {
			if ( is_array( $country ) && isset( $country['countryCode'], $country['availability'] ) ) {
				$status[ (string) $country['countryCode'] ] = (string) $country['availability'];
			}
		}
		return $status;
	}

	/**
	 * Only an explicit valid:false on a response without an error wrapper is
	 * INVALID. Everything unexpected is UNAVAILABLE, so an outage can never
	 * read as "this number doesn't exist".
	 *
	 * @param array{status:int,body:string,error:string} $response
	 */
	private function parse( array $response ): ValidationResult {
		if ( '' !== $response['error'] || 0 === $response['status'] ) {
			return $this->unavailable( 'transport_error' );
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			return $this->unavailable( 200 === $response['status'] ? 'malformed' : 'http_' . $response['status'] );
		}

		if ( false === ( $data['actionSucceed'] ?? null ) || isset( $data['errorWrappers'] ) ) {
			$code = $this->error_code( $data );
			// VIES answered and refused the number: that's a verdict, not an outage.
			if ( in_array( $code, array( 'INVALID_INPUT', 'VAT_BLOCKED' ), true ) ) {
				return new ValidationResult( ValidationResult::INVALID, $this->id(), gmdate( 'Y-m-d\TH:i:s\Z' ), null, null, null, false, $code );
			}
			// VIES turning the store away for load can be caused by visitors, so it charges VAT like our own throttle.
			if ( in_array( $code, array( 'IP_BLOCKED', 'GLOBAL_MAX_CONCURRENT_REQ', 'MS_MAX_CONCURRENT_REQ' ), true ) ) {
				return new ValidationResult( ValidationResult::UNCHECKED, $this->id(), gmdate( 'Y-m-d\TH:i:s\Z' ), null, null, null, false, 'throttled' );
			}
			return $this->unavailable( $code );
		}
		if ( 200 !== $response['status'] ) {
			return $this->unavailable( 'http_' . $response['status'] );
		}

		$checked_at = isset( $data['requestDate'] ) && is_string( $data['requestDate'] ) ? $data['requestDate'] : gmdate( 'Y-m-d\TH:i:s\Z' );

		if ( true === ( $data['valid'] ?? null ) ) {
			return new ValidationResult(
				ValidationResult::VALID,
				$this->id(),
				$checked_at,
				$this->text( $data, 'name' ),
				$this->text( $data, 'address' ),
				$this->text( $data, 'requestIdentifier' ),
				false,
				'INVALID' === ( $data['traderNameMatch'] ?? '' ) ? 'name_mismatch' : ''
			);
		}
		if ( false === ( $data['valid'] ?? null ) ) {
			return new ValidationResult( ValidationResult::INVALID, $this->id(), $checked_at );
		}
		return $this->unavailable( 'malformed' );
	}

	/**
	 * @param array<mixed> $data
	 */
	private function error_code( array $data ): string {
		$wrappers = $data['errorWrappers'] ?? array();
		$first    = is_array( $wrappers ) && isset( $wrappers[0] ) && is_array( $wrappers[0] ) ? $wrappers[0] : array();
		$error    = isset( $first['error'] ) ? (string) $first['error'] : '';
		return '' === $error ? 'error' : $error;
	}

	/**
	 * @param array<mixed> $data
	 */
	private function text( array $data, string $key ): ?string {
		$value = isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? trim( $data[ $key ] ) : '';
		return '' === $value || '---' === $value ? null : $value;
	}

	private function unavailable( string $detail ): ValidationResult {
		return new ValidationResult( ValidationResult::UNAVAILABLE, $this->id(), gmdate( 'Y-m-d\TH:i:s\Z' ), null, null, null, false, $detail );
	}
}
