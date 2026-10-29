<?php

namespace Vatright\Checkout;

use Vatright\Domain\B2bScope;
use Vatright\Domain\FormatCheck;
use Vatright\Domain\Territory;
use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use Vatright\Migration\LegacyData;
use Vatright\Plugin;
use Vatright\Registry\Validator;
use Vatright\Settings;
use WC_Customer;
use WC_Data;
use WP_Error;

/**
 * The VAT number input on every screen that takes one. WooCommerce renders
 * the block field itself on the block checkout, My Account address forms and
 * the admin order screen; only the classic checkout needs a field of ours.
 * It sits in the contact location, so there is one value per customer and
 * order instead of a billing and a shipping copy to keep in step.
 * Whichever input saved it, the number ends up in _vatright_number on the
 * customer and the order.
 */
class VatField {

	const FIELD_ID = 'vatright/vat-number';

	const CLASSIC_FIELD = 'billing_vatright_number';

	const SELF_CERTIFY_FIELD = 'vatright_self_certify';

	const SELF_CERTIFY_MARKER = 'vatright_self_certify_field';

	/**
	 * Countries where a VAT number means something to us: the EU, Northern
	 * Ireland (GB), Switzerland and Norway.
	 */
	const COUNTRIES = array(
		'AT',
		'BE',
		'BG',
		'HR',
		'CY',
		'CZ',
		'DK',
		'EE',
		'FI',
		'FR',
		'DE',
		'GR',
		'HU',
		'IE',
		'IT',
		'LV',
		'LT',
		'LU',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SK',
		'SI',
		'ES',
		'SE',
		'GB',
		'CH',
		'NO',
	);

	/** @var Validator */
	private $validator;

	public function __construct( Validator $validator ) {
		$this->validator = $validator;
	}

