<?php

namespace Vatright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vatright\Settings;

require_once __DIR__ . '/../Support/WpOptionStubs.php';

class SettingsSecretTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['vatright_test_options'] = array();
		Settings::reset();
	}

	public function test_the_secret_comes_from_its_own_option(): void {
		$GLOBALS['vatright_test_options'] = array(
			Settings::SECRET_OPTION => 'own-secret',
			Settings::OPTION        => array( 'hmrc_client_secret' => 'old-secret' ),
		);

		$this->assertSame( 'own-secret', Settings::get()->hmrc_client_secret() );
	}

	public function test_an_upgrade_moves_a_stored_secret_out_of_the_settings_option(): void {
		$GLOBALS['vatright_test_options'] = array(
			Settings::OPTION => array(
				'hmrc_client_id'     => 'id',
				'hmrc_client_secret' => 'old-secret',
			),
		);

		Settings::move_secret_out_of_settings();

		$this->assertSame( 'old-secret', $GLOBALS['vatright_test_options'][ Settings::SECRET_OPTION ] );
		$this->assertSame( '', $GLOBALS['vatright_test_options'][ Settings::OPTION ]['hmrc_client_secret'] );
		$this->assertSame( 'id', $GLOBALS['vatright_test_options'][ Settings::OPTION ]['hmrc_client_id'] );
		$this->assertSame( 'old-secret', Settings::get()->hmrc_client_secret() );
	}

	public function test_an_upgrade_keeps_the_secret_already_in_its_own_option(): void {
		$GLOBALS['vatright_test_options'] = array(
			Settings::SECRET_OPTION => 'own-secret',
			Settings::OPTION        => array( 'hmrc_client_secret' => 'stale-secret' ),
		);

		Settings::move_secret_out_of_settings();

		$this->assertSame( 'own-secret', $GLOBALS['vatright_test_options'][ Settings::SECRET_OPTION ] );
		$this->assertSame( '', $GLOBALS['vatright_test_options'][ Settings::OPTION ]['hmrc_client_secret'] );
	}

	public function test_a_secret_saved_before_the_split_is_still_read(): void {
		$GLOBALS['vatright_test_options'] = array( Settings::OPTION => array( 'hmrc_client_secret' => 'old-secret' ) );

		$this->assertSame( 'old-secret', Settings::get()->hmrc_client_secret() );
	}
}
