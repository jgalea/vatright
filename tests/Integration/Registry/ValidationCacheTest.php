<?php

namespace Vatright\Tests\Integration\Registry;

use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use Vatright\Registry\Router;
use Vatright\Registry\ValidationCache;
use Vatright\Registry\Validator;
use Vatright\Registry\Vies;
use Vatright\Registry\WpHttpTransport;
use Vatright\Settings;
use Vatright\Tests\Support\HttpGuard;
use Vatright\Tests\Support\IntegrationTestCase;

class ValidationCacheTest extends IntegrationTestCase {

	/** @var VatNumber */
	private $number;

	public function set_up() {
		parent::set_up();
		Settings::reset();
		update_option( Settings::OPTION, array( 'cache_ttl_valid_days' => 3 ) );
		$number = VatNumber::parse( 'DE123475223', 'DE' );
		$this->assertNotNull( $number );
		$this->number = $number;
	}

	private function result( string $status ): ValidationResult {
		return new ValidationResult( $status, 'vies', '2026-10-17T00:00:00Z', 'Name', 'Address' );
	}

	private function key( VatNumber $n, string $requester = '' ): string {
		return ValidationCache::PREFIX . md5( $n->canonical() . '|' . $requester );
	}

	private function timeout_of( string $key ): int {
		return (int) get_option( '_transient_timeout_' . $key ) - time();
	}

	public function test_round_trip_marks_the_result_as_from_cache(): void {
		$cache = new ValidationCache( Settings::get() );
		$cache->put( $this->number, null, $this->result( ValidationResult::VALID ) );

		$hit = $cache->get( $this->number, null );

		$this->assertNotNull( $hit );
		$this->assertTrue( $hit->from_cache() );
		$this->assertSame( 'Name', $hit->name() );
	}

	public function test_miss_returns_null(): void {
		$this->assertNull( ( new ValidationCache( Settings::get() ) )->get( $this->number, null ) );
	}

	public function test_requester_is_part_of_the_key(): void {
		$cache     = new ValidationCache( Settings::get() );
		$requester = VatNumber::parse( 'MT20000010', 'MT' );
		$cache->put( $this->number, null, $this->result( ValidationResult::VALID ) );

		$this->assertNull( $cache->get( $this->number, $requester ) );
		$this->assertNotNull( get_transient( $this->key( $this->number ) ) );
	}

	public function test_ttl_per_status(): void {
		$cache = new ValidationCache( Settings::get() );
		$key   = $this->key( $this->number );

		$cache->put( $this->number, null, $this->result( ValidationResult::VALID ) );
		$this->assertEqualsWithDelta( 3 * DAY_IN_SECONDS, $this->timeout_of( $key ), 5 );

		$cache->put( $this->number, null, $this->result( ValidationResult::INVALID ) );
		$this->assertEqualsWithDelta( HOUR_IN_SECONDS, $this->timeout_of( $key ), 5 );

		$cache->put( $this->number, null, $this->result( ValidationResult::UNAVAILABLE ) );
		$this->assertEqualsWithDelta( 5 * MINUTE_IN_SECONDS, $this->timeout_of( $key ), 5 );
	}

	public function test_unchecked_and_format_invalid_are_not_cached(): void {
		$cache = new ValidationCache( Settings::get() );

		$cache->put( $this->number, null, $this->result( ValidationResult::UNCHECKED ) );
		$this->assertFalse( get_transient( $this->key( $this->number ) ) );

		$cache->put( $this->number, null, $this->result( ValidationResult::FORMAT_INVALID ) );
		$this->assertFalse( get_transient( $this->key( $this->number ) ) );
	}

