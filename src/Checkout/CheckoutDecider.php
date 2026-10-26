<?php

namespace Vatright\Checkout;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Vatright\Domain\Decision;
use Vatright\Orders\DecisionStore;
use Vatright\Settings;
use WC_Order;
use WP_Error;

/**
 * Classic checkout calculates totals before its validation hook, so the
 * checkout is armed on woocommerce_checkout_process (before that
 * calculation) and the validation hook only reports the decision's error.
 */
class CheckoutDecider {

	/** @var CartSteering */
	private $steering;

	public function __construct( CartSteering $steering ) {
		$this->steering = $steering;
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_process', array( $this->steering, 'arm_checkout' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( $this, 'decide_block' ), 10, 0 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'record' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'record' ) );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function validate_classic( $data, WP_Error $errors ): void {
		$code = self::error_code( $this->steering->current() );
		if ( null !== $code ) {
			$errors->add( 'vatright_' . $code, self::message( $code ) );
		}
	}

	/**
	 * Store API turns the RouteException into a 400 response with our code.
	 */
	public function decide_block(): void {
		$this->steering->arm_checkout();
		$code = self::error_code( $this->steering->decide( WC()->cart ) );
		if ( null !== $code ) {
			// @phpstan-ignore class.notFound, class.notFound (Store API classes are not in the WooCommerce stubs.)
			throw new RouteException( 'vatright_' . esc_html( $code ), esc_html( self::message( $code ) ), 400 );
		}
	}

	public function record( WC_Order $order ): void {
		$decision = $this->steering->current();
		if ( null !== $decision ) {
			DecisionStore::apply( $order, $decision, 'checkout' );
		}
	}

	public static function requires_confirmation( ?Decision $decision ): bool {
		$settings = Settings::get();
		return null !== $decision && $settings->self_certification() && $settings->self_certification_required() && $decision->needs_location_confirmation();
	}

	private static function error_code( ?Decision $decision ): ?string {
		$blocking = null === $decision ? null : $decision->blocking_error();
		return $blocking
			?? ( NameMismatch::refuses( $decision ) ? NameMismatch::CODE : null )
			?? B2bOnly::error( $decision, B2bOnly::billing_country() )
			?? ( self::requires_confirmation( $decision ) ? 'self_certification_required' : null );
	}

	public static function message( string $code, ?string $country = null ): string {
		$country = $country ?? B2bOnly::billing_country();
		$b2b     = in_array( $code, array( 'b2b_only_required', 'b2b_only_unverified' ), true )
			|| ( in_array( $code, array( 'invalid_vat_number', 'registry_unavailable' ), true ) && B2bOnly::applies( $country ) );
		if ( $b2b ) {
			return B2bOnly::message( $code, $country );
		}
		switch ( $code ) {
			case 'invalid_vat_number':
				return __( 'This VAT number is not valid. Correct it, or leave the field empty to pay with VAT.', 'vatright' );
			case 'registry_unavailable':
				return __( 'We could not check your VAT number with the tax authority just now. Try again in a few minutes, or leave the field empty to pay with VAT.', 'vatright' );
			case NameMismatch::CODE:
				if ( B2bOnly::applies( $country ) ) {
					return __( 'The company name does not match the name registered for this VAT number. Enter the company name exactly as it is registered for that number.', 'vatright' );
				}
				return __( 'The company name does not match the name registered for this VAT number. Enter the company name exactly as it is registered for that number, or leave the VAT number empty to pay with VAT.', 'vatright' );
			case 'self_certification_required':
				return __( 'Your billing country and your location do not match. Confirm the country you are located in to continue.', 'vatright' );
			default:
				return __( 'Your VAT details could not be accepted.', 'vatright' );
		}
	}
}
