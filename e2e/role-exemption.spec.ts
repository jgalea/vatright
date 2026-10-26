import { test, expect, type Page } from '@playwright/test';
import { fillCustomer as fillBlock, placeOrder as placeBlock } from './block-flow';
import { fillCustomer as fillClassic, openCheckout as openClassic, placeOrder as placeClassic, taxRow } from './classic-flow';
import { PRODUCTS, addToCart, orderIdFromUrl, orderRecord, resetState, setSettings } from './helpers';

async function signIn( page: Page, login: string, pass: string, path: string ) {
	await page.goto( path );
	await page.locator( '#username, #user_login' ).fill( login );
	await page.locator( '#password, #user_pass' ).fill( pass );
	await page.locator( 'button[name="login"], #wp-submit' ).click();
}

test.beforeEach( async ( { page } ) => {
	resetState();
	setSettings( { exempt_roles: [ 'customer' ] } );
	await signIn( page, 'vatright-customer', 'vatright-pass', '/my-account/' );
	await expect( page.locator( '.woocommerce-MyAccount-navigation' ) ).toBeVisible();
} );

test( 'classic checkout: a customer with an exempt role pays no VAT and the rule is recorded', async ( { page } ) => {
	await openClassic( page, PRODUCTS.book );
	await fillClassic( page, { country: 'DE', postcode: '10115' } );
	await expect( taxRow( page ) ).toHaveCount( 0 );

	await placeClassic( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.decision.rule ).toBe( 'ROLE_EXEMPT' );
	expect( record.decision.customer.exempt_roles ).toEqual( [ 'customer' ] );
	expect( record.exempt ).toBe( 'yes' );
	expect( Number( record.total_tax ) ).toBe( 0 );
} );

// A signed-in customer with a saved address gets it as a card, with the form behind Edit.
async function openBlock( page: Page, product: number ) {
	await addToCart( page, product );
	await page.goto( '/checkout/' );
	const edit = page.getByRole( 'button', { name: 'Edit shipping address' } );
	await edit.waitFor( { timeout: 10_000 } ).catch( () => undefined );
	if ( await edit.isVisible() ) {
		await edit.click();
	}
	await expect( page.locator( '#shipping-country' ) ).toBeVisible();
}

test( 'block checkout: a customer with an exempt role pays no VAT and the rule is recorded', async ( { page } ) => {
	await openBlock( page, PRODUCTS.book );
	await fillBlock( page, { country: 'DE', postcode: '10115' } );
	await expect( page.locator( '.wc-block-components-totals-taxes' ).filter( { visible: true } ) ).toHaveCount( 0 );

	await placeBlock( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.decision.rule ).toBe( 'ROLE_EXEMPT' );
	expect( record.exempt ).toBe( 'yes' );
	expect( Number( record.total_tax ) ).toBe( 0 );
} );

test( 'the same customer is charged when their role is not listed', async ( { page } ) => {
	setSettings( { exempt_roles: [ 'shop_manager' ] } );
	await openClassic( page, PRODUCTS.book );
	await fillClassic( page, { country: 'DE', postcode: '10115' } );
	await expect( taxRow( page ).first() ).toBeVisible();

	await placeClassic( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.decision.rule ).toBe( 'B2C_ORIGIN_BELOW_THRESHOLD' );
	expect( Number( record.total_tax ) ).toBeGreaterThan( 0 );
} );

test( 'the admin order screen shows the role exemption as its own rule', async ( { page, browser } ) => {
	await openClassic( page, PRODUCTS.book );
	await fillClassic( page, { country: 'DE', postcode: '10115' } );
	await placeClassic( page );
	await page.waitForURL( /order-received/ );
	const id = orderIdFromUrl( page.url() );

	const admin = await browser.newPage();
	await signIn( admin, 'admin', 'password', '/wp-login.php' );
	await expect( admin.locator( '#wpadminbar' ) ).toBeVisible();
	await admin.goto( `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }` );
	const box = admin.locator( '#vatright-decision, .postbox' ).filter( { hasText: 'VAT-exempt because of the customer role' } ).first();
	await expect( box ).toBeVisible();
	await expect( box ).toContainText( 'Exempt customer roles' );
	await expect( box ).toContainText( 'customer' );
	await box.scrollIntoViewIfNeeded();
	await box.screenshot( { path: process.env.HOME + '/code/artifacts/vatright/features/accounts/admin-order-role-exempt.png' } );
	await admin.close();
} );
