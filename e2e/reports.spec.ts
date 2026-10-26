import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { wp } from './helpers';

const SCREEN = '/wp-admin/admin.php?page=vatright-reports';
const SHOTS = process.env.VATRIGHT_SHOTS ?? '';

test.beforeAll( () => {
	expect( wp( 'eval-file', 'wp-content/plugins/vatright/e2e/reports-seed.php' ) ).toContain( '@@1@@' );
} );

test.afterAll( () => {
	wp( 'eval', 'global $wpdb; $wpdb->query( "DELETE FROM " . Vatright\\Ledger\\Schema::ledger_table() . " WHERE rule = \'e2e\'" );' );
} );

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await Promise.all( [ page.waitForURL( ( url ) => ! url.pathname.includes( 'wp-login' ) ), page.locator( '#wp-submit' ).click() ] );
}

async function shot( page: Page, name: string ) {
	if ( SHOTS ) {
		await page.screenshot( { path: `${ SHOTS }/${ name }.png` } );
	}
}

test( 'the period picker offers a quarter, a month and a custom range', async ( { page } ) => {
	await login( page );
	await page.goto( SCREEN );

	const kind = page.locator( '#vatright-period' );
	await expect( kind ).toHaveValue( 'quarter' );
	await expect( page.locator( '#vatright-quarter' ) ).toBeVisible();
	await expect( page.locator( '#vatright-from' ) ).toBeHidden();

	await kind.selectOption( 'month' );
	await expect( page.locator( '#vatright-month' ) ).toBeVisible();
	await expect( page.locator( '#vatright-quarter' ) ).toBeHidden();

	await page.locator( '#vatright-year' ).selectOption( '2026' );
	await page.locator( '#vatright-month' ).selectOption( '8' );
	await Promise.all( [ page.waitForURL( /period=month/ ), page.getByRole( 'button', { name: 'Show', exact: true } ).click() ] );
	await expect( page.getByText( 'Sales made from 2026-08-01 to 2026-08-31.' ) ).toBeVisible();
	const oss = page.locator( 'table' ).first();
	await expect( oss ).toContainText( 'FR' );
	await expect( oss ).toContainText( '200.00' );
	await expect( oss ).not.toContainText( '100.00' );
	await shot( page, 'reports-month' );

	await page.locator( '#vatright-period' ).selectOption( 'range' );
	await expect( page.locator( '#vatright-from' ) ).toBeVisible();
	await expect( page.locator( '#vatright-month' ) ).toBeHidden();
	await page.locator( '#vatright-from' ).fill( '2026-07-20' );
	await page.locator( '#vatright-to' ).fill( '2026-08-25' );
	await Promise.all( [ page.waitForURL( /period=range/ ), page.getByRole( 'button', { name: 'Show', exact: true } ).click() ] );
	await expect( page.getByText( 'Sales made from 2026-07-20 to 2026-08-25.' ) ).toBeVisible();
	const range = page.locator( 'table' ).first();
	await expect( range ).toContainText( '200.00' );
	await expect( range ).toContainText( '50.00' );
	await expect( range ).not.toContainText( '100.00' );
	await shot( page, 'reports-range' );
} );

test( 'the CSV export covers the chosen range and is named after it', async ( { page } ) => {
	await login( page );
	await page.goto( `${ SCREEN }&period=range&from=2026-07-20&to=2026-08-25` );

	const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.getByRole( 'button', { name: 'Download OSS CSV' } ).click() ] );
	expect( download.suggestedFilename() ).toBe( 'oss-2026-07-20-to-2026-08-25.csv' );
	const csv = readFileSync( ( await download.path() ) as string, 'utf8' );
	expect( csv ).toContain( 'DE,19,services,50.00,9.50' );
	expect( csv ).toContain( 'FR,20,services,200.00,40.00' );
	expect( csv ).not.toContain( '100.00' );
} );

test( 'the quarter view and its export are unchanged', async ( { page } ) => {
	await login( page );
	await page.goto( `${ SCREEN }&year=2026&quarter=3` );

	await expect( page.locator( '#vatright-period' ) ).toHaveValue( 'quarter' );
	await expect( page.locator( 'table' ).first() ).toContainText( '150.00' );
	const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.getByRole( 'button', { name: 'Download OSS CSV' } ).click() ] );
	expect( download.suggestedFilename() ).toBe( 'oss-2026-q3.csv' );
} );

test( 'the REST endpoint takes the same range', async ( { page } ) => {
	await login( page );
	await page.goto( SCREEN );
	const nonce = await page.evaluate( async () => {
		const res = await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' } );
		return res.text();
	} );
	const res = await page.request.get( `/?rest_route=/vatright/v1/reports/oss&from=2026-08-01&to=2026-08-10&_wpnonce=${ nonce }` );
	expect( res.status() ).toBe( 200 );
	const body = await res.json();
	expect( body.period ).toBe( '2026-08-01_2026-08-10' );
	expect( body.rows.map( ( r: { member_state: string } ) => r.member_state ) ).toEqual( [ 'FR' ] );
} );
