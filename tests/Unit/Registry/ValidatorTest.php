<?php

namespace Vatright\Tests\Unit\Registry;

use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use Vatright\Registry\HttpTransport;
use Vatright\Registry\LookupThrottle;
use Vatright\Registry\NameMatching;
use Vatright\Registry\Registry;
use Vatright\Registry\Router;
use Vatright\Registry\ValidationCache;
use Vatright\Registry\Validator;
use Vatright\Registry\Vies;
use Vatright\Settings;
use Vatright\Tests\Support\FixtureTransport;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase {

	private function number( string $canonical ): VatNumber {
		$number = VatNumber::parse( $canonical, substr( $canonical, 0, 2 ) );
		$this->assertNotNull( $number );
		return $number;
	}

	private function settings( string $store_vat_number, string $store_vat_number_gb = '' ): Settings {
		return new class( $store_vat_number, $store_vat_number_gb ) extends Settings {
			/** @var string */
			private $store;

			/** @var string */
			private $store_gb;

			public function __construct( string $store, string $store_gb ) {
				$this->store    = $store;
				$this->store_gb = $store_gb;
			}

			public function store_vat_number(): string {
				return $this->store;
			}

			public function store_vat_number_gb(): string {
				return $this->store_gb;
			}
		};
	}

	private function cache(): ValidationCache {
		return new class( new Settings() ) extends ValidationCache {
			/** @var array<string,ValidationResult> */
			public $stored = array();

			/** @var array<string,string> */
			public $verdicts = array();

			public function get( VatNumber $n, ?VatNumber $requester ): ?ValidationResult {
				$key = $n->canonical() . '|' . ( null === $requester ? '' : $requester->canonical() );
				return isset( $this->stored[ $key ] ) ? $this->stored[ $key ]->with_from_cache( true ) : null;
			}

			public function put( VatNumber $n, ?VatNumber $requester, ValidationResult $result ): void {
				$this->stored[ $n->canonical() . '|' . ( null === $requester ? '' : $requester->canonical() ) ] = $result;
			}

			public function get_name_verdict( VatNumber $n, ?VatNumber $requester, string $name ): ?string {
				return $this->verdicts[ $n->canonical() . '|' . ( null === $requester ? '' : $requester->canonical() ) . '|' . $name ] ?? null;
			}

			public function put_name_verdict( VatNumber $n, ?VatNumber $requester, string $name, string $verdict ): void {
				$this->verdicts[ $n->canonical() . '|' . ( null === $requester ? '' : $requester->canonical() ) . '|' . $name ] = $verdict;
			}
		};
	}

	/**
	 * Answers a name check with a mismatch for any name but "Right Name", and
	 * records the names it was asked about.
	 */
	private function name_registry(): NameMatching {
		return new class() implements NameMatching {
			/** @var array<int,string|null> */
			public $names = array();

			public function id(): string {
				return 'double';
			}

			public function supports( VatNumber $n ): bool {
				return $n->is_eu();
			}

			public function check( VatNumber $n, ?VatNumber $requester ): ValidationResult {
				$this->names[] = null;
				return new ValidationResult( ValidationResult::VALID, 'double', '2026-10-17T00:00:00Z' );
			}

			public function check_named( VatNumber $n, ?VatNumber $requester, string $trader_name ): ValidationResult {
				$this->names[] = $trader_name;
				return new ValidationResult( ValidationResult::VALID, 'double', '2026-10-17T00:00:00Z', null, null, null, false, 'Right Name' === $trader_name ? '' : 'name_mismatch' );
			}
		};
	}

	/**
	 * Registry double that records every check and answers VALID unless the
	 * requester is present and the double was told to reject it.
	 */
	private function registry( string $prefix, bool $rejects_requester = false ): Registry {
		return new class( $prefix, $rejects_requester ) implements Registry {
			/** @var string */
			private $prefix;

			/** @var bool */
			private $rejects;

			/** @var array<int,VatNumber|null> */
			public $requesters = array();

			public function __construct( string $prefix, bool $rejects ) {
				$this->prefix  = $prefix;
				$this->rejects = $rejects;
			}

			public function id(): string {
				return 'double';
			}

			public function supports( VatNumber $n ): bool {
				return $n->prefix() === $this->prefix || ( 'EU' === $this->prefix && $n->is_eu() );
			}

			public function check( VatNumber $n, ?VatNumber $requester ): ValidationResult {
				$this->requesters[] = $requester;
				if ( $this->rejects && null !== $requester ) {
					return new ValidationResult( ValidationResult::UNAVAILABLE, 'double', '2026-10-17T00:00:00Z', null, null, null, false, 'INVALID_REQUESTER_INFO' );
				}
				return new ValidationResult( ValidationResult::VALID, 'double', '2026-10-17T00:00:00Z' );
			}
		};
	}

	public function test_format_invalid_never_calls_network(): void {
		$registry  = $this->registry( 'EU' );
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( '' ) );

		$result = $validator->validate( $this->number( 'DE123475224' ), true );

		$this->assertSame( ValidationResult::FORMAT_INVALID, $result->status() );
		$this->assertCount( 0, $registry->requesters );
	}

	public function test_no_network_returns_unchecked(): void {
		$registry  = $this->registry( 'EU' );
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( '' ) );

		$result = $validator->validate( $this->number( 'DE123475223' ), false );

		$this->assertSame( ValidationResult::UNCHECKED, $result->status() );
		$this->assertSame( 'cache_miss', $result->detail() );
		$this->assertCount( 0, $registry->requesters );
	}

	public function test_cache_hit_skips_network(): void {
		$registry  = $this->registry( 'EU' );
		$cache     = $this->cache();
		$validator = new Validator( new Router( $registry ), $cache, $this->settings( '' ) );
		$cache->put( $this->number( 'DE123475223' ), null, new ValidationResult( ValidationResult::VALID, 'vies', '2026-10-17T00:00:00Z', 'Cached AG' ) );

		$result = $validator->validate( $this->number( 'DE123475223' ), true );

		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertTrue( $result->from_cache() );
		$this->assertSame( 'Cached AG', $result->name() );
		$this->assertCount( 0, $registry->requesters );
	}

	public function test_network_result_is_cached_for_the_next_call(): void {
		$registry  = $this->registry( 'EU' );
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( '' ) );

		$first  = $validator->validate( $this->number( 'DE123475223' ), true );
		$second = $validator->validate( $this->number( 'DE123475223' ), true );

		$this->assertFalse( $first->from_cache() );
		$this->assertTrue( $second->from_cache() );
		$this->assertCount( 1, $registry->requesters );
	}

	public function test_number_without_a_registry_is_unchecked(): void {
		$validator = new Validator( new Router( $this->registry( 'GB' ) ), $this->cache(), $this->settings( '' ) );

		$result = $validator->validate( $this->number( 'DE123475223' ), true );

		$this->assertSame( ValidationResult::UNCHECKED, $result->status() );
		$this->assertSame( 'no_registry', $result->detail() );
	}

	public function test_store_number_is_the_requester_for_the_same_registry_family(): void {
		$registry  = $this->registry( 'EU' );
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( 'MT20000010' ) );

		$validator->validate( $this->number( 'DE123475223' ), true );

		$this->assertNotNull( $registry->requesters[0] );
		$this->assertSame( 'MT20000010', $registry->requesters[0]->canonical() );
	}

	public function test_store_number_is_not_sent_to_another_registry_family(): void {
		$registry  = $this->registry( 'GB' );
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( 'MT20000010' ) );

		$validator->validate( $this->number( 'GB980780684' ), true );

		$this->assertNull( $registry->requesters[0] );
	}

	public function test_requester_rejected_retries_without_requester(): void {
		$registry  = $this->registry( 'EU', true );
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( 'MT20000010' ) );

		$result = $validator->validate( $this->number( 'DE123475223' ), true );

		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( 'requester_rejected', $result->detail() );
		$this->assertCount( 2, $registry->requesters );
		$this->assertNotNull( $registry->requesters[0] );
		$this->assertNull( $registry->requesters[1] );
	}

	public function test_requester_rejected_against_recorded_vies_responses(): void {
		$vies      = new Vies( new FixtureTransport( dirname( __DIR__, 2 ) . '/fixtures/vies' ) );
		$validator = new Validator( new Router( $vies ), $this->cache(), $this->settings( 'MT99999999' ) );

		$result = $validator->validate( $this->number( 'MT20000010' ), true );

		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( 'requester_rejected', $result->detail() );
		$this->assertSame( 'Example Ltd', $result->name() );
		$this->assertNull( $result->consultation() );
	}

	public function test_unavailable_without_requester_problem_is_returned_as_is(): void {
		$transport = new class() implements HttpTransport {
			/** @var int */
			public $calls = 0;

			public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array {
				++$this->calls;
				return array(
					'status' => 0,
					'body'   => '',
					'error'  => 'timed out',
				);
			}
		};
		$validator = new Validator( new Router( new Vies( $transport ) ), $this->cache(), $this->settings( 'MT20000010' ) );

		$result = $validator->validate( $this->number( 'DE123475223' ), true );

		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertSame( 1, $transport->calls );
	}

	private function throttle( bool $allows ): LookupThrottle {
		return new class( $allows ) extends LookupThrottle {
			/** @var bool */
			private $allows;

			/** @var string[] */
			public $asked = array();

			public function __construct( bool $allows ) {
				$this->allows = $allows;
			}

			public function allow( string $bucket ): bool {
				$this->asked[] = $bucket;
				return $this->allows;
			}
		};
	}

	public function test_throttled_visitor_lookup_is_unchecked_without_a_registry_call_or_cache_entry(): void {
		$registry  = $this->registry( 'EU' );
		$cache     = $this->cache();
		$throttle  = $this->throttle( false );
		$validator = new Validator( new Router( $registry ), $cache, $this->settings( '' ), $throttle );

		$result = $validator->validate_for_visitor( $this->number( 'DE123475223' ), 'update' );

		$this->assertSame( ValidationResult::UNCHECKED, $result->status() );
		$this->assertSame( 'throttled', $result->detail() );
		$this->assertSame( array( 'update' ), $throttle->asked );
		$this->assertCount( 0, $registry->requesters );
		$this->assertSame( array(), $cache->stored );
	}

	public function test_allowed_visitor_lookup_reaches_the_registry(): void {
		$registry  = $this->registry( 'EU' );
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( '' ), $this->throttle( true ) );

		$result = $validator->validate_for_visitor( $this->number( 'DE123475223' ), 'checkout' );

		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertCount( 1, $registry->requesters );
	}

	public function test_cached_answer_is_never_counted_against_the_throttle(): void {
		$cache     = $this->cache();
		$throttle  = $this->throttle( false );
		$validator = new Validator( new Router( $this->registry( 'EU' ) ), $cache, $this->settings( '' ), $throttle );
		$cache->put( $this->number( 'DE123475223' ), null, new ValidationResult( ValidationResult::VALID, 'vies', '2026-10-17T00:00:00Z' ) );

		$result = $validator->validate_for_visitor( $this->number( 'DE123475223' ), 'update' );

		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( array(), $throttle->asked );
	}

	public function test_revalidation_and_admin_lookups_are_never_throttled(): void {
		$registry  = $this->registry( 'EU' );
		$throttle  = $this->throttle( false );
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( '' ), $throttle );

		$validator->validate( $this->number( 'DE123475223' ), true, true );
		$validator->validate( $this->number( 'DE129273398' ), true );

		$this->assertCount( 2, $registry->requesters );
		$this->assertSame( array(), $throttle->asked );
	}

	public function test_a_registry_that_matches_names_is_asked_with_the_name(): void {
		$registry  = $this->name_registry();
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( '' ) );

		$result = $validator->validate( $this->number( 'DE123475223' ), true, false, 'Wrong Name' );

		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( 'name_mismatch', $result->detail() );
		$this->assertSame( array( 'Wrong Name' ), $registry->names );
	}

	public function test_no_name_means_a_plain_check(): void {
		$registry  = $this->name_registry();
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( '' ) );

		$result = $validator->validate( $this->number( 'DE123475223' ), true );

		$this->assertSame( '', $result->detail() );
		$this->assertSame( array( null ), $registry->names );
	}

	public function test_a_registry_without_name_matching_is_never_given_the_name(): void {
		$registry  = $this->registry( 'EU' );
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( '' ) );

		$result = $validator->validate( $this->number( 'DE123475223' ), true, false, 'Wrong Name' );

		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( '', $result->detail() );
		$this->assertCount( 1, $registry->requesters );
	}

	public function test_a_name_verdict_is_cached_per_name(): void {
		$registry  = $this->name_registry();
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( '' ) );
		$number    = $this->number( 'DE123475223' );

		$first  = $validator->validate( $number, true, false, 'Wrong Name' );
		$second = $validator->validate( $number, true, false, 'Wrong Name' );
		$fixed  = $validator->validate( $number, true, false, 'Right Name' );

		$this->assertSame( 'name_mismatch', $first->detail() );
		$this->assertSame( 'name_mismatch', $second->detail() );
		$this->assertTrue( $second->from_cache() );
		$this->assertSame( '', $fixed->detail() );
		$this->assertSame( array( 'Wrong Name', 'Right Name' ), $registry->names );
	}

	public function test_a_name_mismatch_does_not_leak_into_plain_lookups(): void {
		$registry  = $this->name_registry();
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( '' ) );
		$number    = $this->number( 'DE123475223' );

		$validator->validate( $number, true, false, 'Wrong Name' );
		$plain = $validator->validate( $number, false );

		$this->assertSame( ValidationResult::VALID, $plain->status() );
		$this->assertSame( '', $plain->detail() );
		$this->assertCount( 1, $registry->names );
	}

	public function test_a_cached_number_without_a_verdict_for_the_name_is_asked_again(): void {
		$registry  = $this->name_registry();
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( '' ) );
		$number    = $this->number( 'DE123475223' );

		$validator->validate( $number, true );
		$named = $validator->validate( $number, true, false, 'Wrong Name' );

		$this->assertSame( 'name_mismatch', $named->detail() );
		$this->assertSame( array( null, 'Wrong Name' ), $registry->names );
	}

	public function test_a_lookup_that_cannot_use_the_network_never_invents_a_mismatch(): void {
		$registry  = $this->name_registry();
		$cache     = $this->cache();
		$validator = new Validator( new Router( $registry ), $cache, $this->settings( '' ) );
		$number    = $this->number( 'DE123475223' );
		$cache->put( $number, null, new ValidationResult( ValidationResult::VALID, 'vies', '2026-10-17T00:00:00Z' ) );

		$result = $validator->validate( $number, false, false, 'Wrong Name' );

		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( '', $result->detail() );
		$this->assertSame( array(), $registry->names );
	}

	public function test_a_throttled_name_check_keeps_the_cached_valid_answer(): void {
		$registry  = $this->name_registry();
		$cache     = $this->cache();
		$validator = new Validator( new Router( $registry ), $cache, $this->settings( '' ), $this->throttle( false ) );
		$number    = $this->number( 'DE123475223' );
		$cache->put( $number, null, new ValidationResult( ValidationResult::VALID, 'vies', '2026-10-17T00:00:00Z' ) );

		$result = $validator->validate_for_visitor( $number, 'checkout', 'Wrong Name' );

		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( '', $result->detail() );
		$this->assertSame( array(), $registry->names );
	}

	public function test_an_invalid_number_is_not_asked_about_its_name_again(): void {
		$registry  = $this->name_registry();
		$cache     = $this->cache();
		$validator = new Validator( new Router( $registry ), $cache, $this->settings( '' ) );
		$number    = $this->number( 'DE123475223' );
		$cache->put( $number, null, new ValidationResult( ValidationResult::INVALID, 'vies', '2026-10-17T00:00:00Z' ) );

		$result = $validator->validate( $number, true, false, 'Wrong Name' );

		$this->assertSame( ValidationResult::INVALID, $result->status() );
		$this->assertSame( array(), $registry->names );
	}

	public function test_the_name_is_kept_when_a_rejected_requester_is_dropped(): void {
		$registry  = new class() implements NameMatching {
			/** @var array<int,array{0:bool,1:string}> */
			public $calls = array();

			public function id(): string {
				return 'double';
			}

			public function supports( VatNumber $n ): bool {
				return true;
			}

			public function check( VatNumber $n, ?VatNumber $requester ): ValidationResult {
				return $this->check_named( $n, $requester, '' );
			}

			public function check_named( VatNumber $n, ?VatNumber $requester, string $trader_name ): ValidationResult {
				$this->calls[] = array( null !== $requester, $trader_name );
				if ( null !== $requester ) {
					return new ValidationResult( ValidationResult::UNAVAILABLE, 'double', '2026-10-17T00:00:00Z', null, null, null, false, 'INVALID_REQUESTER_INFO' );
				}
				return new ValidationResult( ValidationResult::VALID, 'double', '2026-10-17T00:00:00Z', null, null, null, false, 'name_mismatch' );
			}
		};
		$validator = new Validator( new Router( $registry ), $this->cache(), $this->settings( 'MT20000010' ) );

		$result = $validator->validate( $this->number( 'DE123475223' ), true, false, 'Wrong Name' );

		$this->assertSame( 'name_mismatch', $result->detail() );
		$this->assertSame( array( array( true, 'Wrong Name' ), array( false, 'Wrong Name' ) ), $registry->calls );
	}

	public function test_with_two_store_numbers_each_registry_gets_the_one_it_takes(): void {
		$eu        = $this->registry( 'EU' );
		$gb        = $this->registry( 'GB' );
		$validator = new Validator( new Router( $eu, $gb ), $this->cache(), $this->settings( 'MT20000010', 'GB980780684' ) );

		$validator->validate( $this->number( 'DE123475223' ), true );
		$validator->validate( $this->number( 'GB980780684' ), true );

		$this->assertSame( 'MT20000010', $eu->requesters[0]->canonical() );
		$this->assertSame( 'GB980780684', $gb->requesters[0]->canonical() );
	}

	public function test_with_only_the_eu_number_the_gb_registry_gets_no_requester(): void {
		$gb        = $this->registry( 'GB' );
		$validator = new Validator( new Router( $gb ), $this->cache(), $this->settings( 'MT20000010' ) );

		$validator->validate( $this->number( 'GB980780684' ), true );

		$this->assertNull( $gb->requesters[0] );
	}

	public function test_with_only_the_gb_number_vies_gets_no_requester_and_hmrc_gets_it(): void {
		$eu        = $this->registry( 'EU' );
		$gb        = $this->registry( 'GB' );
		$validator = new Validator( new Router( $eu, $gb ), $this->cache(), $this->settings( '', 'GB980780684' ) );

		$validator->validate( $this->number( 'DE123475223' ), true );
		$validator->validate( $this->number( 'GB980780684' ), true );

		$this->assertNull( $eu->requesters[0] );
		$this->assertSame( 'GB980780684', $gb->requesters[0]->canonical() );
	}

	public function test_a_gb_number_in_the_main_field_still_serves_hmrc(): void {
		$gb        = $this->registry( 'GB' );
		$validator = new Validator( new Router( $gb ), $this->cache(), $this->settings( 'GB980780684' ) );

		$validator->validate( $this->number( 'GB980780684' ), true );

		$this->assertSame( 'GB980780684', $gb->requesters[0]->canonical() );
	}
}
