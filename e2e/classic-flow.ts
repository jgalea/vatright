import { expect, type Page } from '@playwright/test';
import { TEST_CUSTOMER, addToCart, type Customer } from './helpers';

export const taxRow = ( page: Page ) => page.locator( '.woocommerce-checkout-review-order-table tr.tax-rate, .woocommerce-checkout-review-order-table tr.tax-total' );
export const notice = ( page: Page ) => page.locator( '.vatright-notice__message' );

export async function updated( page: Page, action: () => Promise< unknown > ) {
	const response = page.waitForResponse( ( r ) => r.url().includes( 'wc-ajax=update_order_review' ) && r.status() === 200 );
	await action();
	await response;
	await page.waitForLoadState( 'networkidle' );
}

export async function openCheckout( page: Page, product: number ) {
	await addToCart( page, product );
	await page.goto( '/classic-checkout/' );
	await expect( page.locator( '#billing_first_name' ) ).toBeVisible();
	await page.waitForLoadState( 'networkidle' );
}

export async function fillCustomer( page: Page, customer: Customer ) {
	await page.locator( '#billing_first_name' ).fill( TEST_CUSTOMER.first );
	await page.locator( '#billing_last_name' ).fill( TEST_CUSTOMER.last );
	await page.locator( '#billing_address_1' ).fill( TEST_CUSTOMER.address );
	await page.locator( '#billing_city' ).fill( TEST_CUSTOMER.city );
	await page.locator( '#billing_phone' ).fill( TEST_CUSTOMER.phone );
	await page.locator( '#billing_email' ).fill( TEST_CUSTOMER.email );
	await updated( page, () => page.locator( '#billing_country' ).selectOption( customer.country ) );
	if ( customer.state ) {
		await page.locator( '#billing_state' ).evaluate( ( el, value ) => {
			( window as any ).jQuery( el ).val( value ).trigger( 'change' );
		}, customer.state );
	}
	await changePostcode( page, customer.postcode );
	if ( customer.vat ) {
		await enterVat( page, customer.vat );
	}
}

export async function changePostcode( page: Page, postcode: string ) {
	await updated( page, async () => {
		await page.locator( '#billing_postcode' ).fill( postcode );
		await page.locator( '#billing_postcode' ).blur();
		await page.evaluate( () => ( window as any ).jQuery( document.body ).trigger( 'update_checkout' ) );
	} );
}

export async function enterVat( page: Page, vat: string ) {
	await updated( page, () => page.locator( '#billing_vatright_number' ).fill( vat ) );
}

export async function placeOrder( page: Page ) {
	await page.locator( '#place_order' ).click();
}
