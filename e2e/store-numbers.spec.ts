import { test, expect, type Page } from '@playwright/test';
import * as classic from './classic-flow';
import { NUMBERS, PRODUCTS, resetState, setSettings, wp } from './helpers';

const SHOTS = process.env.VATRIGHT_SHOTS ?? '';
const SETTINGS = '/wp-admin/admin.php?page=wc-settings&tab=tax&section=vatright';
const EU = 'DE114103379';
const GB = 'GB980780684';

test.beforeEach( () => {
	resetState();
} );

test.afterAll( () => {
	resetState();
} );

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}

async function placeOrder( page: Page, country: string, postcode: string, vat?: string ) {
	await classic.openCheckout( page, PRODUCTS.ebook );
	await classic.fillCustomer( page, { country, postcode, vat } );
	await classic.placeOrder( page );
	await page.waitForURL( /order-received/ );
	const order = page.locator( 'main' );
	await expect( order ).toContainText( 'Order details' );
	return order;
}

test( 'the settings screen takes a separate UK store number and keeps it', async ( { page } ) => {
	setSettings( { store_vat_number: EU } );
	await login( page );
	await page.goto( SETTINGS );

	const gb = page.locator( '[name="vatright_settings[store_vat_number_gb]"]' );
	await expect( gb ).toBeVisible();
	await expect( page.getByText( 'UK store VAT number' ).first() ).toBeVisible();
	await gb.fill( ' gb 980 7806 84 ' );
	await page.locator( '.woocommerce-save-button' ).click();

	await expect( page.locator( '[name="vatright_settings[store_vat_number_gb]"]' ) ).toHaveValue( GB );
	await page.locator( '[name="vatright_settings[store_vat_number_gb]"]' ).scrollIntoViewIfNeeded();
	if ( SHOTS ) {
		await page.screenshot( { path: `${ SHOTS }/store-numbers-settings.png` } );
	}

	await page.locator( '[name="vatright_settings[store_vat_number_gb]"]' ).fill( 'DE114103379' );
	await page.locator( '.woocommerce-save-button' ).click();
	await expect( page.locator( '[name="vatright_settings[store_vat_number_gb]"]' ) ).toHaveValue( GB );
	await expect( page.getByText( 'does not look like a GB number' ) ).toBeVisible();
} );

test( 'a UK customer is shown the UK number and a German customer the EU number', async ( { page } ) => {
	setSettings( { store_vat_number: EU, store_vat_number_gb: GB } );

	const uk = await placeOrder( page, 'GB', 'SW1A 1AA' );
	await expect( uk ).toContainText( 'Our VAT number' );
	await expect( uk ).toContainText( GB );
	await expect( uk ).not.toContainText( EU );
	if ( SHOTS ) {
		await page.screenshot( { path: `${ SHOTS }/store-numbers-uk-order.png`, fullPage: true } );
	}

	const de = await placeOrder( page, 'DE', '10115', NUMBERS.validDe );
	await expect( de ).toContainText( 'Our VAT number' );
	await expect( de.locator( 'tr', { hasText: 'Our VAT number' } ) ).toContainText( EU );
	await expect( de.locator( 'tr', { hasText: 'Our VAT number' } ) ).not.toContainText( GB );
} );

test( 'a store with one number shows no store number on the order, as before', async ( { page } ) => {
	setSettings( { store_vat_number: EU } );

	const uk = await placeOrder( page, 'GB', 'SW1A 1AA' );
	await expect( uk ).not.toContainText( 'Our VAT number' );
} );
