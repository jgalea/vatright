import { test, expect } from '@playwright/test';
import { fillCustomer, notice, openCheckout, placeOrder, taxRow, vatField } from './block-flow';
import {
	NUMBERS,
	PRODUCTS,
	orderCount,
	orderIdFromUrl,
	orderRecord,
	registryCalls,
	resetState,
	visitorIpIn,
	wpEval,
} from './helpers';

test.beforeEach( () => resetState() );

test( 'B2C customer in Germany pays the store country VAT below the threshold', async ( { page } ) => {
	await openCheckout( page, PRODUCTS.book );
	await fillCustomer( page, { country: 'DE', postcode: '10115' } );
	await expect( taxRow( page ) ).toBeVisible();
	await expect( notice( page ) ).toHaveCount( 0 );

	await placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.decision.rule ).toBe( 'B2C_ORIGIN_BELOW_THRESHOLD' );
	expect( record.exempt ).toBe( 'no' );
	expect( Number( record.total_tax ) ).toBeGreaterThan( 0 );
} );

test( 'the VAT field only shows for countries where a VAT number means something', async ( { page } ) => {
	await openCheckout( page, PRODUCTS.ebook );
	await expect( vatField( page ) ).toBeVisible();

	await page.locator( '#billing-country' ).selectOption( 'US' );
	await expect( vatField( page ) ).toHaveCount( 0 );

	await page.locator( '#billing-country' ).selectOption( 'DE' );
	await expect( vatField( page ) ).toBeVisible();
} );

test( 'valid business number removes VAT and shows the reverse charge wording', async ( { page } ) => {
	await openCheckout( page, PRODUCTS.book );
	await fillCustomer( page, { country: 'DE', postcode: '10115' } );
	await expect( taxRow( page ) ).toBeVisible();

	await vatField( page ).fill( NUMBERS.validDe );
	await expect( notice( page ) ).toHaveText( 'VAT number verified. Reverse charge applies.' );
	await expect( taxRow( page ) ).toHaveCount( 0 );

	await placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.decision.rule ).toBe( 'RC_GOODS_ART138' );
	expect( record.exempt ).toBe( 'yes' );
	expect( record.number ).toBe( NUMBERS.validDe );
	expect( Number( record.total_tax ) ).toBe( 0 );
	expect( registryCalls() ).toBe( 1 );

	await expect( page.getByText( `VAT number: ${ NUMBERS.validDe }` ) ).toBeVisible();
	expect( ( await page.locator( 'body' ).innerText() ).split( NUMBERS.validDe ).length - 1 ).toBe( 1 );
	const id = orderIdFromUrl( page.url() );
	const mails: string[] = wpEval(
		`echo "@@" . wp_json_encode( array_values( array_filter( array_column( get_option( "vatright_e2e_mail_log", array() ), "message" ), function ( $m ) { return false !== strpos( $m, "#${ id }" ); } ) ) ) . "@@";`
	);
	expect( mails.length ).toBeGreaterThan( 0 );
	for ( const mail of mails ) {
		expect( mail.split( NUMBERS.validDe ).length - 1 ).toBe( 1 );
	}
} );

test( 'invalid number keeps VAT, explains why and blocks the order', async ( { page } ) => {
	await openCheckout( page, PRODUCTS.book );
	await fillCustomer( page, { country: 'DE', postcode: '10115', vat: NUMBERS.invalidDe } );

	await expect( notice( page ) ).toContainText( 'This VAT number is not valid' );
	await expect( taxRow( page ) ).toBeVisible();

	const before = orderCount();
	await placeOrder( page );
	await expect( page.locator( '.wc-block-components-validation-error, .wc-block-components-notice-banner.is-error' ).first() ).toBeVisible();
	expect( page.url() ).not.toContain( 'order-received' );
	expect( orderCount() ).toBe( before );
} );

test( 'registry outage places the order with VAT charged and flagged pending', async ( { page } ) => {
	await openCheckout( page, PRODUCTS.book );
	await fillCustomer( page, { country: 'DE', postcode: '10115', vat: NUMBERS.outageDe } );

	await expect( notice( page ) ).toContainText( 'could not be checked just now' );
	await expect( taxRow( page ) ).toBeVisible();

	await placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.decision.rule ).toBe( 'PENDING_CHARGED' );
	expect( record.decision.flags ).toContain( 'validation_pending' );
	expect( record.exempt ).toBe( 'no' );
	expect( Number( record.total_tax ) ).toBeGreaterThan( 0 );
} );

test( 'conflicting location evidence asks for self-certification', async ( { page, context } ) => {
	await visitorIpIn( context, 'FR' );
	await openCheckout( page, PRODUCTS.ebook );
	await fillCustomer( page, { country: 'DE', postcode: '10115' } );

	const checkbox = page.getByRole( 'checkbox', { name: 'I confirm I am located in Germany.' } ).filter( { visible: true } );
	await expect( checkbox ).toBeVisible();
	await expect( checkbox ).not.toBeChecked();

	await checkbox.click();
	await expect( checkbox ).toBeChecked();

	await placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.decision.flags ).toContain( 'evidence_conflict_self_certified' );
	expect( record.decision.evidence.self_certified ).toBe( 'DE' );
} );

test( 'Northern Ireland postcode is treated as EU for goods', async ( { page } ) => {
	await openCheckout( page, PRODUCTS.book );
	await fillCustomer( page, { country: 'GB', postcode: 'SW1A 1AA' } );
	await expect( taxRow( page ) ).toHaveCount( 0 );

	await page.locator( '#shipping-postcode' ).fill( 'BT1 1AA' );
	await expect( taxRow( page ) ).toBeVisible();

	await vatField( page ).fill( NUMBERS.validXi );
	await expect( notice( page ) ).toHaveText( 'VAT number verified. Reverse charge applies.' );
	await expect( taxRow( page ) ).toHaveCount( 0 );

	await placeOrder( page );
	await page.waitForURL( /order-received/ );
	const record = orderRecord( orderIdFromUrl( page.url() ) );
	expect( record.decision.rule ).toBe( 'RC_GOODS_ART138' );
	expect( record.decision.vat_number.prefix ).toBe( 'XI' );
	expect( record.exempt ).toBe( 'yes' );
} );

test( 'the notice sits right under the VAT field and survives a resize between layouts', async ( { page } ) => {
	await page.setViewportSize( { width: 390, height: 844 } );
	await openCheckout( page, PRODUCTS.book );
	await fillCustomer( page, { country: 'DE', postcode: '10115', vat: NUMBERS.validDe } );
	await expect( notice( page ) ).toHaveText( 'VAT number verified. Reverse charge applies.' );

	const gap = async () => {
		const field = ( await vatField( page ).boundingBox() )!;
		const message = ( await notice( page ).boundingBox() )!;
		return message.y - ( field.y + field.height );
	};
	expect( await gap() ).toBeLessThan( 150 );

	await page.setViewportSize( { width: 1440, height: 900 } );
	await expect( notice( page ) ).toHaveText( 'VAT number verified. Reverse charge applies.' );
	expect( await gap() ).toBeLessThan( 150 );

	await page.setViewportSize( { width: 390, height: 844 } );
	await expect( notice( page ) ).toHaveText( 'VAT number verified. Reverse charge applies.' );
} );
