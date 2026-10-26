<?php

namespace Vatright\Tests\Integration\Account;

use Vatright\Checkout\InputGatherer;
use Vatright\Checkout\VatField;
use Vatright\Tests\Integration\Checkout\CheckoutTestCase;
use WP_Error;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.Security.NonceVerification.Missing -- the tests fire WooCommerce's own hooks and post to them directly.

require_once dirname( __DIR__ ) . '/Checkout/CheckoutTestCase.php';

class RegistrationFieldTest extends CheckoutTestCase {

	const INPUT = 'vatright_registration_number';

	public function tear_down() {
		$_POST = array();
		parent::tear_down();
	}

	private function enable(): void {
		$this->settings( array( 'registration_vat_field' => true ) );
	}

	private function form(): string {
		ob_start();
		do_action( 'woocommerce_register_form' );
		return (string) ob_get_clean();
	}

	private function errors( string $typed ): WP_Error {
		$_POST[ self::INPUT ] = $typed;
		return apply_filters( 'woocommerce_process_registration_errors', new WP_Error(), 'ada', 'pw', 'ada@example.com' );
	}

	private function register( string $typed ): int {
		$_POST[ self::INPUT ] = $typed;
		$id                   = self::factory()->user->create( array( 'role' => 'customer' ) );
		do_action( 'woocommerce_created_customer', $id, array(), 'pw' );
		return $id;
	}

	public function test_the_form_has_no_field_until_the_setting_is_on() {
		$this->assertStringNotContainsString( self::INPUT, $this->form() );

		$this->enable();
		$html = $this->form();

		$this->assertStringContainsString( 'name="' . self::INPUT . '"', $html );
		$this->assertStringContainsString( 'VAT number', $html );
		$this->assertStringContainsString( 'optional', strtolower( $html ) );
		$this->assertStringContainsString( 'tax registry', $html, 'The disclosure of where the number is sent is shown' );
	}

	public function test_with_the_setting_off_a_posted_number_is_ignored() {
		$errors = $this->errors( self::INVALID_DE );
		$this->assertFalse( $errors->has_errors() );

		$id = $this->register( self::VALID_DE );
		$this->assertSame( '', (string) get_user_meta( $id, InputGatherer::NUMBER_META, true ) );
		$this->assertSame( 0, $this->vies_calls );
	}

	public function test_an_empty_field_registers_without_a_lookup() {
		$this->enable();

		$this->assertFalse( $this->errors( '  ' )->has_errors() );
		$id = $this->register( '' );

		$this->assertSame( '', (string) get_user_meta( $id, InputGatherer::NUMBER_META, true ) );
		$this->assertSame( 0, $this->vies_calls );
	}

	public function test_a_valid_number_is_checked_by_the_registry_and_saved_as_the_customers_number() {
		$this->enable();

		$this->assertFalse( $this->errors( 'de 114.103.379' )->has_errors() );
		$this->assertSame( 1, $this->vies_calls );

		$id = $this->register( 'de 114.103.379' );
		$this->assertSame( self::VALID_DE, get_user_meta( $id, InputGatherer::NUMBER_META, true ) );
	}

	public function test_an_invalid_number_is_refused_with_a_clear_error() {
		$this->enable();
		$this->vies_answer( self::INVALID_DE, '{"countryCode":"DE","vatNumber":"123475223","valid":false}' );

		$errors = $this->errors( self::INVALID_DE );

		$this->assertTrue( $errors->has_errors() );
		$this->assertSame( 'vatright_registration_invalid', $errors->get_error_code() );
		$this->assertStringContainsString( 'not valid', $errors->get_error_message() );
	}

	public function test_text_that_is_not_a_vat_number_is_refused() {
		$this->enable();

		$errors = $this->errors( 'hello' );

		$this->assertSame( 'vatright_registration_format', $errors->get_error_code() );
		$this->assertStringContainsString( 'country code', $errors->get_error_message() );
		$this->assertSame( 0, $this->vies_calls );
	}

	public function test_a_number_without_a_country_prefix_is_refused_because_registration_has_no_billing_country() {
		$this->enable();

		$this->assertSame( 'vatright_registration_format', $this->errors( '114103379' )->get_error_code() );
	}

	public function test_an_unavailable_registry_lets_registration_through_and_keeps_the_number_for_later() {
		$this->enable();
		$this->vies_answer( self::DOWN_DE, '{"actionSucceed":false,"errorWrappers":[{"error":"MS_UNAVAILABLE"}]}' );

		$this->assertFalse( $this->errors( self::DOWN_DE )->has_errors() );
		$this->assertSame( 1, $this->vies_calls, 'The registry was asked and could not answer' );
		$id = $this->register( self::DOWN_DE );

		$this->assertSame( self::DOWN_DE, get_user_meta( $id, InputGatherer::NUMBER_META, true ) );
	}

	public function test_a_throttled_lookup_lets_registration_through_without_reaching_the_registry() {
		$this->enable();
		add_filter(
			'vatright_client_lookup_limit',
			static function () {
				return array( 0, 60 );
			}
		);

		$this->assertFalse( $this->errors( self::VALID_DE )->has_errors() );
		$this->assertSame( 0, $this->vies_calls );
		$this->assertSame( self::VALID_DE, get_user_meta( $this->register( self::VALID_DE ), InputGatherer::NUMBER_META, true ) );
	}

	public function test_registration_lookups_use_their_own_throttle_bucket() {
		$this->enable();
		$seen = array();
		add_filter(
			'vatright_global_lookup_limit',
			static function ( $limit, $bucket ) use ( &$seen ) {
				$seen[] = $bucket;
				return $limit;
			},
			10,
			2
		);

		$this->errors( self::VALID_DE );

		$this->assertSame( array( 'register' ), $seen );
	}

	public function test_a_number_that_never_validated_is_not_saved_by_a_registration_that_skipped_the_check() {
		$this->enable();

		$id = $this->register( 'hello' );

		$this->assertSame( '', (string) get_user_meta( $id, InputGatherer::NUMBER_META, true ) );
	}

	public function test_an_account_created_at_checkout_keeps_the_number_entered_there() {
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$this->add_to_cart( $this->product( 'digital' ) );
		$this->customer( 'DE', '10115', '' );

		$reply = $this->classic_checkout(
			'DE',
			'10115',
			array(
				'billing_vatright_number' => self::VALID_DE,
				'createaccount'           => '1',
				'account_password'        => 'a-long-test-password-1',
				'billing_email'           => 'newbuyer@example.com',
			)
		);

		$this->assertSame( 'success', $reply['result'] ?? '', (string) wp_json_encode( $reply ) );
		$user = get_user_by( 'email', 'newbuyer@example.com' );
		$this->assertNotFalse( $user, 'The checkout created an account' );
		$this->assertSame( self::VALID_DE, get_user_meta( $user->ID, InputGatherer::NUMBER_META, true ) );
	}

	public function test_an_account_created_at_block_checkout_keeps_the_number_entered_there() {
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$this->add_to_cart( $this->product( 'digital' ) );
		$this->customer( 'DE', '10115', '' );

		$response = $this->block_checkout(
			'DE',
			'10115',
			array(
				'create_account'    => true,
				'additional_fields' => array( VatField::FIELD_ID => self::VALID_DE ),
			)
		);

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$user = get_user_by( 'email', 'ada@example.com' );
		$this->assertNotFalse( $user, 'The checkout created an account' );
		$this->assertSame( self::VALID_DE, get_user_meta( $user->ID, InputGatherer::NUMBER_META, true ) );
	}
}
