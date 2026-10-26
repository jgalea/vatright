<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- a WP-CLI script, its variables are not globals of the plugin.

use Vatright\Checkout\InputGatherer;
use Vatright\Domain\DecisionInput;
use Vatright\Domain\Evidence;
use Vatright\Domain\Line;
use Vatright\Domain\Rules;
use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use Vatright\Orders\DecisionStore;

/**
 * Orders with a stored decision, as checkout leaves them: a valid B2B reverse
 * charge, a B2B order waiting for the registry, a consumer order under OSS and
 * an order with no decision. Prints their ids keyed by name.
 */
function vatright_e2e_decided_order( $name, $billing, $number, $status, $oss, $pending ) {
	$order = new WC_Order();
	$order->set_billing_first_name( $name );
	$order->set_billing_last_name( 'Tester' );
	$order->set_billing_country( $billing );
	$order->set_billing_postcode( '10115' );
	$order->set_shipping_country( $billing );
	$order->set_shipping_postcode( '10115' );

	$product = new WC_Product_Simple();
	$product->set_regular_price( '100' );
	$product->set_virtual( true );
	$product->save();
	$item = new WC_Order_Item_Product();
	$item->set_product( $product );
	$item->set_quantity( 1 );
	$item->set_subtotal( '100' );
	$item->set_total( '100' );
	$item->add_meta_data( InputGatherer::LINE_KEY_META, 'line-digital', true );
	$order->add_item( $item );
	$order->set_total( '100' );
	$order->set_status( 'processing' );
	$order->save();

	$vat      = '' === $number ? null : VatNumber::parse( $number, $billing );
	$decision = ( new Rules() )->decide(
		new DecisionInput(
			'MT',
			$oss,
			false,
			array(
				'current_eur'  => '0.00',
				'previous_eur' => '0.00',
				'exceeded'     => false,
				'forced'       => $oss,
			),
			'charge',
			false,
			false,
			$billing,
			'10115',
			$billing,
			'10115',
			false,
			$vat,
			'' === $number ? null : $number,
			null === $vat ? null : new ValidationResult( $status, 'vies', gmdate( 'Y-m-d\TH:i:s\Z' ), 'Example GmbH', 'Street 1', 'WAPIAAAA' ),
			new Evidence( $billing, null, null, null ),
			array( new Line( 'line-digital', 'digital', false ) ),
			'checkout',
			gmdate( 'Y-m-d\TH:i:s\Z' )
		)
	);
	DecisionStore::apply( $order, $pending ? $decision->with_flag( 'validation_pending' ) : $decision, 'checkout' );
	$order->save();
	return $order->get_id();
}

$ids = array(
	'valid'   => vatright_e2e_decided_order( 'Valid', 'DE', 'DE114103379', 'valid', false, false ),
	'pending' => vatright_e2e_decided_order( 'Pending', 'DE', 'DE129273398', 'unavailable', false, true ),
	'oss'     => vatright_e2e_decided_order( 'Consumer', 'FR', '', '', true, false ),
);

$bare = new WC_Order();
$bare->set_billing_first_name( 'Bare' );
$bare->set_status( 'processing' );
$bare->save();
$ids['none'] = $bare->get_id();

echo '@@' . wp_json_encode( $ids ) . '@@';
