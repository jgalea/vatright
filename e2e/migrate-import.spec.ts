import { test, expect, type Page } from '@playwright/test';
import { NUMBERS, PRODUCTS, resetState, wp, wpEval } from './helpers';

// An order as WooCommerce EU VAT Number 3.2.2 saves it at checkout (set_order_data()), with the plugin itself gone.
function seedEuVatNumberOrder(): number {
	return wpEval( `
		$order = wc_create_order();
		$order->add_product( wc_get_product( ${ PRODUCTS.ebook } ), 1 );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_company( 'Analytical Engines GmbH' );
		$order->set_billing_address_1( 'Unter den Linden 1' );
		$order->set_billing_city( 'Berlin' );
		$order->set_billing_postcode( '10117' );
		$order->set_billing_country( 'DE' );
		$order->update_meta_data( '_billing_vat_number', '${ NUMBERS.validDe }' );
		$order->update_meta_data( '_vat_number_is_validated', 'true' );
		$order->update_meta_data( '_vat_number_is_valid', 'true' );
		$order->update_meta_data( '_customer_ip_country', 'DE' );
		$order->update_meta_data( 'is_vat_exempt', 'yes' );
		$order->calculate_totals( false );
		$order->set_status( 'completed' );
		$order->save();
		echo '@@' . $order->get_id() . '@@';
	` );
}

function meta( id: number ) {
	return wpEval( `
		$order = wc_get_order( ${ id } );
		echo '@@' . wp_json_encode( array(
			'own'       => $order->get_meta( '_vatright_number' ),
			'imported'  => json_decode( (string) $order->get_meta( '_vatright_imported' ), true ),
			'theirs'    => $order->get_meta( '_billing_vat_number' ),
			'validated' => $order->get_meta( '_vat_number_is_validated' ),
			'valid'     => $order->get_meta( '_vat_number_is_valid' ),
			'address'   => $order->get_formatted_billing_address(),
		) ) . '@@';
	` );
}

function ordersToImport( output: string ): number {
	const match = output.match( /Orders: \d+ with a VAT number, (\d+) to import\./ );
	expect( match, output ).not.toBeNull();
	return Number( match![ 1 ] );
}

async function logIn( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}

test.beforeEach( async ( { page } ) => {
	resetState();
	wp( 'option', 'delete', 'vatright_import_state' );
	await logIn( page );
} );

test( 'the importer carries an EU VAT Number order over without touching its data', async ( { page } ) => {
	const id = seedEuVatNumberOrder();

	const dry = wp( 'vatright', 'import', '--dry-run', '--source=eu-vat-number' );
	expect( dry ).toContain( 'WooCommerce EU VAT Number (inactive)' );
	expect( ordersToImport( dry ) ).toBeGreaterThanOrEqual( 1 );
	expect( meta( id ).own ).toBe( '' );

	expect( wp( 'vatright', 'import', '--source=eu-vat-number' ) ).toContain( 'Success: Imported' );

	const after = meta( id );
	expect( after.own ).toBe( NUMBERS.validDe );
	expect( after.imported ).toMatchObject( { source: 'eu-vat-number', status: 'valid' } );
	expect( after.theirs ).toBe( NUMBERS.validDe );
	expect( after.validated ).toBe( 'true' );
	expect( after.valid ).toBe( 'true' );
	expect( after.address ).toContain( `VAT number: ${ NUMBERS.validDe }` );
	expect( ordersToImport( wp( 'vatright', 'import', '--dry-run', '--source=eu-vat-number' ) ) ).toBe( 0 );

	await page.goto( `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }` );
	const billing = page.locator( '.order_data_column' ).nth( 1 ).locator( 'div.address' );
	await expect( page.locator( '.order_data_column' ).nth( 1 ).locator( 'h3' ) ).toContainText( 'Billing' );
	await expect( billing ).toContainText( `VAT number: ${ NUMBERS.validDe }` );
	await expect( billing ).toBeVisible();
	await expect( page.locator( '#order_data input[id*="vatright"]' ) ).toHaveValue( NUMBERS.validDe );
} );

test( 'the settings screen counts the data first and imports it in the background', async ( { page } ) => {
	const id = seedEuVatNumberOrder();

	await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=tax&section=vatright' );
	const tool = page.locator( '.vatright-import' );
	await expect( tool.getByRole( 'heading', { name: 'Import from another VAT plugin' } ) ).toBeVisible();
	const row = tool.locator( 'tr', { hasText: 'Orders from WooCommerce EU VAT Number (inactive)' } );
	await expect( row ).toBeVisible();
	expect( Number( ( await row.locator( 'td' ).nth( 2 ).innerText() ).replace( /\D/g, '' ) ) ).toBeGreaterThanOrEqual( 1 );
	expect( meta( id ).own ).toBe( '' );

	await tool.getByRole( 'link', { name: 'Import' } ).click();
	await expect( page.locator( '.vatright-import' ) ).toContainText( 'Import running' );

	wp( 'action-scheduler', 'run', '--hooks=vatright_import_batch', '--batches=10' );
	expect( meta( id ).own ).toBe( NUMBERS.validDe );

	await page.reload();
	await expect( page.locator( '.vatright-import' ) ).toContainText( 'Last import' );
	await expect( page.locator( '.vatright-import' ).getByRole( 'link', { name: 'Import' } ) ).toHaveCount( 0 );
} );
