<?php

namespace Vatright\Migration;

use Vatright\Ledger\Backfill;
use WP_CLI;

class Cli {

	/** @var Importer */
	private $importer;

	public function __construct( Importer $importer ) {
		$this->importer = $importer;
	}

	/**
	 * Imports the VAT numbers and settings another VAT plugin left behind.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Count what would be imported and change nothing.
	 *
	 * [--source=<source>]
	 * : Import from one plugin only.
	 * ---
	 * options:
	 *   - eu-vat-number
	 *   - wpfactory
	 *   - aelia
	 *   - simba
	 * ---
	 *
	 * [--batch=<size>]
	 * : Orders or customers per batch.
	 * ---
	 * default: 100
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp vatright import --dry-run
	 *     wp vatright import --source=eu-vat-number
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$only = isset( $assoc_args['source'] ) ? (string) $assoc_args['source'] : null;
		if ( null !== $only && ! isset( LegacyData::SOURCES[ $only ] ) ) {
			WP_CLI::error( 'Unknown source: ' . $only );
		}
		$batch = max( 1, (int) ( $assoc_args['batch'] ?? Importer::BATCH ) );

		$dry = $this->importer->dry_run( $only );
		foreach ( $dry['sources'] as $source ) {
			WP_CLI::log( sprintf( '%s (%s): %d orders with a VAT number, %d to import.', $source['name'], $source['active'] ? 'active' : 'inactive', $source['orders'], $source['orders_to_import'] ) );
		}
		WP_CLI::log( sprintf( 'Orders: %d with a VAT number, %d to import.', $dry['orders'], $dry['orders_to_import'] ) );
		WP_CLI::log( sprintf( 'Customers: %d with a saved VAT number, %d to import.', $dry['customers'], $dry['customers_to_import'] ) );
		foreach ( $dry['settings'] as $key => $value ) {
			WP_CLI::log( sprintf( 'Setting: %s = %s', $key, $value ) );
		}
		if ( isset( $assoc_args['dry-run'] ) ) {
			WP_CLI::success( 'Dry run: nothing was changed.' );
			return;
		}

		$orders = 0;
		$after  = 0;
		do {
			$result  = $this->importer->import_orders( $after, $batch, $only );
			$orders += $result['imported'];
			$after   = $result['last'];
		} while ( ! $result['done'] );

		$customers = 0;
		$after     = 0;
		do {
			$result     = $this->importer->import_customers( $after, $batch, $only );
			$customers += $result['imported'];
			$after      = $result['last'];
		} while ( ! $result['done'] );

		$settings = $this->importer->import_settings( $only, true );
		Backfill::schedule();
		WP_CLI::success( sprintf( 'Imported %d orders, %d customers and %d settings. The VAT records for imported orders are written in the background.', $orders, $customers, count( $settings ) ) );
	}
}
