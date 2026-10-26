<?php

namespace Vatright\Orders;

use Vatright\Domain\ValidationResult;
use Vatright\Settings;
use WC_Order;

/**
 * Adds the customer's VAT number and the legal wording of the recorded
 * decision to the order totals, which Woo's order pages, emails (HTML and
 * plain text) and most invoice plugins render.
 */
class InvoiceText {

	public function register(): void {
		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'add_rows' ), 10, 2 );
	}

	/**
	 * @param array<string,array{label:string,value:string}> $rows
	 * @param mixed                                          $order
	 * @return array<string,array{label:string,value:string}>
	 */
	public function add_rows( $rows, $order ) {
		if ( $order instanceof WC_Order ) {
			$store = self::store_vat_number( $order );
			if ( '' !== $store ) {
				$rows['vatright_store_number'] = array(
					'label' => __( 'Our VAT number', 'vatright' ),
					'value' => esc_html( $store ),
				);
			}
		}

		$decision = $order instanceof WC_Order ? DecisionStore::get( $order ) : null;
		if ( null === $decision || ( ! $decision->is_vat_exempt() && array() === $decision->legal_text() ) ) {
			return $rows;
		}
		$record = $decision->to_array();

		$number = (string) ( $record['vat_number']['canonical'] ?? '' );
		if ( '' !== $number ) {
			$rows['vatright_number'] = array(
				'label' => __( 'Customer VAT number', 'vatright' ),
				'value' => esc_html( $number ),
			);
		}

		foreach ( $decision->legal_text() as $key ) {
			$text = self::legal_text( $key, $order );
			if ( '' !== $text ) {
				$rows[ 'vatright_legal_' . $key ] = array(
					'label' => __( 'VAT note', 'vatright' ),
					'value' => esc_html( $text ),
				);
			}
		}

		$consultation = (string) ( $record['validation']['consultation'] ?? '' );
		if ( '' !== $consultation && ValidationResult::VALID === ( $record['validation']['status'] ?? '' ) ) {
			$rows['vatright_consultation'] = array(
				'label' => __( 'VAT number consultation reference', 'vatright' ),
				'value' => esc_html( $consultation ),
			);
		}
		return $rows;
	}

	/**
	 * The store's own VAT number for this order: the UK number for a UK
	 * customer, the EU one otherwise. Empty unless the store has both, since
	 * a store with one number prints it nowhere today.
	 */
	public static function store_vat_number( WC_Order $order ): string {
		$settings = Settings::get();
		return $settings->has_separate_store_numbers() ? $settings->store_vat_number_for( self::customer_country( $order ) ) : '';
	}

	/**
	 * The country the sale was decided for, or the billing country when no
	 * decision was kept.
	 */
	public static function customer_country( WC_Order $order ): string {
		$decision = DecisionStore::get( $order );
		if ( null !== $decision ) {
			$record   = $decision->to_array();
			$resolved = $record['evidence']['resolved'] ?? null;
			$billing  = (string) ( $record['customer']['billing']['country'] ?? '' );
			return is_string( $resolved ) && '' !== $resolved ? $resolved : $billing;
		}
		return $order->get_billing_country();
	}

	public static function legal_text( string $key, ?WC_Order $order = null ): string {
		switch ( $key ) {
			case 'art196':
				$text = __( 'Reverse charge: VAT to be accounted for by the recipient, Article 196 Council Directive 2006/112/EC', 'vatright' );
				break;
			case 'art138':
				$text = __( 'Exempt intra-Community supply of goods, Article 138 Council Directive 2006/112/EC', 'vatright' );
				break;
			default:
				$text = '';
		}
		return (string) apply_filters( 'vatright_legal_text', $text, $key, $order );
	}
}
