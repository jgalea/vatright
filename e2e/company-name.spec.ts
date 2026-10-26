import { test, expect, type Page } from '@playwright/test';
import * as classic from './classic-flow';
import * as block from './block-flow';
import { NUMBERS, PRODUCTS, orderCount, orderIdFromUrl, orderRecord, resetState, setSettings, wp } from './helpers';

const SHOTS = process.env.VATRIGHT_SHOTS ?? '';
const REFUSAL = 'registered for this VAT number';
const WRONG = 'Totally Different Bakery';
const REGISTERED = 'Industria de Diseno Textil SA';
const NL = { country: 'NL', state: '', postcode: '1017AB', vat: NUMBERS.validNl };
const ES = { country: 'ES', state: 'M', postcode: '28001', vat: NUMBERS.validEs };

let previousCompanyField = 'hidden';

test.beforeAll( () => {
	previousCompanyField = wp( 'option', 'get', 'woocommerce_checkout_company_field' ).trim() || 'hidden';
	wp( 'option', 'update', 'woocommerce_checkout_company_field', 'optional' );
} );

test.afterAll( () => {
	wp( 'option', 'update', 'woocommerce_checkout_company_field', previousCompanyField );
	resetState();
} );

test.beforeEach( () => {
	resetState();
	setSettings( { refuse_name_mismatch: true } );
} );

async function shot( page: Page, name: string ) {
	if ( SHOTS ) {
		await page.screenshot( { path: `${ SHOTS }/${ name }.png`, fullPage: true } );
	}
}

async function classicCheckout( page: Page, company: string, customer: typeof ES = ES ) {
	await classic.openCheckout( page, PRODUCTS.ebook );
	await classic.fillCustomer( page, customer );
	await page.locator( '#billing_company' ).fill( company );
}

async function blockCheckout( page: Page, company: string, customer: typeof ES = ES ) {
	await block.openCheckout( page, PRODUCTS.ebook );
	await block.fillCustomer( page, customer );
	await page.locator( '#billing-company' ).fill( company );
}

test( 'classic checkout refuses a company name the registry rejects, then takes the registered one', async ( { page } ) => {
	await classicCheckout( page, WRONG );
	const before = orderCount();

	await classic.placeOrder( page );

	await expect( page.getByRole( 'alert' ).filter( { hasText: REFUSAL } ) ).toBeVisible();
	expect( page.url() ).not.toContain( 'order-received' );
	expect( orderCount() ).toBe( before );
	await shot( page, 'name-mismatch-classic' );

	await page.locator( '#billing_company' ).fill( REGISTERED );
	await classic.placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.number ).toBe( NUMBERS.validEs );
	expect( record.exempt ).toBe( 'yes' );
} );

test( 'block checkout refuses a company name the registry rejects, then takes the registered one', async ( { page } ) => {
	await blockCheckout( page, WRONG );
	const before = orderCount();

	await block.placeOrder( page );

	await expect( page.getByText( REFUSAL ).first() ).toBeVisible();
	expect( page.url() ).not.toContain( 'order-received' );
	expect( orderCount() ).toBe( before );
	await shot( page, 'name-mismatch-block' );

	await page.locator( '#billing-company' ).fill( REGISTERED );
	await block.placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.number ).toBe( NUMBERS.validEs );
	expect( record.exempt ).toBe( 'yes' );
} );

test( 'a country whose registry gives no name verdict is never refused', async ( { page } ) => {
	await classicCheckout( page, WRONG, { country: 'DE', postcode: '10115', vat: NUMBERS.validDe } as typeof ES );
	await classic.placeOrder( page );
	await page.waitForURL( /order-received/ );
	expect( orderRecord( orderIdFromUrl( page.url() ) ).exempt ).toBe( 'yes' );
} );

test( 'an order with no company name is not refused', async ( { page } ) => {
	await classicCheckout( page, '' );
	await classic.placeOrder( page );
	await page.waitForURL( /order-received/ );
	expect( orderRecord( orderIdFromUrl( page.url() ) ).exempt ).toBe( 'yes' );
} );

test( 'with the setting off the same mismatch places the order on the classic checkout', async ( { page } ) => {
	setSettings( {} );
	await classicCheckout( page, WRONG );
	await classic.placeOrder( page );
	await page.waitForURL( /order-received/ );
	expect( orderRecord( orderIdFromUrl( page.url() ) ).exempt ).toBe( 'yes' );
} );

test( 'with the setting off the same mismatch places the order on the block checkout', async ( { page } ) => {
	setSettings( {} );
	await blockCheckout( page, WRONG );
	await block.placeOrder( page );
	await page.waitForURL( /order-received/ );
	expect( orderRecord( orderIdFromUrl( page.url() ) ).exempt ).toBe( 'yes' );
} );

test( 'classic checkout refuses a name that differs from the one VIES returns, then takes a looser spelling of it', async ( { page } ) => {
	await classicCheckout( page, WRONG, NL );
	const before = orderCount();

	await classic.placeOrder( page );

	await expect( page.getByRole( 'alert' ).filter( { hasText: REFUSAL } ) ).toBeVisible();
	expect( page.url() ).not.toContain( 'order-received' );
	expect( orderCount() ).toBe( before );

	await page.locator( '#billing_company' ).fill( 'abn amro bank' );
	await classic.placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.number ).toBe( NUMBERS.validNl );
	expect( record.exempt ).toBe( 'yes' );
} );

test( 'block checkout refuses a name that differs from the one VIES returns, then takes a looser spelling of it', async ( { page } ) => {
	await blockCheckout( page, WRONG, NL );
	const before = orderCount();

	await block.placeOrder( page );

	await expect( page.getByText( REFUSAL ).first() ).toBeVisible();
	expect( page.url() ).not.toContain( 'order-received' );
	expect( orderCount() ).toBe( before );

	await page.locator( '#billing-company' ).fill( 'ABN AMRO Bank N.V.' );
	await block.placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.number ).toBe( NUMBERS.validNl );
	expect( record.exempt ).toBe( 'yes' );
} );
