import { test, expect, type Page } from '@playwright/test';
import * as classic from './classic-flow';
import * as block from './block-flow';
import { NUMBERS, PRODUCTS, orderCount, orderIdFromUrl, orderRecord, resetState, setSettings, wpEval } from './helpers';

const SHOTS = process.env.B2B_SHOTS;
const MESSAGE = 'This shop sells to businesses only. Enter a valid VAT number to place your order.';

async function shot( page: Page, name: string ) {
	if ( SHOTS ) {
		await page.screenshot( { path: `${ SHOTS }/${ name }.png`, fullPage: true } );
	}
}

test.beforeEach( () => {
	resetState();
	setSettings( { b2b_only: 'everywhere' } );
} );

test.afterAll( () => {
	resetState();
} );

test( 'classic checkout refuses an order without a VAT number and takes one with a verified number', async ( { page } ) => {
	await classic.openCheckout( page, PRODUCTS.book );
	await classic.fillCustomer( page, { country: 'DE', postcode: '10115' } );

	const before = orderCount();
	await classic.placeOrder( page );
	await expect( page.getByRole( 'alert' ).filter( { hasText: MESSAGE } ) ).toBeVisible();
	expect( page.url() ).not.toContain( 'order-received' );
	expect( orderCount() ).toBe( before );
	await shot( page, 'classic-checkout-b2b-only-error' );

	await classic.enterVat( page, NUMBERS.validDe );
	await classic.placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.number ).toBe( NUMBERS.validDe );
	expect( record.exempt ).toBe( 'yes' );
} );

test( 'classic checkout shows the business-only message for an invalid number', async ( { page } ) => {
	await classic.openCheckout( page, PRODUCTS.book );
	await classic.fillCustomer( page, { country: 'DE', postcode: '10115', vat: NUMBERS.invalidDe } );

	await classic.placeOrder( page );

	await expect( page.getByRole( 'alert' ).filter( { hasText: 'this shop sells to businesses only' } ) ).toBeVisible();
	await expect( page.getByText( 'leave the field empty' ) ).toHaveCount( 0 );
} );

test( 'block checkout refuses an order without a VAT number and takes one with a verified number', async ( { page } ) => {
	await block.openCheckout( page, PRODUCTS.book );
	await block.fillCustomer( page, { country: 'DE', postcode: '10115' } );

	const before = orderCount();
	await block.placeOrder( page );
	await expect( page.getByText( 'VAT number is required' ).first() ).toBeVisible();
	expect( page.url() ).not.toContain( 'order-received' );
	expect( orderCount() ).toBe( before );
	await shot( page, 'block-checkout-b2b-only-error' );

	await block.vatField( page ).fill( NUMBERS.validDe );
	await block.placeOrder( page );
	await page.waitForURL( /order-received/ );
	expect( orderRecord( orderIdFromUrl( page.url() ) ).number ).toBe( NUMBERS.validDe );
} );

test( 'the setting is saved from the VAT Right settings page', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

	setSettings( {} );
	await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=tax&section=vatright' );
	const mode = page.locator( '[name="vatright_settings[b2b_only]"]' );
	await expect( mode ).toHaveValue( 'off' );
	await expect( page.getByText( 'is never accepted unchecked' ) ).toBeVisible();

	await mode.selectOption( 'countries' );
	await page.locator( '[name="vatright_settings[b2b_only_countries][]"]' ).evaluate( ( el: HTMLSelectElement ) => {
		Array.from( el.options ).forEach( ( o ) => ( o.selected = [ 'DE', 'FR' ].includes( o.value ) ) );
	} );
	await shot( page, 'settings-b2b-only' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '#message.updated, .notice-success' ).first() ).toBeVisible();

	const stored = wpEval( 'echo "@@" . wp_json_encode( array( get_option( "vatright_settings" )["b2b_only"], get_option( "vatright_settings" )["b2b_only_countries"] ) ) . "@@";' );
	expect( stored[ 0 ] ).toBe( 'countries' );
	expect( [ ...stored[ 1 ] ].sort() ).toEqual( [ 'DE', 'FR' ] );
} );

