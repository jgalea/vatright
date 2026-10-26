import { test, expect, type Page } from '@playwright/test';
import { resetState, wp, wpEval } from './helpers';

const SHOTS = process.env.VATRIGHT_SHOTS ?? '';

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}

function seedOrders(): Record< string, number > {
	const out = wp( 'eval-file', 'wp-content/plugins/vatright/e2e/admin-features-seed.php' );
	return JSON.parse( out.match( /@@([\s\S]*?)@@/ )![ 1 ] );
}

function setStorage( hpos: boolean ) {
	wp( 'option', 'update', 'woocommerce_custom_orders_table_enabled', hpos ? 'yes' : 'no' );
}

function clearOrders() {
	wpEval( `
		foreach ( wc_get_orders( array( 'limit' => -1, 'return' => 'ids' ) ) as $id ) {
			wc_get_order( $id )->delete( true );
		}
		echo '@@1@@';
	` );
}

async function shot( page: Page, name: string ) {
	if ( SHOTS ) {
		await page.screenshot( { path: `${ SHOTS }/${ name }.png`, fullPage: false } );
	}
}

for ( const storage of [ 'hpos', 'legacy' ] as const ) {
	test.describe( `orders list VAT column on ${ storage } storage`, () => {
		let ids: Record< string, number >;
		const listUrl = storage === 'hpos' ? '/wp-admin/admin.php?page=wc-orders' : '/wp-admin/edit.php?post_type=shop_order';

		test.beforeEach( async ( { page } ) => {
			resetState();
			setStorage( storage === 'hpos' );
			clearOrders();
			ids = seedOrders();
			await login( page );
		} );

		test.afterEach( () => {
			clearOrders();
			setStorage( true );
		} );

		const row = ( page: Page, id: number ) => page.locator( storage === 'hpos' ? `tr#order-${ id }` : `tr#post-${ id }` );

		test( 'shows the number, its status and the treatment', async ( { page } ) => {
			await page.goto( listUrl );
			await expect( page.locator( 'th#vatright_vat' ) ).toHaveText( 'VAT' );

			const valid = row( page, ids.valid ).locator( 'td.vatright_vat' );
			await expect( valid ).toContainText( 'DE114103379' );
			await expect( valid ).toContainText( 'Valid' );
			await expect( valid ).toContainText( 'Reverse charge' );

			const pending = row( page, ids.pending ).locator( 'td.vatright_vat' );
			await expect( pending ).toContainText( 'Pending validation' );

			const oss = row( page, ids.oss ).locator( 'td.vatright_vat' );
			await expect( oss ).toContainText( 'OSS destination' );

			await expect( row( page, ids.none ).locator( 'td.vatright_vat' ).locator( '.vatright-vat' ) ).toHaveCount( 0 );
			await shot( page, `orders-column-${ storage }` );
		} );

		test( 'the filter narrows the list to orders pending validation', async ( { page } ) => {
			await page.goto( listUrl );
			await page.locator( 'select[name="vatright_vat_filter"]' ).selectOption( 'pending' );
			await page.locator( '#post-query-submit, #order-query-submit' ).click();

			await expect( row( page, ids.pending ) ).toBeVisible();
			await expect( row( page, ids.valid ) ).toHaveCount( 0 );
			await expect( row( page, ids.oss ) ).toHaveCount( 0 );
			await expect( page.locator( 'select[name="vatright_vat_filter"]' ) ).toHaveValue( 'pending' );
			await shot( page, `orders-filter-pending-${ storage }` );
		} );
	} );
}

