<?php

namespace Vatright\Checkout;

use Vatright\Domain\Decision;
use Vatright\Domain\Rule;
use Vatright\Domain\Scheme;
use Vatright\Domain\ValidationResult;
use Vatright\Plugin;
use Vatright\Settings;
use WC_Customer;

/**
 * What the checkout block shows about the VAT decision, and the one update
 * the block can send: the VAT number as typed (which triggers the registry
 * lookup) and the self-certification checkbox.
 *
 * @phpstan-type Status array{status: string, rule: string, message: string, needs_self_certification: bool, self_certification_required: bool, self_certified: bool, self_certification_label: string}
 */
class StoreApiExtension {

	const NAMESPACE = 'vatright';

	/** @var VatField */
	private $field;

	public function __construct( VatField $field ) {
		$this->field = $field;
	}

	public function register(): void {
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_endpoint();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_endpoint' ) );
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_block_script' ) );
	}

	public function register_endpoint(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => 'cart',
				'namespace'       => self::NAMESPACE,
				'data_callback'   => array( $this, 'data' ),
				'schema_callback' => array( $this, 'schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::NAMESPACE,
				'callback'  => array( $this, 'update' ),
			)
		);
	}

	/**
	 * @return Status
	 */
	public function data(): array {
		$steering = Plugin::cart_steering();
		return self::status( null === $steering ? null : $steering->current() );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function schema(): array {
		$readonly = array(
			'readonly' => true,
			'context'  => array( 'view', 'edit' ),
		);
		return array(
			'status'                      => $readonly + array( 'type' => 'string' ),
			'rule'                        => $readonly + array( 'type' => 'string' ),
			'message'                     => $readonly + array( 'type' => 'string' ),
			'needs_self_certification'    => $readonly + array( 'type' => 'boolean' ),
			'self_certification_required' => $readonly + array( 'type' => 'boolean' ),
			'self_certified'              => $readonly + array( 'type' => 'boolean' ),
			'self_certification_label'    => $readonly + array( 'type' => 'string' ),
		);
	}

	/**
	 * The block sends the country and postcode of the address form the
	 * shopper is filling in, which stands in for billing while "use same
	 * address for billing" is on and may not have been pushed to the cart yet.
	 * Store API recalculates the cart after this returns.
	 *
	 * @param array<string,mixed> $data
	 */
	public function update( $data ): void {
		$customer = WC()->customer;
		if ( ! $customer instanceof WC_Customer ) { // @phpstan-ignore instanceof.alwaysTrue
			return;
		}
		if ( isset( $data['country'] ) ) {
			$customer->set_billing_country( sanitize_text_field( (string) $data['country'] ) );
			$customer->set_billing_postcode( sanitize_text_field( (string) ( $data['postcode'] ?? '' ) ) );
		}
		$billing = strtoupper( $customer->get_billing_country() );

		if ( array_key_exists( 'vat_number', $data ) ) {
			$this->field->set_number( $customer, sanitize_text_field( (string) $data['vat_number'] ), $billing, $customer->get_billing_company() );
		}
		if ( array_key_exists( 'self_certify', $data ) && null !== CartSteering::session() ) {
			CartSteering::session()->set( InputGatherer::SELF_CERTIFIED_SESSION, filter_var( $data['self_certify'], FILTER_VALIDATE_BOOLEAN ) ? $billing : null );
		}
	}

	public function enqueue_block_script(): void {
		$dir  = dirname( __DIR__, 2 ) . '/build/checkout-block.asset.php';
		$page = get_queried_object_id();
		if ( ! is_checkout() || $page <= 0 || ! has_block( 'woocommerce/checkout', $page ) || ! file_exists( $dir ) ) {
			return;
		}
		$meta   = require $dir;
		$script = VatField::asset( 'build/checkout-block.js' );
		wp_enqueue_script( 'vatright-checkout-block', $script['url'], array_merge( $meta['dependencies'], array( 'wc-blocks-checkout' ) ), $script['version'], true );
		wp_set_script_translations( 'vatright-checkout-block', 'vatright', dirname( __DIR__, 2 ) . '/languages' );
		wp_localize_script( 'vatright-checkout-block', 'vatrightCheckout', array( 'disclosure' => VatField::disclosure() ) );
		$style = VatField::asset( 'assets/checkout-block/style.css' );
		wp_enqueue_style( 'vatright-checkout-block', $style['url'], array(), $style['version'] );
	}

	/**
	 * @return Status
	 */
	public static function status( ?Decision $decision ): array {
		$none = array(
			'status'                      => 'none',
			'rule'                        => '',
			'message'                     => '',
			'needs_self_certification'    => false,
			'self_certification_required' => false,
			'self_certified'              => false,
			'self_certification_label'    => '',
		);
		if ( null === $decision ) {
			return $none;
		}

		$record    = $decision->to_array();
		$evidence  = (array) $record['evidence'];
		$certified = ! empty( $evidence['self_certified'] );
		$billing   = (string) ( $record['customer']['billing']['country'] ?? '' );
		$none      = array_merge(
			$none,
			array(
				'needs_self_certification'    => Settings::get()->self_certification() && ! empty( $evidence['conflict'] ) && ! $certified && Rule::ROLE_EXEMPT !== $decision->rule(),
				'self_certification_required' => CheckoutDecider::requires_confirmation( $decision ),
				'self_certified'              => $certified,
				'self_certification_label'    => self::self_certification_label( $billing ),
			)
		);

		if ( null === $record['vat_number'] ) {
			if ( $none['self_certification_required'] ) {
				$none['status']  = 'invalid';
				$none['message'] = CheckoutDecider::message( 'self_certification_required' );
			}
			return $none;
		}
		list( $status, $message ) = self::describe( $decision, (string) ( $record['validation']['status'] ?? '' ), (string) ( $record['validation']['detail'] ?? '' ) );
		return array_merge(
			$none,
			array(
				'status'  => $status,
				'rule'    => $decision->rule(),
				'message' => $message,
			)
		);
	}

	public static function self_certification_label( string $country ): string {
		$names = WC()->countries->get_countries();
		/* translators: %s: country name */
		return sprintf( __( 'I confirm I am located in %s.', 'vatright' ), $names[ $country ] ?? $country );
	}

	/**
	 * @return array{0: string, 1: string} Status key, shopper-facing message.
	 */
	private static function describe( Decision $decision, string $validation, string $detail ): array {
		$code = $decision->blocking_error();
		if ( null !== $code ) {
			return array( 'invalid_vat_number' === $code ? 'invalid' : 'pending', CheckoutDecider::message( $code ) );
		}
		if ( NameMismatch::refuses( $decision ) ) {
			return array( 'invalid', CheckoutDecider::message( NameMismatch::CODE ) );
		}
		if ( Rule::ROLE_EXEMPT === $decision->rule() ) {
			return array( 'verified', __( 'No VAT is charged on this order.', 'vatright' ) );
		}
		if ( $decision->is_vat_exempt() ) {
			if ( ValidationResult::VALID === $validation ) {
				return array( 'verified', __( 'VAT number verified. Reverse charge applies.', 'vatright' ) );
			}
			return array( 'pending', __( 'Your VAT number could not be checked just now. No VAT is charged for now and the number will be checked again.', 'vatright' ) );
		}
		if ( Rule::DOMESTIC_B2B_GUARD === $decision->rule() ) {
			return array( 'domestic', __( 'VAT is charged on sales to businesses in the shop\'s own country.', 'vatright' ) );
		}
		if ( Rule::PREFIX_MISMATCH_CHARGED === $decision->rule() ) {
			return array( 'mismatch', __( 'This VAT number does not match your billing country, so VAT is charged.', 'vatright' ) );
		}
		if ( ValidationResult::VALID === $validation ) {
			foreach ( $decision->lines() as $line ) {
				if ( in_array( $line->scheme(), array( Scheme::REVERSE_CHARGE_SERVICES, Scheme::INTRA_EU_GOODS ), true ) ) {
					return array( 'verified', __( 'VAT number verified. Reverse charge applies to part of this order.', 'vatright' ) );
				}
			}
			return array( 'verified', __( 'VAT number verified. VAT is charged on this order.', 'vatright' ) );
		}
		if ( in_array( $validation, array( ValidationResult::INVALID, ValidationResult::FORMAT_INVALID ), true ) ) {
			return array( 'invalid', CheckoutDecider::message( 'invalid_vat_number' ) );
		}
		if ( ValidationResult::UNAVAILABLE === $validation ) {
			return array( 'pending', __( 'Your VAT number could not be checked just now, so VAT is charged on this order.', 'vatright' ) );
		}
		if ( 'cache_miss' === $detail ) {
			return array( 'pending', __( 'Your VAT number has not been checked yet. VAT is charged until it is.', 'vatright' ) );
		}
		return array( 'unchecked', __( 'This VAT number cannot be checked automatically, so VAT is charged.', 'vatright' ) );
	}
}
