<?php

namespace Vatright\Orders;

use Vatright\Domain\Rule;
use Vatright\Domain\ValidationResult;
use Vatright\Migration\LegacyData;
use WP_Post;
use WC_Order;

/**
 * Everything is escaped on output: the registry's name and address, the
 * customer's VAT number and the free-text company name are third-party data.
 */
class MetaBox {

	const ACTION = 'vatright_recheck';

	/** @var Revalidation */
	private $revalidation;

	public function __construct( Revalidation $revalidation ) {
		$this->revalidation = $revalidation;
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_recheck' ) );
	}

	public function add(): void {
		add_meta_box( 'vatright-decision', __( 'EU VAT', 'vatright' ), array( $this, 'render' ), wc_get_page_screen_id( 'shop-order' ), 'normal', 'default' );
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order The post under CPT storage, the order under HPOS.
	 */
	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$decision = DecisionStore::get( $order );
		if ( null === $decision ) {
			echo '<p>' . esc_html__( 'No VAT decision is recorded for this order.', 'vatright' ) . '</p>';
			return;
		}
		$record = $decision->to_array();

		$this->render_summary( $order, $record );
		$this->render_flags( $record['flags'] );
		$this->render_inputs( $order, $record );
		$this->render_log( DecisionStore::log( $order ) );

		if ( '' !== (string) ( $record['vat_number']['canonical'] ?? '' ) ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => self::ACTION,
						'order_id' => $order->get_id(),
					),
					admin_url( 'admin-post.php' )
				),
				self::ACTION . $order->get_id()
			);
			echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Re-check VAT number now', 'vatright' ) . '</a></p>';
		}
	}

	public function handle_recheck(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( self::ACTION . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'You are not allowed to do this.', 'vatright' ), '', array( 'response' => 403 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'vatright' ), '', array( 'response' => 404 ) );
		}
		$this->revalidation->recheck( $order, 'admin', get_current_user_id() );

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * @param array<string,mixed> $record
	 */
	private function render_summary( WC_Order $order, array $record ): void {
		echo '<p><strong>' . esc_html( self::headline( $record ) ) . '</strong></p>';

		$recorded = (string) ( $record['vat_number']['canonical'] ?? '' );
		$current  = LegacyData::order_number( $order );
		$billing  = strtoupper( $order->get_billing_country() );
		if ( $recorded !== $current || ( '' !== $billing && $billing !== (string) $record['customer']['billing']['country'] ) ) {
			echo '<p class="notice notice-warning inline"><span>' . esc_html__( 'The VAT number or billing country changed since this decision was recorded. Recalculate taxes to update it.', 'vatright' ) . '</span></p>';
		}
	}

	/**
	 * @param string[] $flags
	 */
	private function render_flags( array $flags ): void {
		if ( array() === $flags ) {
			return;
		}
		echo '<ul>';
		foreach ( $flags as $flag ) {
			$class = in_array( $flag, array( 'invalid_after_exemption', 'validated_after_outage' ), true ) ? 'notice notice-warning inline' : '';
			echo '<li class="' . esc_attr( $class ) . '"><span>' . esc_html( self::flag_text( $flag ) ) . '</span></li>';
		}
		echo '</ul>';
	}

	/**
	 * @param array<string,mixed> $record
	 */
	private function render_inputs( WC_Order $order, array $record ): void {
		$validation = (array) ( $record['validation'] ?? array() );
		$evidence   = (array) $record['evidence'];
		$customer   = (array) $record['customer'];

		$rows = array(
			__( 'Billing country', 'vatright' )        => trim( $customer['billing']['country'] . ' ' . $customer['billing']['postcode'] ),
			__( 'Shipping country', 'vatright' )       => trim( $customer['shipping']['country'] . ' ' . $customer['shipping']['postcode'] ),
			__( 'Local pickup', 'vatright' )           => $customer['local_pickup'] ? __( 'Yes', 'vatright' ) : __( 'No', 'vatright' ),
			__( 'Exempt customer roles', 'vatright' )  => implode( ', ', (array) ( $customer['exempt_roles'] ?? array() ) ),
			__( 'Billing company', 'vatright' )        => $order->get_billing_company(),
			__( 'VAT number as typed', 'vatright' )    => (string) ( $record['vat_number']['raw'] ?? '' ),
			__( 'VAT number', 'vatright' )             => (string) ( $record['vat_number']['canonical'] ?? '' ),
			__( 'Store VAT number', 'vatright' )       => InvoiceText::store_vat_number( $order ),
			__( 'Registry answer', 'vatright' )        => (string) ( $validation['status'] ?? '' ),
			__( 'Registry', 'vatright' )               => (string) ( $validation['registry'] ?? '' ),
			__( 'Registered name', 'vatright' )        => (string) ( $validation['name'] ?? '' ),
			__( 'Registered address', 'vatright' )     => (string) ( $validation['address'] ?? '' ),
			__( 'Consultation reference', 'vatright' ) => (string) ( $validation['consultation'] ?? '' ),
			__( 'Checked at', 'vatright' )             => (string) ( $validation['checked_at'] ?? '' ),
			__( 'Location evidence', 'vatright' )      => implode(
				', ',
				array_filter(
					array(
						'' !== (string) ( $evidence['billing'] ?? '' ) ? 'billing ' . $evidence['billing'] : '',
						'' !== (string) ( $evidence['shipping'] ?? '' ) ? 'shipping ' . $evidence['shipping'] : '',
						'' !== (string) ( $evidence['ip'] ?? '' ) ? 'IP ' . $evidence['ip'] : '',
						'' !== (string) ( $evidence['self_certified'] ?? '' ) ? 'self-certified ' . $evidence['self_certified'] : '',
					)
				)
			),
			__( 'Decided at', 'vatright' )             => (string) $record['at'],
		);

		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			if ( '' !== trim( (string) $value ) ) {
				echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( (string) $value ) ) . '</td></tr>';
			}
		}
		foreach ( (array) $record['lines'] as $line ) {
			echo '<tr><th scope="row">' . esc_html__( 'Line', 'vatright' ) . '</th><td>' . esc_html(
				sprintf(
					/* translators: 1: supply type, 2: place of supply country, 3: country whose VAT rate applies, 4: scheme */
					__( '%1$s, supplied in %2$s, VAT of %3$s, %4$s', 'vatright' ),
					$line['supply_type'],
					$line['place_of_supply'],
					$line['tax_country'],
					$line['scheme']
				)
			) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * @param array<int,array<string,mixed>> $log
	 */
	private function render_log( array $log ): void {
		if ( array() === $log ) {
			return;
		}
		echo '<h4>' . esc_html__( 'Decision log', 'vatright' ) . '</h4>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'When (UTC)', 'vatright' ), __( 'Trigger', 'vatright' ), __( 'User', 'vatright' ), __( 'Rule', 'vatright' ), __( 'Flags', 'vatright' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $log as $entry ) {
			$user = ! empty( $entry['actor_user_id'] ) ? get_userdata( (int) $entry['actor_user_id'] ) : false;
			echo '<tr><td>' . esc_html( (string) ( $entry['at'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $entry['trigger'] ?? '' ) ) . '</td><td>' . esc_html( $user ? $user->display_name : '' ) . '</td><td>' . esc_html( self::rule_text( (string) ( $entry['rule'] ?? '' ) ) ) . '</td><td>' . esc_html( implode( ', ', (array) ( $entry['flags'] ?? array() ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function rule_text( string $rule ): string {
		switch ( $rule ) {
			case Rule::EXPORT_NON_EU:
				return __( 'Customer outside the EU: no EU VAT.', 'vatright' );
			case Rule::OUTSIDE_TERRITORY:
				return __( 'Delivery to a territory outside the EU VAT area: no EU VAT.', 'vatright' );
			case Rule::DOMESTIC:
				return __( 'Domestic sale: VAT of the store country.', 'vatright' );
			case Rule::DOMESTIC_B2B_GUARD:
				return __( 'Domestic business customer: VAT charged, a number from the store country never exempts.', 'vatright' );
			case Rule::DOMESTIC_B2B_ALLOWED:
				return __( 'Domestic business customer with a valid VAT number: exempt, as allowed in the settings.', 'vatright' );
			case Rule::RC_GOODS_ART138:
				return __( 'Goods to a business in another EU country with a valid VAT number: exempt intra-Community supply (Article 138).', 'vatright' );
			case Rule::RC_SERVICES_ART196:
				return __( 'Services to a business in another EU country with a valid VAT number: reverse charge (Article 196).', 'vatright' );
			case Rule::B2C_ORIGIN_BELOW_THRESHOLD:
				return __( 'Consumer in another EU country, below the distance-selling threshold: VAT of the store country.', 'vatright' );
			case Rule::B2C_DESTINATION:
				return __( 'Consumer in another EU country: VAT of the destination country.', 'vatright' );
			case Rule::B2C_SERVICE_ORIGIN:
				return __( 'Service to a consumer: VAT of the store country.', 'vatright' );
			case Rule::PREFIX_MISMATCH_CHARGED:
				return __( 'The VAT number belongs to a different country than the billing address: VAT charged.', 'vatright' );
			case Rule::LOCAL_PICKUP_DOMESTIC:
				return __( 'Collected in store: VAT of the store country.', 'vatright' );
			case Rule::PENDING_CHARGED:
				return __( 'The VAT number could not be checked: VAT charged until it is.', 'vatright' );
			case Rule::PENDING_EXEMPT:
				return __( 'The VAT number could not be checked: exempt until it is.', 'vatright' );
			case Rule::PENDING_REJECTED:
				return __( 'The VAT number could not be checked: the order was refused.', 'vatright' );
			case Rule::INVALID_NUMBER:
				return __( 'The VAT number is not valid: VAT charged.', 'vatright' );
			case Rule::ROLE_EXEMPT:
				return __( 'VAT-exempt because of the customer role, as set in the VAT Right settings. The legal basis is the merchant\'s responsibility.', 'vatright' );
			case Rule::NON_EU_NUMBER_RECORDED:
				return __( 'Non-EU VAT number recorded: no EU VAT.', 'vatright' );
			default:
				return $rule;
		}
	}

	/**
	 * A re-check keeps the pending rule, because the tax charged doesn't
	 * change, but records the registry's answer, which the headline reports.
	 *
	 * @param array<string,mixed> $record
	 */
	public static function headline( array $record ): string {
		$rule   = (string) $record['rule'];
		$status = (string) ( $record['validation']['status'] ?? '' );
		if ( ! in_array( $rule, array( Rule::PENDING_CHARGED, Rule::PENDING_EXEMPT ), true ) ) {
			return self::rule_text( $rule );
		}
		$charged = Rule::PENDING_CHARGED === $rule;
		if ( ValidationResult::VALID === $status ) {
			return $charged
				? __( 'The VAT number was checked later and is valid. VAT was charged at checkout.', 'vatright' )
				: __( 'The VAT number was checked later and is valid: exempt.', 'vatright' );
		}
		if ( ValidationResult::INVALID === $status || ValidationResult::FORMAT_INVALID === $status ) {
			return $charged
				? __( 'The VAT number was checked later and is not valid: VAT charged.', 'vatright' )
				: __( 'The VAT number was checked later and is not valid, but no VAT was charged.', 'vatright' );
		}
		return self::rule_text( $rule );
	}

	public static function flag_text( string $flag ): string {
		switch ( $flag ) {
			case 'validation_pending':
				return __( 'Validation pending: the VAT number is checked again in the background.', 'vatright' );
			case 'validated_after_outage':
				return __( 'The number was valid when checked later, but VAT was charged. The customer may be due a VAT refund.', 'vatright' );
			case 'invalid_after_exemption':
				return __( 'VAT was not charged, but the number turned out to be invalid. VAT may be due.', 'vatright' );
			case 'evidence_conflict_self_certified':
				return __( 'The location evidence conflicted and the customer self-certified their country.', 'vatright' );
			case 'single_evidence':
				return __( 'Only one piece of location evidence was available.', 'vatright' );
			case 'name_mismatch':
				return __( 'The company name does not match the registry.', 'vatright' );
			case 'prefix_mismatch':
				return __( 'The VAT number prefix does not match the billing country.', 'vatright' );
			case 'backfilled':
				return __( 'Decided after the fact for an order placed before the plugin was active.', 'vatright' );
			case 'rate_missing':
				return __( 'No tax rate was set for the destination, the store rate was charged.', 'vatright' );
			default:
				return $flag;
		}
	}
}
