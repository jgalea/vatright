import { execFileSync } from 'node:child_process';
import { expect, type BrowserContext, type Page } from '@playwright/test';

export const NUMBERS = {
	validDe: 'DE114103379',
	invalidDe: 'DE123475223',
	outageDe: 'DE129273398',
	validXi: 'XI980780684',
	validEs: 'ESA15075062',
	validNl: 'NL820646660B01',
};

export const PRODUCTS = { book: 10, ebook: 11, consulting: 12 };

export const DECISION_PHP = `
$order = wc_get_order( %ID% );
echo "@@" . wp_json_encode( array(
	'decision'   => json_decode( (string) $order->get_meta( '_vatright_decision' ), true ),
	'exempt'     => $order->get_meta( 'is_vat_exempt' ),
	'number'     => $order->get_meta( '_vatright_number' ),
	'total_tax'  => $order->get_total_tax(),
	'log'        => count( $order->get_meta( '_vatright_log', false ) ),
) ) . "@@";
`;

export function wp( ...args: string[] ): string {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] } );
}

export function wpEval( php: string ): any {
	const out = wp( 'eval', php );
	const match = out.match( /@@([\s\S]*?)@@/ );
	if ( ! match ) {
		throw new Error( 'wp eval returned no marker: ' + out );
	}
	return JSON.parse( match[ 1 ] );
}

export function orderRecord( orderId: number ) {
	return wpEval( DECISION_PHP.replace( '%ID%', String( orderId ) ) );
}

export function orderCount(): number {
	return wpEval( 'echo "@@" . count( wc_get_orders( array( "limit" => -1, "return" => "ids" ) ) ) . "@@";' );
}

export function setSettings( settings: Record< string, unknown > ) {
	wp( 'option', 'update', 'vatright_settings', JSON.stringify( settings ), '--format=json' );
}

export function resetState() {
	const out = wp( 'eval-file', 'wp-content/plugins/vatright/e2e/reset.php' );
	expect( out ).toContain( '@@1@@' );
}

export function registryCalls(): number {
	return wpEval( 'echo "@@" . (int) get_option( "vatright_e2e_vies_calls", 0 ) . "@@";' );
}

export async function visitorIpIn( context: BrowserContext, country: string ) {
	await context.addCookies( [ { name: 'vatright_e2e_ip_country', value: country, url: `http://localhost:${ process.env.VATRIGHT_E2E_PORT || '8899' }` } ] );
}

export async function addToCart( page: Page, productId: number ) {
	await page.goto( `/?add-to-cart=${ productId }` );
}

export function orderIdFromUrl( url: string ): number {
	const match = url.match( /order-received\/(\d+)/ );
	expect( match, 'not on the order received page: ' + url ).not.toBeNull();
	return Number( match![ 1 ] );
}

export interface Customer {
	country: string;
	postcode: string;
	vat?: string;
	state?: string;
}

export const TEST_CUSTOMER = {
	email: 'ada@example.com',
	first: 'Ada',
	last: 'Lovelace',
	address: '1 Test Street',
	city: 'Testville',
	phone: '123456789',
};
