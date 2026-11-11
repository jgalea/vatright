<?php

namespace Vatright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vatright\Settings;

require_once __DIR__ . '/../Support/RolesStubs.php';

class ExemptRolesSignupTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['vatright_test_options']      = array();
		$GLOBALS['vatright_test_multisite']    = false;
		$GLOBALS['vatright_test_site_options'] = array();
		$GLOBALS['vatright_filter_overrides']  = array();
		Settings::reset();
	}

	/**
	 * @param string[] $stored
	 * @return string[]
	 */
	private function read( array $stored ): array {
		$GLOBALS['vatright_test_options'][ Settings::OPTION ] = array( 'exempt_roles' => $stored );
		return Settings::get()->exempt_roles();
	}

	public function test_a_stored_default_role_is_not_exempt_once_wordpress_sign_up_is_open(): void {
		$GLOBALS['vatright_test_options'] = array(
			'default_role'       => 'subscriber',
			'users_can_register' => 1,
		);
		$this->assertSame( array( 'customer' ), $this->read( array( 'subscriber', 'customer' ) ) );
	}

	public function test_woocommerce_registration_makes_the_customer_role_non_exempt_whatever_the_default_role_is(): void {
		foreach ( array( 'woocommerce_enable_myaccount_registration', 'woocommerce_enable_signup_and_login_from_checkout' ) as $option ) {
			$GLOBALS['vatright_test_options'] = array(
				'default_role' => 'subscriber',
				$option        => 'yes',
			);
			$this->assertSame( array( 'subscriber' ), $this->read( array( 'subscriber', 'customer' ) ), $option );
		}
	}

	public function test_customer_stays_exempt_when_woocommerce_registration_is_off(): void {
		$GLOBALS['vatright_test_options'] = array(
			'default_role'                              => 'subscriber',
			'woocommerce_enable_myaccount_registration' => 'no',
			'woocommerce_enable_signup_and_login_from_checkout' => 'no',
		);
		$this->assertSame( array( 'customer' ), $this->read( array( 'customer' ) ) );
	}

	public function test_the_role_a_filter_gives_new_customers_is_the_one_that_is_dropped(): void {
		$GLOBALS['vatright_test_options']                                      = array( 'woocommerce_enable_myaccount_registration' => 'yes' );
		$GLOBALS['vatright_filter_overrides']['woocommerce_new_customer_data'] = array( 'role' => 'subscriber' );
		$this->assertSame( array( 'customer' ), $this->read( array( 'subscriber', 'customer' ) ) );
	}

	public function test_a_stored_default_role_is_not_exempt_with_network_registration(): void {
		$GLOBALS['vatright_test_options']                      = array( 'default_role' => 'subscriber' );
		$GLOBALS['vatright_test_multisite']                    = true;
		$GLOBALS['vatright_test_site_options']['registration'] = 'user';
		$this->assertSame( array(), $this->read( array( 'subscriber' ) ) );
	}

	public function test_a_stored_default_role_stays_exempt_when_no_sign_up_path_is_open(): void {
		$GLOBALS['vatright_test_options']                      = array( 'default_role' => 'subscriber' );
		$GLOBALS['vatright_test_multisite']                    = true;
		$GLOBALS['vatright_test_site_options']['registration'] = 'none';
		$this->assertSame( array( 'subscriber' ), $this->read( array( 'subscriber' ) ) );
	}
}
