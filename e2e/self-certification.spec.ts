import { test, expect, type Page } from '@playwright/test';
import * as block from './block-flow';
import * as classic from './classic-flow';
import { PRODUCTS, orderCount, orderIdFromUrl, orderRecord, resetState, setSettings, visitorIpIn, wpEval } from './helpers';

const SHOTS = process.env.VATRIGHT_SHOTS || '';
const MESSAGE = 'Confirm the country you are located in to continue.';

test.beforeEach( () => resetState() );

async function shot( page: Page, name: string ) {
	if ( SHOTS ) {
		await page.screenshot( { path: `${ SHOTS }/${ name }.png`, fullPage: true } );
	}
}

async function adminShowsConfirmation( page: Page, id: number ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await page.goto( `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }` );
	const box = page.locator( '#vatright-order-box, .postbox:has-text("EU VAT")' ).first();
	await expect( box ).toContainText( 'self-certified DE' );
	await expect( box ).toContainText( 'self-certified their country' );
}

test.describe( 'block checkout', () => {
	test( 'required: blocks until the customer confirms, then stores the confirmation', async ( { page, context } ) => {
		setSettings( { self_certification_required: true } );
		await visitorIpIn( context, 'FR' );
		await block.openCheckout( page, PRODUCTS.ebook );
		await block.fillCustomer( page, { country: 'DE', postcode: '10115' } );

		await expect( block.notice( page ) ).toContainText( MESSAGE );
		const checkbox = page.getByRole( 'checkbox', { name: 'I confirm I am located in Germany.' } ).filter( { visible: true } );
		await expect( checkbox ).not.toBeChecked();
		await shot( page, 'block-required-prompt' );

		const before = orderCount();
		await block.placeOrder( page );
		await expect( page.locator( '.wc-block-components-notice-banner.is-error' ).first() ).toContainText( MESSAGE );
		expect( page.url() ).not.toContain( 'order-received' );
		expect( orderCount() ).toBe( before );
		await shot( page, 'block-required-blocked' );

		await checkbox.click();
		await expect( checkbox ).toBeChecked();
		await expect( block.notice( page ) ).toHaveCount( 0 );
		await block.placeOrder( page );
		await page.waitForURL( /order-received/ );
		const id = orderIdFromUrl( page.url() );
		expect( orderRecord( id ).decision.evidence.self_certified ).toBe( 'DE' );
		await adminShowsConfirmation( page, id );
		await shot( page, 'block-admin-order' );
	} );

	test( 'not required: the order goes through unconfirmed', async ( { page, context } ) => {
		await visitorIpIn( context, 'FR' );
		await block.openCheckout( page, PRODUCTS.ebook );
		await block.fillCustomer( page, { country: 'DE', postcode: '10115' } );
		await expect( page.getByRole( 'checkbox', { name: 'I confirm I am located in Germany.' } ).filter( { visible: true } ) ).toBeVisible();
		await expect( block.notice( page ) ).toHaveCount( 0 );

		await block.placeOrder( page );
		await page.waitForURL( /order-received/ );
		expect( orderRecord( orderIdFromUrl( page.url() ) ).decision.evidence.self_certified ).toBeNull();
	} );
} );

test.describe( 'classic checkout', () => {
	test( 'required: blocks until the customer confirms, then stores the confirmation', async ( { page, context } ) => {
		setSettings( { self_certification_required: true } );
		await visitorIpIn( context, 'FR' );
		await classic.openCheckout( page, PRODUCTS.ebook );
		await classic.fillCustomer( page, { country: 'DE', postcode: '10115' } );

		await expect( classic.notice( page ) ).toContainText( MESSAGE );
		const checkbox = page.getByRole( 'checkbox', { name: 'I confirm I am located in Germany.' } );
		await expect( checkbox ).not.toBeChecked();
		await shot( page, 'classic-required-prompt' );

		const before = orderCount();
		await classic.placeOrder( page );
		await expect( page.locator( '.woocommerce-NoticeGroup-checkout' ) ).toContainText( MESSAGE );
		expect( page.url() ).not.toContain( 'order-received' );
		expect( orderCount() ).toBe( before );
		await shot( page, 'classic-required-blocked' );

		await classic.updated( page, () => checkbox.check() );
		await expect( classic.notice( page ) ).toHaveCount( 0 );
		await classic.placeOrder( page );
		await page.waitForURL( /order-received/ );
		const id = orderIdFromUrl( page.url() );
		expect( orderRecord( id ).decision.evidence.self_certified ).toBe( 'DE' );
		await adminShowsConfirmation( page, id );
	} );

	test( 'not required: the order goes through unconfirmed', async ( { page, context } ) => {
		await visitorIpIn( context, 'FR' );
		await classic.openCheckout( page, PRODUCTS.ebook );
		await classic.fillCustomer( page, { country: 'DE', postcode: '10115' } );
		await expect( page.getByRole( 'checkbox', { name: 'I confirm I am located in Germany.' } ) ).toBeVisible();
		await expect( classic.notice( page ) ).toHaveCount( 0 );

		await classic.placeOrder( page );
		await page.waitForURL( /order-received/ );
		expect( orderRecord( orderIdFromUrl( page.url() ) ).decision.evidence.self_certified ).toBeNull();
	} );

	test( 'required: a goods order is not blocked', async ( { page, context } ) => {
		setSettings( { self_certification_required: true } );
		await visitorIpIn( context, 'FR' );
		await classic.openCheckout( page, PRODUCTS.book );
		await classic.fillCustomer( page, { country: 'DE', postcode: '10115' } );
		await classic.placeOrder( page );
		await page.waitForURL( /order-received/ );
	} );
} );