test.describe( 'OSS threshold email', () => {
	const mails = (): Array< { to: string; subject: string; message: string } > =>
		wpEval( 'echo "@@" . wp_json_encode( array_values( array_filter( get_option( "vatright_e2e_mail_log", array() ), function ( $m ) { return false !== strpos( $m["subject"], "OSS threshold" ); } ) ) ) . "@@";' );

	const addSales = ( net: number ) =>
		wpEval( `
			global $wpdb;
			$year = (int) wp_date( 'Y' );
			$wpdb->insert( Vatright\\Ledger\\Schema::ledger_table(), array(
				'order_id' => 1, 'entry_type' => 'sale', 'decision_id' => 'e2e', 'scheme' => 'oss_destination', 'rule' => 'B2C_DESTINATION',
				'supply_type' => 'goods', 'customer_country' => 'DE', 'place_of_supply' => 'DE', 'rate_percent' => 19,
				'net' => ${ net }, 'vat' => 0, 'currency' => 'EUR', 'eur_rate' => 1, 'net_eur' => ${ net }, 'vat_eur' => 0,
				'supply_at' => $year . '-01-01 00:00:00', 'period' => $year . '-Q1', 'relates_to_period' => $year . '-Q1', 'created_at' => $year . '-01-01 00:00:00',
			) );
			Vatright\\Ledger\\ThresholdTracker::flush( $year, $year - 1 );
			do_action( 'vatright_ledger_recorded', $year );
			echo '@@1@@';
		` );

	test.beforeEach( async ( { page } ) => {
		resetState();
		wpEval( `
			global $wpdb;
			$wpdb->query( "DELETE FROM " . Vatright\\Ledger\\Schema::ledger_table() );
			delete_option( 'vatright_e2e_mail_log' );
			delete_option( 'vatright_threshold_alerts' );
			delete_option( 'woocommerce_vatright_threshold_settings' );
			echo '@@1@@';
		` );
		await login( page );
	} );

	test.afterEach( () => {
		wpEval( 'global $wpdb; $wpdb->query( "DELETE FROM " . Vatright\\Ledger\\Schema::ledger_table() ); delete_option( "vatright_threshold_alerts" ); echo "@@1@@";' );
	} );

	test( 'warns at the configured share, once, and again when the threshold is crossed', async ( { page } ) => {
		addSales( 8100 );
		addSales( 50 );
		let sent = mails();
		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].subject ).toContain( 'at 81%' );
		expect( sent[ 0 ].message ).toContain( '8,100.00' );

		addSales( 2000 );
		sent = mails();
		expect( sent ).toHaveLength( 2 );
		expect( sent[ 1 ].subject ).toContain( 'crossed' );

		addSales( 10 );
		expect( mails() ).toHaveLength( 2 );

		const stored = wpEval( 'echo "@@" . wp_json_encode( get_option( "vatright_threshold_alerts" ) ) . "@@";' );
		expect( Object.values( stored )[ 0 ] ).toHaveProperty( 'crossed' );
	} );

	test( 'the percentage setting is saved and drives the warning', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=tax&section=vatright' );
		const field = page.locator( 'input[name="vatright_settings[threshold_warning_percent]"]' );
		await expect( field ).toHaveValue( '80' );
		await field.fill( '50' );
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expect( field ).toHaveValue( '50' );
		await shot( page, 'settings-threshold-percent' );

		addSales( 5100 );
		const sent = mails();
		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].subject ).toContain( 'at 51%' );
	} );

	test( 'is listed under WooCommerce emails, can be switched off and edited', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=email' );
		await expect( page.getByText( 'OSS threshold warning' ).first() ).toBeVisible();
		await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=email&section=vatright_threshold' );
		await expect( page.locator( '#woocommerce_vatright_threshold_subject' ) ).toBeVisible();
		await shot( page, 'email-settings' );

		await page.locator( '#woocommerce_vatright_threshold_enabled' ).uncheck();
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		addSales( 12000 );
		expect( mails() ).toHaveLength( 0 );
	} );

	test( 'renders the email as WooCommerce previews it', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=email&section=vatright_threshold' );
		const preview = page.locator( 'a', { hasText: /preview/i } ).first();
		if ( ( await preview.count() ) > 0 ) {
			await preview.click();
			await shot( page, 'email-preview' );
		}
		addSales( 8100 );
		const html = mails()[ 0 ].message;
		await page.setContent( html );
		await expect( page.getByText( 'distance-selling threshold' ) ).toBeVisible();
		await shot( page, 'email-rendered' );
	} );
} );
