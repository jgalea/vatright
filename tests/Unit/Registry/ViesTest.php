<?php

namespace Vatright\Tests\Unit\Registry;

use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use Vatright\Registry\HttpTransport;
use Vatright\Registry\Vies;
use Vatright\Tests\Support\FixtureTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ViesTest extends TestCase {

	private function vies(): Vies {
		return new Vies( new FixtureTransport( dirname( __DIR__, 2 ) . '/fixtures/vies' ) );
	}

	private function number( string $canonical ): VatNumber {
		$number = VatNumber::parse( $canonical, substr( $canonical, 0, 2 ) );
		$this->assertNotNull( $number );
		return $number;
	}

	private function replying( int $status, string $body ): Vies {
		return new Vies(
			new class( $status, $body ) implements HttpTransport {
				/** @var int */
				private $status;

				/** @var string */
				private $body;

				public function __construct( int $status, string $body ) {
					$this->status = $status;
					$this->body   = $body;
				}

				public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array {
					return array(
						'status' => $this->status,
						'body'   => $this->body,
						'error'  => '',
					);
				}
			}
		);
	}

	public function test_valid_company_carries_name_and_address_but_no_consultation(): void {
		$result = $this->vies()->check( $this->number( 'BE0417497106' ), null );

		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( 'vies', $result->registry() );
		$this->assertSame( 'NV Anheuser-Busch InBev', $result->name() );
		$this->assertStringContainsString( 'Grote Markt 1', (string) $result->address() );
		$this->assertNull( $result->consultation() );
		$this->assertSame( '', $result->detail() );
	}

	public function test_requester_yields_a_consultation_number(): void {
		$store  = $this->number( 'MT20000010' );
		$result = $this->vies()->check( $store, $store );

		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( '17c58933-84e7-491a-b3a6-30989b27d775', $result->consultation() );
	}

	public function test_explicit_false_is_invalid(): void {
		$result = $this->vies()->check( $this->number( 'MT12345678' ), null );

		$this->assertSame( ValidationResult::INVALID, $result->status() );
		$this->assertNull( $result->name() );
	}

	public function test_false_with_an_error_wrapper_is_not_invalid(): void {
		$result = $this->replying( 200, '{"valid":false,"actionSucceed":false,"errorWrappers":[{"error":"MS_UNAVAILABLE"}]}' )->check( $this->number( 'MT12345678' ), null );

		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertSame( 'MS_UNAVAILABLE', $result->detail() );
	}

	public function test_json_without_a_valid_flag_is_unavailable(): void {
		$result = $this->replying( 200, '{"countryCode":"MT"}' )->check( $this->number( 'MT12345678' ), null );

		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertSame( 'malformed', $result->detail() );
	}

	public function test_valid_flag_on_a_non_200_response_is_unavailable(): void {
		$result = $this->replying( 502, '{"valid":true}' )->check( $this->number( 'MT12345678' ), null );

		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertSame( 'http_502', $result->detail() );
	}

	public function test_member_state_outage_maps_to_unavailable(): void {
		$result = $this->vies()->check( $this->number( 'MT90000001' ), null );

		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertSame( 'MS_UNAVAILABLE', $result->detail() );
	}

	/**
	 * @dataProvider provide_rejections
	 */
	public function test_a_number_vies_rejects_is_invalid_not_unavailable( string $code ): void {
		$result = $this->replying( 200, '{"actionSucceed":false,"errorWrappers":[{"error":"' . $code . '"}]}' )->check( $this->number( 'MT12345678' ), null );

		$this->assertSame( ValidationResult::INVALID, $result->status() );
		$this->assertSame( $code, $result->detail() );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function provide_rejections(): array {
		return array(
			'format rejected' => array( 'INVALID_INPUT' ),
			'number blocked'  => array( 'VAT_BLOCKED' ),
		);
	}

	/**
	 * @dataProvider provide_load_refusals
	 */
	public function test_vies_turning_the_store_away_counts_as_throttled( string $code ): void {
		$result = $this->replying( 200, '{"actionSucceed":false,"errorWrappers":[{"error":"' . $code . '"}]}' )->check( $this->number( 'MT12345678' ), null );

		$this->assertSame( ValidationResult::UNCHECKED, $result->status() );
		$this->assertSame( 'throttled', $result->detail() );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function provide_load_refusals(): array {
		return array(
			'store IP blocked'    => array( 'IP_BLOCKED' ),
			'too many, overall'   => array( 'GLOBAL_MAX_CONCURRENT_REQ' ),
			'too many, one state' => array( 'MS_MAX_CONCURRENT_REQ' ),
		);
	}

	public function test_timeout_maps_to_unavailable(): void {
		$result = $this->vies()->check( $this->number( 'MT90000002' ), null );

		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertSame( 'transport_error', $result->detail() );
	}

	public function test_malformed_body_maps_to_unavailable(): void {
		$result = $this->vies()->check( $this->number( 'MT90000003' ), null );

		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertSame( 'malformed', $result->detail() );
	}

	public function test_http_500_maps_to_unavailable(): void {
		$result = $this->vies()->check( $this->number( 'MT500' ), null );

		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertSame( 'VOW-ERR-1', $result->detail() );
	}

	public function test_transport_exception_maps_to_unavailable(): void {
		$vies = new Vies(
			new class() implements HttpTransport {
				public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array {
					throw new RuntimeException( 'boom' );
				}
			}
		);

		$this->assertSame( ValidationResult::UNAVAILABLE, $vies->check( $this->number( 'MT12345678' ), null )->status() );
	}

	public function test_rejected_requester_is_reported_as_unavailable_with_its_error(): void {
		$result = $this->vies()->check( $this->number( 'MT20000010' ), $this->number( 'MT99999999' ) );

		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertSame( 'INVALID_REQUESTER_INFO', $result->detail() );
	}

	public function test_trader_name_mismatch_is_flagged_on_a_valid_result(): void {
		$result = $this->vies()->check_named( $this->number( 'DE90000004' ), null, 'Acme GmbH' );

		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( 'name_mismatch', $result->detail() );
		$this->assertNull( $result->name() );
	}

	/**
	 * Expected statuses are what VIES answered when the fixtures were recorded.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function recorded_samples(): array {
		$expected = array(
			'DE114103379'    => true,
			'DE115235681'    => true,
			'DE123475223'    => true,
			'DE129273398'    => true,
			'ESA15075062'    => true,
			'ESA39000013'    => true,
			'ESA82018474'    => true,
			'IT00159560366'  => true,
			'IT00743110157'  => true,
			'IT00905811006'  => true,
			'NL004495445B01' => true,
			'NL805734958B01' => true,
			'NL820646660B01' => true,
			'BE0202239951'   => true,
			'BE0403019261'   => false,
			'BE0417497106'   => true,
			'PL7342867148'   => true,
			'PL7740001454'   => true,
			'PT500697256'    => true,
			'PT504499777'    => true,
			'XI980780684'    => false,
		);
		$rows     = array();
		foreach ( $expected as $canonical => $is_valid ) {
			$rows[ $canonical ] = array( $canonical, $is_valid );
		}
		return $rows;
	}

	/**
	 * @dataProvider recorded_samples
	 */
	public function test_recorded_sample_numbers( string $canonical, bool $is_valid ): void {
		$result = $this->vies()->check( $this->number( $canonical ), null );

		$this->assertSame( $is_valid ? ValidationResult::VALID : ValidationResult::INVALID, $result->status(), $canonical );
	}

	public function test_every_recorded_sample_has_an_expectation(): void {
		$files = glob( dirname( __DIR__, 2 ) . '/fixtures/vies/sample_*.json' );

		$this->assertCount( count( $this->recorded_samples() ), false === $files ? array() : $files );
	}

	public function test_request_body_uses_the_vies_prefix_and_requester(): void {
		$transport = new class() implements HttpTransport {
			/** @var array<int,array<string,mixed>> */
			public $calls = array();

			public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array {
				$this->calls[] = array(
					'method'  => $method,
					'url'     => $url,
					'body'    => json_decode( $body, true ),
					'timeout' => $timeout,
				);
				return array(
					'status' => 200,
					'body'   => '{"valid":false}',
					'error'  => '',
				);
			}
		};

		( new Vies( $transport, 4 ) )->check( $this->number( 'EL123456789' ), $this->number( 'MT20000010' ) );

		$this->assertSame( 'POST', $transport->calls[0]['method'] );
		$this->assertSame( Vies::BASE_URL . '/check-vat-number', $transport->calls[0]['url'] );
		$this->assertSame( 4, $transport->calls[0]['timeout'] );
		$this->assertSame(
			array(
				'countryCode'              => 'EL',
				'vatNumber'                => '123456789',
				'requesterMemberStateCode' => 'MT',
				'requesterNumber'          => '20000010',
			),
			$transport->calls[0]['body']
		);
	}

	public function test_supports_eu_and_northern_ireland_only(): void {
		$vies = $this->vies();

		$this->assertTrue( $vies->supports( $this->number( 'DE123475223' ) ) );
		$this->assertTrue( $vies->supports( $this->number( 'XI980780684' ) ) );
		$this->assertFalse( $vies->supports( $this->number( 'GB980780684' ) ) );
		$this->assertFalse( $vies->supports( $this->number( 'NO923609016' ) ) );
		$this->assertFalse( $vies->supports( $this->number( 'CHE101329561' ) ) );
	}

	public function test_status_lists_availability_per_country(): void {
		$status = $this->vies()->status();

		$this->assertSame( 'Available', $status['DE'] );
		$this->assertArrayHasKey( 'EL', $status );
		$this->assertArrayHasKey( 'XI', $status );
		$this->assertCount( 28, $status );
	}

	public function test_status_is_empty_when_unreadable(): void {
		$this->assertSame( array(), $this->replying( 503, '<html>' )->status() );
	}
}
