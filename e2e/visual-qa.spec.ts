import os from 'node:os';
import path from 'node:path';
import fs from 'node:fs';
import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import * as block from './block-flow';
import * as classic from './classic-flow';
import { layoutProblems } from './layout-checks';
import { NUMBERS, PRODUCTS, resetState, visitorIpIn } from './helpers';

test.skip( ! process.env.VISUAL_QA, 'Set VISUAL_QA=1 to render every VAT checkout state at 390, 768 and 1440 wide.' );

const OUT = process.env.VISUAL_QA_OUT || path.join( os.homedir(), 'code/artifacts/vatright/qa' );
const WIDTHS = [ { width: 390, height: 844 }, { width: 768, height: 1024 }, { width: 1440, height: 900 } ];

interface Flow {
	name: string;
	scope: string;
	open: ( page: Page, product: number ) => Promise< void >;
	fill: ( page: Page, customer: { country: string; postcode: string; vat?: string } ) => Promise< void >;
	notice: ( page: Page ) => ReturnType< Page[ 'locator' ] >;
	certification: ( page: Page ) => ReturnType< Page[ 'locator' ] >;
	confirm: ( page: Page, box: ReturnType< Page[ 'locator' ] > ) => Promise< void >;
}

const flows: Flow[] = [
	{
		name: 'block',
		scope: '.vatright-notice, .wc-block-checkout__contact-fields',
		open: block.openCheckout,
		fill: block.fillCustomer,
		notice: block.notice,
		certification: ( page ) => page.getByRole( 'checkbox', { name: /I confirm I am located/ } ).filter( { visible: true } ),
		confirm: async ( page, box ) => {
			await box.click();
			await expect( box ).toBeChecked();
			await block.settle( page );
		},
	},
	{
		name: 'classic',
		scope: '.vatright-notice, #billing_vatright_number_field',
		open: classic.openCheckout,
		fill: classic.fillCustomer,
		notice: classic.notice,
		certification: ( page ) => page.getByRole( 'checkbox', { name: /I confirm I am located/ } ),
		confirm: async ( page, box ) => {
			await classic.updated( page, () => box.check() );
			await expect( box ).toBeChecked();
		},
	},
];

const states: Record<
	string,
	( flow: Flow, page: Page, context: BrowserContext ) => Promise< void >
> = {
	'no-number': async ( flow, page ) => {
		await flow.open( page, PRODUCTS.book );
		await flow.fill( page, { country: 'DE', postcode: '10115' } );
	},
	verified: async ( flow, page ) => {
		await flow.open( page, PRODUCTS.book );
		await flow.fill( page, { country: 'DE', postcode: '10115', vat: NUMBERS.validDe } );
		await expect( flow.notice( page ) ).toHaveText( 'VAT number verified. Reverse charge applies.' );
	},
	invalid: async ( flow, page ) => {
		await flow.open( page, PRODUCTS.book );
		await flow.fill( page, { country: 'DE', postcode: '10115', vat: NUMBERS.invalidDe } );
		await expect( flow.notice( page ) ).toContainText( 'not valid' );
	},
	outage: async ( flow, page ) => {
		await flow.open( page, PRODUCTS.book );
		await flow.fill( page, { country: 'DE', postcode: '10115', vat: NUMBERS.outageDe } );
		await expect( flow.notice( page ) ).toContainText( 'could not be checked' );
	},
	'self-certification-open': async ( flow, page, context ) => {
		await visitorIpIn( context, 'FR' );
		await flow.open( page, PRODUCTS.ebook );
		await flow.fill( page, { country: 'DE', postcode: '10115' } );
		await expect( flow.certification( page ) ).toBeVisible();
	},
	'self-certification-checked': async ( flow, page, context ) => {
		await visitorIpIn( context, 'FR' );
		await flow.open( page, PRODUCTS.ebook );
		await flow.fill( page, { country: 'DE', postcode: '10115' } );
		await flow.confirm( page, flow.certification( page ) );
	},
	'northern-ireland': async ( flow, page ) => {
		await flow.open( page, PRODUCTS.book );
		await flow.fill( page, { country: 'GB', postcode: 'BT1 1AA', vat: NUMBERS.validXi } );
		await expect( flow.notice( page ) ).toHaveText( 'VAT number verified. Reverse charge applies.' );
	},
};

test.beforeAll( () => {
	fs.mkdirSync( OUT, { recursive: true } );
	resetState();
} );

for ( const flow of flows ) {
	for ( const [ state, setup ] of Object.entries( states ) ) {
		test( `${ flow.name } checkout, ${ state }`, async ( { browser } ) => {
			test.setTimeout( 120_000 );

			// One fresh visit per width.
			for ( const size of WIDTHS ) {
				resetState();
				const context = await browser.newContext( { viewport: size, reducedMotion: 'reduce', baseURL: `http://localhost:${ process.env.VATRIGHT_E2E_PORT || '8899' }` } );
				const page = await context.newPage();
				await setup( flow, page, context );
				await page.waitForTimeout( 600 );
				await page.addStyleTag( { content: '*{scroll-behavior:auto!important;animation:none!important;transition:none!important}' } );

				const { problems, checked } = await layoutProblems( page, flow.scope );
				expect( checked, 'the layout checks looked at nothing' ).toBeGreaterThan( 0 );
				expect( problems, `${ flow.name } ${ state } at ${ size.width }px` ).toEqual( [] );

				const targets = [ page.locator( flow.scope ).filter( { visible: true } ).first(), page.locator( flow.scope ).filter( { visible: true } ).last() ];
				for ( const [ index, target ] of targets.entries() ) {
					await target.scrollIntoViewIfNeeded();
					await page.screenshot( { path: path.join( OUT, `${ flow.name }-${ state }-${ size.width }-${ index }.png` ), animations: 'disabled' } );
				}
				await context.close();
			}
		} );
	}
}

test( 'the layout checks fail on a known-bad page', async ( { page } ) => {
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	await page.setViewportSize( WIDTHS[ 0 ] );
	resetState();
	await states.verified( flows[ 0 ], page, page.context() );
	await page.addStyleTag( { content: '.vatright-notice{width:1400px!important}.vatright-notice__message{color:#ddd!important}' } );

	const { problems } = await layoutProblems( page, flows[ 0 ].scope );

	expect( problems.join( '\n' ) ).toContain( 'horizontal scroll' );
	expect( problems.join( '\n' ) ).toContain( 'low contrast' );
	expect( problems.join( '\n' ) ).toContain( 'sticks out of the viewport' );
} );
