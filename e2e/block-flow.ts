import { expect, type Page } from '@playwright/test';
import { TEST_CUSTOMER, addToCart, type Customer } from './helpers';

export const taxRow = ( page: Page ) => page.locator( '.wc-block-components-totals-taxes' ).filter( { visible: true } );
export const notice = ( page: Page ) => page.locator( '.vatright-notice__message' ).filter( { visible: true } );

export async function openCheckout( page: Page, product: number ) {
	await addToCart( page, product );
	await page.goto( '/checkout/' );
	await expect( page.locator( '#billing-country, #shipping-country' ) ).toBeVisible();
}

/**
 * Goods carts show one address form (shipping, reused for billing); carts
 * without shipping show the billing form.
 */
export async function fillCustomer( page: Page, customer: Customer ) {
	const form = ( await page.locator( '#shipping-country' ).count() ) > 0 ? 'shipping' : 'billing';
	await page.locator( `#${ form }-country` ).selectOption( customer.country );
	await page.locator( '#email' ).fill( TEST_CUSTOMER.email );
	await page.locator( `#${ form }-first_name` ).fill( TEST_CUSTOMER.first );
	await page.locator( `#${ form }-last_name` ).fill( TEST_CUSTOMER.last );
	await page.locator( `#${ form }-address_1` ).fill( TEST_CUSTOMER.address );
	await page.locator( `#${ form }-city` ).fill( TEST_CUSTOMER.city );
	if ( customer.state ) {
		await page.locator( `#${ form }-state` ).selectOption( customer.state );
	}
	await page.locator( `#${ form }-postcode` ).fill( customer.postcode );
	await page.locator( `#${ form }-phone` ).fill( TEST_CUSTOMER.phone );
	await settle( page );
	if ( customer.vat ) {
		await vatField( page ).fill( customer.vat );
		await vatField( page ).blur();
	}
}

/**
 * Typing faster than the checkout block saves lets a late server reply
 * overwrite fields with their previous values. A person leaves the field and
 * pauses before the next step, so the tests do too.
 */
export async function settle( page: Page ) {
	await page.evaluate( () => ( document.activeElement as HTMLElement | null )?.blur() );
	await page.waitForTimeout( 1500 );
	await page.waitForLoadState( 'networkidle' );
}

export const vatField = ( page: Page ) => page.getByLabel( 'VAT number' );

export async function placeOrder( page: Page ) {
	await settle( page );
	await page.locator( '.wc-block-components-checkout-place-order-button' ).click();
}
