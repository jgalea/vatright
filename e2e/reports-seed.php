<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- a WP-CLI script, its variables are not globals of the plugin.

global $wpdb;

$table = Vatright\Ledger\Schema::ledger_table();
$wpdb->query( "DELETE FROM {$table} WHERE rule = 'e2e'" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

$sales = array(
	array( 7001, 'DE', 19, 100, '2026-07-15 10:00:00' ),
	array( 7002, 'FR', 20, 200, '2026-08-02 10:00:00' ),
	array( 7003, 'DE', 19, 50, '2026-08-20 09:00:00' ),
);
foreach ( $sales as $sale ) {
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table,
		array(
			'order_id'          => $sale[0],
			'entry_type'        => 'sale',
			'decision_id'       => wp_generate_uuid4(),
			'scheme'            => 'oss_destination',
			'rule'              => 'e2e',
			'supply_type'       => 'digital',
			'customer_country'  => $sale[1],
			'place_of_supply'   => $sale[1],
			'rate_percent'      => $sale[2],
			'net'               => $sale[3],
			'vat'               => $sale[3] * $sale[2] / 100,
			'currency'          => 'EUR',
			'eur_rate'          => 1,
			'net_eur'           => $sale[3],
			'vat_eur'           => $sale[3] * $sale[2] / 100,
			'supply_at'         => $sale[4],
			'period'            => '2026-Q3',
			'relates_to_period' => '2026-Q3',
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
		)
	);
}
echo '@@1@@';