	public function register(): void {
		add_action( 'woocommerce_init', array( $this, 'register_block_field' ) );
		add_action( 'woocommerce_set_additional_field_value', array( $this, 'mirror_block_value' ), 10, 4 );
		add_filter( 'woocommerce_get_default_value_for_' . self::FIELD_ID, array( $this, 'block_default' ), 10, 3 );

		add_filter( 'woocommerce_billing_fields', array( $this, 'add_classic_field' ) );
		add_filter( 'woocommerce_form_field', array( $this, 'add_classic_disclosure' ), 10, 2 );
		add_filter( 'woocommerce_form_field', array( $this, 'mark_classic_required' ), 11, 2 );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'classic_default' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'on_update_order_review' ) );
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'on_posted_data' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'copy_to_order' ), 5 );
		add_action( 'woocommerce_checkout_update_user_meta', array( $this, 'copy_to_new_account' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'render_classic_notice' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_classic_script' ) );

		add_filter( 'woocommerce_admin_billing_fields', array( $this, 'add_admin_field' ), 20, 2 );
		add_filter( 'woocommerce_customer_meta_fields', array( $this, 'add_profile_field' ) );
	}

	public function register_block_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'                         => self::FIELD_ID,
				'label'                      => __( 'VAT number', 'vatright' ),
				'location'                   => 'contact',
				'type'                       => 'text',
				'required'                   => self::block_required(),
				// BillingAddress adds the number to the billing address, which every order shows.
				'show_in_order_confirmation' => false,
				'hidden'                     => array(
					'type'       => 'object',
					'required'   => array( 'customer' ),
					'properties' => array(
						'customer' => array(
							'required'   => array( 'billing_address' ),
							'properties' => array(
								'billing_address' => array(
									'required'   => array( 'country' ),
									'properties' => array(
										'country' => array(
											'minLength' => 1,
											'not'       => array( 'enum' => self::COUNTRIES ),
										),
									),
								),
							),
						),
					),
				),
				'sanitize_callback'          => array( self::class, 'normalise' ),
				'validate_callback'          => array( $this, 'validate_block_value' ),
			)
		);
	}

	/**
	 * Business-only mode makes the number mandatory in the countries it
	 * covers. WooCommerce evaluates this schema against the checkout, so the
	 * block field gains or loses its "(optional)" label as the billing country
	 * changes. An empty field is then refused by WooCommerce before checkout.
	 *
	 * @return bool|array<string,mixed>
	 */
	public static function block_required() {
		$settings = Settings::get();
		switch ( $settings->b2b_only() ) {
			case B2bScope::EVERYWHERE:
				$countries = self::COUNTRIES;
				break;
			case B2bScope::EU:
				$countries = Territory::EU;
				break;
			case B2bScope::COUNTRIES:
				$countries = array_map( 'strtoupper', $settings->b2b_only_countries() );
				break;
			default:
				return false;
		}
		return array(
			'type'       => 'object',
			'required'   => array( 'customer' ),
			'properties' => array(
				'customer' => array(
					'required'   => array( 'billing_address' ),
					'properties' => array(
						'billing_address' => array(
							'required'   => array( 'country' ),
							'properties' => array(
								'country' => array( 'enum' => array_values( $countries ) ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * The canonical form when the text parses as a VAT number, otherwise the
	 * trimmed upper-case text (a bare number that needs the billing country
	 * to be understood).
	 *
	 * @param mixed $value
	 */
	public static function normalise( $value ): string {
		$raw    = trim( (string) $value );
		$number = VatNumber::parse( $raw, '' );
		return null === $number ? strtoupper( $raw ) : $number->canonical();
	}

	/**
	 * Format and cached-verdict checks only; the registry is asked later, in
	 * the cart update or at checkout.
	 *
	 * @param mixed $value
	 * @return WP_Error|true
	 */
	public function validate_block_value( $value ) {
		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return true;
		}
		$number = VatNumber::parse( $raw, self::billing_country() );
		if ( null === $number || ! FormatCheck::is_plausible( $number ) ) {
			return new WP_Error( 'vatright_format', __( 'This does not look like a VAT number. Check it, or leave the field empty to pay with VAT.', 'vatright' ) );
		}
		if ( ValidationResult::INVALID === $this->validator->validate( $number, false )->status() ) {
			return new WP_Error( 'vatright_invalid', CheckoutDecider::message( 'invalid_vat_number' ) );
		}
		return true;
	}

	/**
	 * A cleared field clears the number.
	 *
	 * @param string  $key
	 * @param mixed   $value
	 * @param string  $group
	 * @param WC_Data $wc_object
	 */
	public function mirror_block_value( $key, $value, $group, $wc_object ): void {
		if ( self::FIELD_ID !== $key ) {
			return;
		}
		$wc_object->update_meta_data( InputGatherer::NUMBER_META, self::normalise( $value ) );
	}

	/**
	 * Fills the block field on the checkout and My Account for a customer
	 * whose number WooCommerce's own copy doesn't hold: one saved at
	 * registration, or left by another VAT plugin.
	 *
	 * @param mixed  $value
	 * @param string $group
	 * @param mixed  $wc_object
	 * @return mixed
	 */
	public function block_default( $value, $group, $wc_object ) {
		if ( null !== $value || ! $wc_object instanceof WC_Customer ) {
			return $value;
		}
		$number = LegacyData::customer_number( $wc_object );
		return '' === $number ? null : $number;
	}

	/**
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	public function add_classic_field( $fields ) {
		if ( ! self::is_classic_checkout() ) {
			return $fields;
		}
		$fields[ self::CLASSIC_FIELD ] = array(
			'type'              => 'text',
			'label'             => __( 'VAT number', 'vatright' ),
			'required'          => false,
			'class'             => array( 'form-row-wide' ),
			'priority'          => 125,
			'autocomplete'      => 'off',
			'custom_attributes' => array( 'aria-describedby' => 'vatright-disclosure' ),
		);
		return $fields;
	}

	/**
	 * Tells the shopper where the number goes before they enter it.
	 */
	public static function disclosure(): string {
		if ( Settings::get()->refuse_name_mismatch() ) {
			return __( 'Your VAT number and company name are sent to the official tax registry for its country, such as the EU\'s VIES service, to confirm them before VAT is removed.', 'vatright' );
		}
		return __( 'Your VAT number is sent to the official tax registry for its country, such as the EU\'s VIES service, to confirm it before VAT is removed.', 'vatright' );
	}

	/**
	 * WooCommerce's own field description stays hidden until the input has
	 * focus and is hidden from screen readers, so the disclosure is ours.
	 *
	 * @param string $html
	 * @param string $key
	 */
	public function add_classic_disclosure( $html, $key ): string {
		$html = (string) $html;
		$end  = strrpos( $html, '</p>' );
		if ( self::CLASSIC_FIELD !== $key || false === $end ) {
			return $html;
		}
		return substr( $html, 0, $end ) . '<span class="vatright-disclosure" id="vatright-disclosure">' . esc_html( self::disclosure() ) . '</span>' . substr( $html, $end );
	}

	/**
	 * Business-only mode refuses an empty field with its own message, so the
	 * field stays optional to WooCommerce and only looks required. The script
	 * keeps the label in step with the billing country.
	 *
	 * @param string $html
	 * @param string $key
	 */
	public function mark_classic_required( $html, $key ): string {
		$html = (string) $html;
		if ( self::CLASSIC_FIELD !== $key || ! WC()->customer instanceof WC_Customer || ! B2bOnly::applies( WC()->customer->get_billing_country() ) ) { // @phpstan-ignore instanceof.alwaysTrue
			return $html;
		}
		$required = esc_attr__( 'required', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- the label wording WooCommerce itself uses.
		$html     = preg_replace( '#<span class="optional">[^<]*</span>#', '<abbr class="required" title="' . $required . '">*</abbr>', $html, 1 ) ?? $html;
		return str_replace( 'form-row ', 'form-row validate-required ', $html );
	}

	/**
	 * @return array{mode:string,countries:string[],eu:string[],optional:string,required:string}
	 */
	private static function classic_script_data(): array {
		$settings = Settings::get();
		return array(
			'mode'      => $settings->b2b_only(),
			'countries' => $settings->b2b_only_countries(),
			'eu'        => Territory::EU,
			'optional'  => esc_html__( 'optional', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- the label wording WooCommerce itself uses.
			'required'  => esc_attr__( 'required', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- the label wording WooCommerce itself uses.
		);
	}

	/**
	 * @param mixed  $value
	 * @param string $input
	 * @return mixed
	 */
	public function classic_default( $value, $input ) {
		if ( self::CLASSIC_FIELD !== $input || ! WC()->customer instanceof WC_Customer ) { // @phpstan-ignore instanceof.alwaysTrue
			return $value;
		}
		return LegacyData::customer_number( WC()->customer );
	}

	/**
	 * Runs before WooCommerce applies the posted address to the customer, so
	 * the billing country is read from the posted data.
	 *
	 * @param string $post_data
	 */
	public function on_update_order_review( $post_data ): void {
		parse_str( (string) $post_data, $posted );
		$this->apply_posted( (array) wc_clean( wp_unslash( $posted ) ) );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function on_posted_data( $data ) {
		// WooCommerce fills a registered field that was not posted with an empty string; only a field that was posted may clear the number.
		$this->apply_posted( (array) wc_clean( wp_unslash( $_POST ) ) + array( 'billing_country' => (string) ( $data['billing_country'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
		return $data;
	}

	/**
	 * @param \WC_Order $order
	 */
	public function copy_to_order( $order ): void {
		$number = WC()->customer instanceof WC_Customer ? LegacyData::customer_number( WC()->customer ) : ''; // @phpstan-ignore instanceof.alwaysTrue
		if ( '' !== $number ) {
			$order->update_meta_data( InputGatherer::NUMBER_META, $number );
		}
	}

	/**
	 * An account created at checkout is a new customer record, so the number
	 * the visitor typed, held on the session customer, has to be copied over.
	 *
	 * @param mixed $user_id
	 */
	public function copy_to_new_account( $user_id ): void {
		$number = WC()->customer instanceof WC_Customer ? (string) WC()->customer->get_meta( InputGatherer::NUMBER_META ) : ''; // @phpstan-ignore instanceof.alwaysTrue
		if ( '' !== $number && '' === (string) get_user_meta( (int) $user_id, InputGatherer::NUMBER_META, true ) ) {
			update_user_meta( (int) $user_id, InputGatherer::NUMBER_META, $number );
		}
	}

	public function render_classic_notice(): void {
		$decision = Plugin::cart_steering() ? Plugin::cart_steering()->current() : null;
		$status   = StoreApiExtension::status( $decision );
		if ( '' === $status['message'] && ! $status['needs_self_certification'] && ! $status['self_certified'] ) {
			return;
		}
		echo '<tr class="vatright-notice"><td colspan="2">';
		if ( '' !== $status['message'] ) {
			echo '<p class="vatright-notice__message vatright-notice__message--' . esc_attr( $status['status'] ) . '" role="status">' . esc_html( $status['message'] ) . '</p>';
		}
		if ( $status['needs_self_certification'] || $status['self_certified'] ) {
			echo '<input type="hidden" name="' . esc_attr( self::SELF_CERTIFY_MARKER ) . '" value="1" />';
			echo '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
			echo '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="' . esc_attr( self::SELF_CERTIFY_FIELD ) . '" value="1"' . checked( $status['self_certified'], true, false ) . ' /> ';
			echo '<span>' . esc_html( $status['self_certification_label'] ) . '</span></label>';
		}
		echo '</td></tr>';
	}

	public function enqueue_classic_script(): void {
		if ( ! self::is_classic_checkout() ) {
			return;
		}
		$script = self::asset( 'assets/classic-checkout.js' );
		wp_enqueue_script( 'vatright-classic-checkout', $script['url'], array( 'jquery', 'wc-checkout' ), $script['version'], true );
		wp_localize_script( 'vatright-classic-checkout', 'vatrightB2b', self::classic_script_data() );
		$style = self::asset( 'assets/checkout-block/style.css' );
		wp_enqueue_style( 'vatright-checkout', $style['url'], array(), $style['version'] );
	}

	/**
	 * WooCommerce lists its own copy of the block field here, but only for
	 * orders placed through the Store API that have a value, so an admin could
	 * not add a number to any other order. One input of ours replaces it; the
	 * saved value is read from _billing_vatright_number by AdminRecalc.
	 *
	 * @param array<string,mixed> $fields
	 * @param mixed               $order
	 * @return array<string,mixed>
	 */
	public function add_admin_field( $fields, $order = null ) {
		unset( $fields[ self::FIELD_ID ], $fields[ '_wc_other/' . self::FIELD_ID ] );
		$fields['vatright_number'] = array(
			'label'         => __( 'VAT number', 'vatright' ),
			'wrapper_class' => 'form-field-wide',
			'show'          => true,
			'value'         => $order instanceof \WC_Order ? LegacyData::order_number( $order ) : '',
		);
		return $fields;
	}

	/**
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	public function add_profile_field( $fields ) {
		if ( isset( $fields['billing']['fields'] ) ) {
			$fields['billing']['fields'][ InputGatherer::NUMBER_META ] = array(
				'label'       => __( 'VAT number', 'vatright' ),
				'description' => '',
			);
		}
		return $fields;
	}

	/**
	 * Registry lookup for a number entered by the visitor. The cart update
	 * and the classic order review call this, so the next totals calculation
	 * finds the verdict in the cache.
	 */
	public function set_number( WC_Customer $customer, string $raw, string $billing_country, string $company = '' ): void {
		$customer->update_meta_data( InputGatherer::NUMBER_META, self::normalise( $raw ) );
		$number = VatNumber::parse( $raw, $billing_country );
		if ( null !== $number ) {
			$this->validator->validate_for_visitor( $number, 'update', NameMismatch::lookup_name( $company ) );
		}
	}

	/**
	 * @param array<string,mixed> $posted
	 */
	private function apply_posted( array $posted ): void {
		$customer = WC()->customer;
		if ( ! $customer instanceof WC_Customer ) { // @phpstan-ignore instanceof.alwaysTrue
			return;
		}
		$billing = strtoupper( (string) ( $posted['billing_country'] ?? $posted['country'] ?? $customer->get_billing_country() ) );

		if ( array_key_exists( self::CLASSIC_FIELD, $posted ) ) {
			$this->set_number( $customer, (string) $posted[ self::CLASSIC_FIELD ], $billing, (string) ( $posted['billing_company'] ?? $customer->get_billing_company() ) );
			if ( $customer->get_id() ) {
				$customer->update_meta_data( '_wc_other/' . self::FIELD_ID, self::normalise( $posted[ self::CLASSIC_FIELD ] ) );
			}
		}
		if ( array_key_exists( self::SELF_CERTIFY_MARKER, $posted ) && null !== CartSteering::session() ) {
			CartSteering::session()->set( InputGatherer::SELF_CERTIFIED_SESSION, isset( $posted[ self::SELF_CERTIFY_FIELD ] ) ? $billing : null );
		}
	}

	/**
	 * @return array{url: string, version: string}
	 */
	public static function asset( string $path ): array {
		$main = dirname( __DIR__, 2 ) . '/vatright.php';
		return array(
			'url'     => plugins_url( $path, $main ),
			'version' => (string) filemtime( dirname( __DIR__, 2 ) . '/' . $path ),
		);
	}

	private static function billing_country(): string {
		return WC()->customer instanceof WC_Customer ? strtoupper( WC()->customer->get_billing_country() ) : ''; // @phpstan-ignore instanceof.alwaysTrue
	}

	/**
	 * The classic input belongs on a classic checkout page only: not on a
	 * block checkout page, in REST requests, or on My Account, where
	 * WooCommerce adds the block field to the address form itself.
	 */
	private static function is_classic_checkout(): bool {
		if ( defined( 'REST_REQUEST' ) || ( ! did_action( 'wp' ) && ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) || ! is_checkout() ) {
			return false;
		}
		$page = get_queried_object_id();
		return ! ( $page > 0 && has_block( 'woocommerce/checkout', $page ) );
	}
}
