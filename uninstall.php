<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// VAT records must be kept for years: nothing is removed unless the store opted in. Order meta stays with the orders.
$vatright_settings = get_option( 'vatright_settings', array() );

if ( empty( $vatright_settings['delete_data_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

global $wpdb;

\Vatright\Ledger\Schema::drop();

// delete_option() keeps the object cache in step, so no site-wide cache flush is needed.
$vatright_options = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'vatright_' ) . '%',
		$wpdb->esc_like( '_transient_vatright_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_vatright_' ) . '%'
	)
);
foreach ( $vatright_options as $vatright_option ) {
	delete_option( $vatright_option );
}

delete_metadata( 'user', 0, '_vatright_number', '', true );
delete_metadata( 'user', 0, '_wc_other/vatright/vat-number', '', true );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'vatright' );
}