	public function test_validator_goes_to_the_network_once_then_serves_the_cache(): void {
		$requests = 0;
		add_filter(
			'http_request_args',
			static function ( $args ) use ( &$requests ) {
				++$requests;
				return $args;
			}
		);
		HttpGuard::stub(
			'#^https://ec\.europa\.eu/taxation_customs/vies/rest-api/check-vat-number$#',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"valid":true,"name":"Deutsche Telekom AG","address":"Bonn","requestIdentifier":"","requestDate":"2026-10-17T12:00:00.000Z"}',
				'headers'  => array(),
			)
		);
		$validator = new Validator( new Router( new Vies( new WpHttpTransport() ) ), new ValidationCache( Settings::get() ), Settings::get() );

		$first  = $validator->validate( $this->number, true );
		$second = $validator->validate( $this->number, false );

		$this->assertSame( ValidationResult::VALID, $first->status() );
		$this->assertFalse( $first->from_cache() );
		$this->assertSame( ValidationResult::VALID, $second->status() );
		$this->assertTrue( $second->from_cache() );
		$this->assertSame( 1, $requests );
	}

	public function test_registry_timeout_filter_is_clamped_to_five_seconds(): void {
		$timeouts = array();
		add_filter(
			'http_request_args',
			static function ( $args ) use ( &$timeouts ) {
				$timeouts[] = $args['timeout'];
				return $args;
			}
		);
		HttpGuard::stub(
			'#^https://ec\.europa\.eu/#',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{}',
				'headers'  => array(),
			)
		);
		$transport = new WpHttpTransport();

		$transport->request( 'GET', 'https://ec.europa.eu/x', '', array(), 5 );
		add_filter( 'vatright_registry_timeout', '__return_zero' );
		$transport->request( 'GET', 'https://ec.europa.eu/x', '', array(), 5 );
		remove_filter( 'vatright_registry_timeout', '__return_zero' );
		add_filter(
			'vatright_registry_timeout',
			static function () {
				return 30;
			}
		);
		$transport->request( 'GET', 'https://ec.europa.eu/x', '', array(), 5 );

		$this->assertSame( array( 5, 1, 5 ), $timeouts );
	}

	public function test_transport_error_is_reported_without_throwing(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
			},
			PHP_INT_MAX - 1
		);
		remove_filter( 'pre_http_request', array( HttpGuard::class, 'intercept' ), PHP_INT_MAX );

		$response = ( new WpHttpTransport() )->request( 'GET', 'https://ec.europa.eu/x' );

		$this->assertSame( 0, $response['status'] );
		$this->assertSame( 'cURL error 28: timed out', $response['error'] );
	}

	public function test_a_name_verdict_round_trips_per_name_and_number(): void {
		$cache = new ValidationCache( Settings::get() );

		$this->assertNull( $cache->get_name_verdict( $this->number, null, 'Acme' ) );

		$cache->put_name_verdict( $this->number, null, 'Acme', 'mismatch' );

		$this->assertSame( 'mismatch', $cache->get_name_verdict( $this->number, null, 'Acme' ) );
		$this->assertSame( 'mismatch', $cache->get_name_verdict( $this->number, null, ' acme ' ), 'Case and surrounding space do not make a new name.' );
		$this->assertNull( $cache->get_name_verdict( $this->number, null, 'Other' ) );
		$this->assertNull( $cache->get_name_verdict( $this->number, $this->number, 'Acme' ) );
		$this->assertNull( $cache->get( $this->number, null ), 'A verdict is not a validation result.' );
	}

	public function test_a_mismatch_is_kept_for_an_hour_and_a_match_for_the_valid_ttl(): void {
		$cache = new ValidationCache( Settings::get() );
		$cache->put_name_verdict( $this->number, null, 'Wrong', 'mismatch' );
		$cache->put_name_verdict( $this->number, null, 'Right', 'ok' );
		$wrong = $this->name_key( 'Wrong' );
		$right = $this->name_key( 'Right' );

		$this->assertEqualsWithDelta( HOUR_IN_SECONDS, $this->timeout_of( $wrong ), 5 );
		$this->assertEqualsWithDelta( 3 * DAY_IN_SECONDS, $this->timeout_of( $right ), 5 );
	}

	private function name_key( string $name ): string {
		return ValidationCache::PREFIX . 'n_' . md5( $this->number->canonical() . '||' . strtolower( $name ) );
	}
}
