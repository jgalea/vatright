<?php

namespace Vatright\Tests\Integration\Registry;

use Vatright\Registry\WpHttpTransport;
use Vatright\Tests\Support\HttpGuard;
use Vatright\Tests\Support\IntegrationTestCase;

class WpHttpTransportTest extends IntegrationTestCase {

	/** @var array<string,mixed> */
	private $sent = array();

	public function set_up() {
		parent::set_up();
		HttpGuard::install();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) {
				$this->sent = $args;
				return array(
					'headers'  => array(),
					'body'     => '{}',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			2
		);
	}

	public function tear_down() {
		HttpGuard::uninstall();
		parent::tear_down();
	}

	/**
	 * Through WordPress's HTTP API, ec.europa.eu's compressed answers end in
	 * cURL error 56, so every VIES check came back unavailable.
	 */
	public function test_asks_registries_for_an_uncompressed_answer() {
		( new WpHttpTransport() )->request( 'POST', 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number', '{}', array( 'Content-Type' => 'application/json' ) );

		$this->assertSame( 'identity', $this->sent['headers']['Accept-Encoding'] ?? null );
		$this->assertSame( 'application/json', $this->sent['headers']['Content-Type'] ?? null );
	}

	public function test_a_caller_can_still_set_its_own_encoding() {
		( new WpHttpTransport() )->request( 'GET', 'https://example.com/', '', array( 'Accept-Encoding' => 'gzip' ) );

		$this->assertSame( 'gzip', $this->sent['headers']['Accept-Encoding'] ?? null );
	}
}
