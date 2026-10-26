<?php

namespace Vatright\Account;

use Vatright\Checkout\InputGatherer;
use Vatright\Checkout\VatField;
use Vatright\Domain\FormatCheck;
use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use Vatright\Registry\Validator;
use Vatright\Settings;
use WP_Error;

/**
 * An optional VAT number on the My Account registration form. The number is
 * checked through the same validator as checkout, with its own throttle
 * bucket, and saved as the customer's number. A registry that cannot answer
 * never stops a registration: the number is saved and checked when the
 * customer buys.
 */
class RegistrationField {

	const INPUT = 'vatright_registration_number';

	const BUCKET = 'register';

	/** @var Validator */
	private $validator;

	public function __construct( Validator $validator ) {
		$this->validator = $validator;
	}

	public function register(): void {
		add_action( 'woocommerce_register_form', array( $this, 'render' ) );
		add_filter( 'woocommerce_process_registration_errors', array( $this, 'validate' ) );
		add_action( 'woocommerce_created_customer', array( $this, 'save' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_style' ) );
	}

	public function render(): void {
		if ( ! Settings::get()->registration_vat_field() ) {
			return;
		}
		$typed = isset( $_POST[ self::INPUT ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::INPUT ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only echoes back what the visitor typed after a failed registration.
		?>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="<?php echo esc_attr( self::INPUT ); ?>"><?php esc_html_e( 'VAT number', 'vatright' ); ?>&nbsp;<span class="optional">(<?php esc_html_e( 'optional', 'vatright' ); ?>)</span></label>
			<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="<?php echo esc_attr( self::INPUT ); ?>" id="<?php echo esc_attr( self::INPUT ); ?>" value="<?php echo esc_attr( $typed ); ?>" autocomplete="off" aria-describedby="vatright-registration-disclosure" />
			<span class="vatright-disclosure" id="vatright-registration-disclosure"><?php echo esc_html( VatField::disclosure() ); ?></span>
		</p>
		<?php
	}

	/**
	 * WooCommerce has verified its registration nonce before this filter runs.
	 *
	 * @param mixed $errors
	 * @return mixed
	 */
	public function validate( $errors ) {
		$raw = $this->posted();
		if ( ! $errors instanceof WP_Error || '' === $raw ) {
			return $errors;
		}
		$number = VatNumber::parse( $raw, '' );
		if ( null === $number || ! FormatCheck::is_plausible( $number ) ) {
			$errors->add( 'vatright_registration_format', __( 'This does not look like a VAT number. Start it with the country code, for example DE123456789, or leave the field empty.', 'vatright' ) );
			return $errors;
		}
		$status = $this->validator->validate_for_visitor( $number, self::BUCKET )->status();
		if ( ValidationResult::INVALID === $status || ValidationResult::FORMAT_INVALID === $status ) {
			$errors->add( 'vatright_registration_invalid', __( 'This VAT number is not valid. Correct it, or leave the field empty.', 'vatright' ) );
		}
		return $errors;
	}

	/**
	 * @param mixed $customer_id
	 */
	public function save( $customer_id ): void {
		$raw    = $this->posted();
		$number = '' === $raw ? null : VatNumber::parse( $raw, '' );
		if ( null === $number || ! FormatCheck::is_plausible( $number ) ) {
			return;
		}
		update_user_meta( (int) $customer_id, InputGatherer::NUMBER_META, $number->canonical() );
	}

	public function enqueue_style(): void {
		if ( ! Settings::get()->registration_vat_field() || ! function_exists( 'is_account_page' ) || ! is_account_page() || is_user_logged_in() ) {
			return;
		}
		$style = VatField::asset( 'assets/checkout-block/style.css' );
		wp_enqueue_style( 'vatright-checkout', $style['url'], array(), $style['version'] );
	}

	private function posted(): string {
		if ( ! Settings::get()->registration_vat_field() || ! isset( $_POST[ self::INPUT ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the registration nonce before these hooks run.
			return '';
		}
		return trim( sanitize_text_field( wp_unslash( $_POST[ self::INPUT ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}
}
