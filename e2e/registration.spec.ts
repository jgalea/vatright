import { test, expect, type Page } from '@playwright/test';
import { NUMBERS, registryCalls, resetState, setSettings, wp, wpEval } from './helpers';

const EMAIL = 'newcustomer@example.com';
const vatInput = ( page: Page ) => page.locator( '#vatright_registration_number' );

const savedNumber = (): string => wpEval( `$u = get_user_by( "email", "${ EMAIL }" ); echo "@@" . wp_json_encode( $u ? (string) get_user_meta( $u->ID, "_vatright_number", true ) : null ) . "@@";` );

test.beforeAll( () => {
	wp( 'option', 'update', 'woocommerce_enable_myaccount_registration', 'yes' );
	wp( 'option', 'update', 'woocommerce_registration_generate_password', 'no' );
} );

test.beforeEach( () => {
	resetState();
	wpEval( `$u = get_user_by( "email", "${ EMAIL }" ); if ( $u ) { require_once ABSPATH . "wp-admin/includes/user.php"; wp_delete_user( $u->ID ); } echo "@@1@@";` );
} );

async function register( page: Page, number: string ) {
	await page.goto( '/my-account/' );
	await page.locator( '#reg_email' ).fill( EMAIL );
	await page.locator( '#reg_password' ).fill( 'a-long-test-password-1' );
	if ( number ) {
		await vatInput( page ).fill( number );
	}
	// With the password field still focused the first click on Register only blurs it.
	await page.waitForTimeout( 1000 );
	await page.locator( '#reg_email' ).focus();
	await page.getByRole( 'button', { name: 'Register' } ).click();
}

test( 'the registration form has no VAT field until the setting is on', async ( { page } ) => {
	await page.goto( '/my-account/' );
	await expect( page.locator( '#reg_email' ) ).toBeVisible();
	await expect( vatInput( page ) ).toHaveCount( 0 );
} );

test.describe( 'with the registration field on', () => {
	test.beforeEach( () => setSettings( { registration_vat_field: true } ) );

	test( 'the field is optional and says where the number goes', async ( { page } ) => {
		await page.goto( '/my-account/' );
		await expect( vatInput( page ) ).toBeVisible();
		await expect( vatInput( page ) ).not.toHaveAttribute( 'required', /.*/ );
		await expect( page.locator( '#vatright-registration-disclosure' ) ).toContainText( 'official tax registry' );
		await page.screenshot( { path: process.env.HOME + '/code/artifacts/vatright/features/accounts/registration-form.png', fullPage: true } );
	} );

	test( 'a valid number is checked by the registry and saved to the new account', async ( { page } ) => {
		await register( page, 'de 114.103.379' );
		await expect( page.locator( '.woocommerce-MyAccount-navigation' ) ).toBeVisible();
		expect( savedNumber() ).toBe( NUMBERS.validDe );
		expect( registryCalls() ).toBe( 1 );
	} );

	test( 'an invalid number shows an error and creates no account', async ( { page } ) => {
		await register( page, NUMBERS.invalidDe );
		await expect( page.getByRole( 'alert' ).filter( { hasText: 'This VAT number is not valid' } ) ).toBeVisible();
		await expect( vatInput( page ) ).toHaveValue( NUMBERS.invalidDe );
		expect( savedNumber() ).toBeNull();
		await page.screenshot( { path: process.env.HOME + '/code/artifacts/vatright/features/accounts/registration-invalid.png', fullPage: true } );
	} );

	test( 'text that is not a VAT number is refused', async ( { page } ) => {
		await register( page, 'hello' );
		await expect( page.getByRole( 'alert' ).filter( { hasText: 'does not look like a VAT number' } ) ).toBeVisible();
		expect( savedNumber() ).toBeNull();
	} );

	test( 'an unavailable registry does not stop registration and the number is kept', async ( { page } ) => {
		await register( page, NUMBERS.outageDe );
		await expect( page.locator( '.woocommerce-MyAccount-navigation' ) ).toBeVisible();
		expect( savedNumber() ).toBe( NUMBERS.outageDe );
		expect( registryCalls() ).toBe( 1 );
	} );

	test( 'leaving the field empty registers without a lookup', async ( { page } ) => {
		await register( page, '' );
		await expect( page.locator( '.woocommerce-MyAccount-navigation' ) ).toBeVisible();
		expect( savedNumber() ).toBe( '' );
		expect( registryCalls() ).toBe( 0 );
	} );
} );