const classicRow = ( page: Page ) => page.locator( '#billing_vatright_number_field' );

test( 'classic checkout marks the VAT field required only for the countries business-only mode covers', async ( { page } ) => {
	setSettings( { b2b_only: 'countries', b2b_only_countries: [ 'DE' ] } );
	await classic.openCheckout( page, PRODUCTS.book );
	await classic.fillCustomer( page, { country: 'FR', postcode: '75001' } );
	await expect( classicRow( page ) ).toContainText( '(optional)' );
	await expect( classicRow( page ).locator( 'abbr.required' ) ).toHaveCount( 0 );

	await classic.updated( page, () => page.locator( '#billing_country' ).selectOption( 'DE' ) );
	await expect( classicRow( page ).locator( 'abbr.required' ) ).toHaveCount( 1 );
	await expect( classicRow( page ) ).not.toContainText( '(optional)' );
	await shot( page, 'classic-checkout-b2b-only-required-label' );

	await classic.updated( page, () => page.locator( '#billing_country' ).selectOption( 'FR' ) );
	await expect( classicRow( page ) ).toContainText( '(optional)' );
	await expect( classicRow( page ).locator( 'abbr.required' ) ).toHaveCount( 0 );
} );

test( 'classic checkout renders the VAT field required from the first paint when the stored country is covered', async ( { page } ) => {
	await classic.openCheckout( page, PRODUCTS.book );
	await classic.fillCustomer( page, { country: 'DE', postcode: '10115' } );
	await page.reload();
	await expect( classicRow( page ).locator( 'abbr.required' ) ).toHaveCount( 1 );
	await expect( classicRow( page ) ).not.toContainText( '(optional)' );
} );

test( 'classic checkout keeps the VAT field optional while business-only mode is off', async ( { page } ) => {
	setSettings( { b2b_only: 'off' } );
	await classic.openCheckout( page, PRODUCTS.book );
	await classic.fillCustomer( page, { country: 'DE', postcode: '10115' } );
	await expect( classicRow( page ) ).toContainText( '(optional)' );
	await expect( classicRow( page ).locator( 'abbr.required' ) ).toHaveCount( 0 );
} );

test( 'block checkout drops "(optional)" from the VAT field for the countries business-only mode covers', async ( { page } ) => {
	setSettings( { b2b_only: 'countries', b2b_only_countries: [ 'DE' ] } );
	await block.openCheckout( page, PRODUCTS.book );
	await block.fillCustomer( page, { country: 'FR', postcode: '75001' } );
	await expect( page.getByLabel( 'VAT number (optional)' ) ).toBeVisible();

	await page.locator( '#shipping-country' ).selectOption( 'DE' );
	await expect( page.getByLabel( 'VAT number', { exact: true } ) ).toBeVisible();
	await expect( page.getByLabel( 'VAT number (optional)' ) ).toHaveCount( 0 );
	await page.waitForLoadState( 'networkidle' );
	await expect( page.locator( '.wc-block-components-skeleton__element' ) ).toHaveCount( 0 );
	await shot( page, 'block-checkout-b2b-only-required-label' );

	await page.locator( '#shipping-country' ).selectOption( 'FR' );
	await expect( page.getByLabel( 'VAT number (optional)' ) ).toBeVisible();
} );

test( 'block checkout keeps "(optional)" on the VAT field while business-only mode is off', async ( { page } ) => {
	setSettings( { b2b_only: 'off' } );
	await block.openCheckout( page, PRODUCTS.book );
	await block.fillCustomer( page, { country: 'DE', postcode: '10115' } );
	await expect( page.getByLabel( 'VAT number (optional)' ) ).toBeVisible();
} );
