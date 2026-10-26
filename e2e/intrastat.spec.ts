import { readFileSync } from 'node:fs';
import { test, expect, type Page } from '@playwright/test';
import * as classic from './classic-flow';
import { NUMBERS, PRODUCTS, orderIdFromUrl, resetState, wp, wpEval } from './helpers';

const SHOTS = process.env.B2B_SHOTS;
const REPORT = '/wp-admin/admin.php?page=vatright-reports';

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}

function setProduct( meta: Record< string, string > ) {
	const updates = Object.entries( meta ).map( ( [ key, value ] ) => `update_post_meta( ${ PRODUCTS.book }, "${ key }", "${ value }" );` );
	wpEval( `${ updates.join( ' ' ) } echo "@@1@@";` );
}

function clearProduct() {
	wpEval( `foreach ( array( "_vatright_cn8", "_vatright_origin_country", "_vatright_net_mass", "_vatright_supp_units", "_weight" ) as $k ) { delete_post_meta( ${ PRODUCTS.book }, $k ); } echo "@@1@@";` );
}

function clearOrders() {
	wpEval( 'foreach ( wc_get_orders( array( "limit" => -1 ) ) as $o ) { $o->delete( true ); } global $wpdb; $wpdb->query( "TRUNCATE TABLE " . Vatright\\Ledger\\Schema::ledger_table() ); echo "@@1@@";' );
}

test.beforeEach( () => {
	resetState();
	clearProduct();
	clearOrders();
} );

test.afterAll( () => {
	clearProduct();
	clearOrders();
	resetState();
} );

test( 'the product screen saves the INTRASTAT fields and rejects a bad CN8 code', async ( { page } ) => {
	await login( page );
	await page.goto( `/wp-admin/post.php?post=${ PRODUCTS.book }&action=edit` );

	await page.locator( '#_vatright_cn8' ).fill( '4901 9900' );
	await page.locator( '#_vatright_origin_country' ).fill( 'pt' );
	await page.locator( '#_vatright_net_mass' ).fill( '0.45' );
	await page.locator( '#publish' ).click();
	await expect( page.locator( '#message.updated' ) ).toContainText( 'Product updated' );
	await expect( page.locator( '#_vatright_cn8' ) ).toHaveValue( '49019900' );
	await expect( page.locator( '#_vatright_origin_country' ) ).toHaveValue( 'PT' );
	await expect( page.locator( '#_vatright_net_mass' ) ).toHaveValue( '0.45' );
	if ( SHOTS ) {
		await page.locator( '#_vatright_cn8' ).scrollIntoViewIfNeeded();
		await page.screenshot( { path: `${ SHOTS }/product-intrastat-fields.png` } );
	}

	await page.locator( '#_vatright_cn8' ).fill( '1234' );
	await page.locator( '#publish' ).click();
	await expect( page.getByText( 'The CN8 code must be eight digits' ) ).toBeVisible();
	await expect( page.locator( '#_vatright_cn8' ) ).toHaveValue( '' );
} );

test( 'the report lists a dispatched order and downloads it as CSV', async ( { page } ) => {
	setProduct( { _vatright_cn8: '49019900', _vatright_origin_country: 'PT', _vatright_net_mass: '0.45' } );
	await classic.openCheckout( page, PRODUCTS.book );
	await classic.fillCustomer( page, { country: 'DE', postcode: '10115', vat: NUMBERS.validDe } );
	await classic.placeOrder( page );
	await page.waitForURL( /order-received/ );
	const orderId = orderIdFromUrl( page.url() );
	wp( 'eval', `wc_get_order( ${ orderId } )->update_status( "processing" );` );

	await login( page );
	await page.goto( REPORT );
	const table = page.locator( 'table.vatright-intrastat' );
	await expect( page.getByRole( 'heading', { name: 'INTRASTAT dispatches' } ) ).toBeVisible();
	await expect( table.locator( 'tbody tr' ).filter( { hasText: '49019900' } ).locator( 'td' ) ).toHaveText( [ 'DE', '49019900', '11', 'PT', NUMBERS.validDe, '0.450', '100.00', '' ] );
	await expect( page.getByText( 'not the filing itself' ) ).toBeVisible();
	if ( SHOTS ) {
		await page.setViewportSize( { width: 1440, height: 1100 } );
		await page.screenshot( { path: `${ SHOTS }/intrastat-report.png`, fullPage: true } );
	}

	const download = page.waitForEvent( 'download' );
	await page.getByRole( 'button', { name: 'Download INTRASTAT CSV' } ).click();
	const file = await ( await download ).path();
	const csv = readFileSync( file as string, 'utf8' );
	expect( csv.split( '\n' )[ 0 ].replace( /"/g, '' ) ).toBe( 'Partner member state,CN8 commodity code,Nature of transaction,Country of origin,Partner VAT number,Net mass (kg),Invoice value (EUR),Supplementary units' );
	expect( csv ).toContain( `DE,49019900,11,PT,${ NUMBERS.validDe },0.450,100.00,` );
} );

test( 'products without the filing fields are flagged on the report', async ( { page } ) => {
	await classic.openCheckout( page, PRODUCTS.book );
	await classic.fillCustomer( page, { country: 'DE', postcode: '10115', vat: NUMBERS.validDe } );
	await classic.placeOrder( page );
	await page.waitForURL( /order-received/ );
	wp( 'eval', `wc_get_order( ${ orderIdFromUrl( page.url() ) } )->update_status( "processing" );` );

	await login( page );
	await page.goto( REPORT );

	await expect( page.locator( '.notice-warning' ).filter( { hasText: 'missing data an INTRASTAT declaration needs' } ) ).toContainText( 'Book' );
} );
