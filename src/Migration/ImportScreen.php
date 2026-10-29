<?php

namespace Vatright\Migration;

use Vatright\Admin\SettingsSection;

/**
 * The import on the VAT Right settings screen: what another VAT plugin left
 * behind, counted before anything changes, and a button that starts the
 * import in the background.
 */
class ImportScreen {

	const ACTION = 'vatright_import';

	/** @var Importer */
	private $importer;

	public function __construct( Importer $importer ) {
		$this->importer = $importer;
	}

	public function register(): void {
		add_action( 'woocommerce_after_settings_tax', array( $this, 'render' ), 5 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function render(): void {
		global $current_section;
		if ( SettingsSection::SECTION !== $current_section || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return;
		}
		$state = Importer::state();
		$dry   = get_transient( Importer::DRY_RUN_CACHE );
		if ( ! is_array( $dry ) || 'running' === ( $state['status'] ?? '' ) ) {
			$dry = $this->importer->dry_run();
			set_transient( Importer::DRY_RUN_CACHE, $dry, 5 * MINUTE_IN_SECONDS );
		}
		if ( array() === $dry['sources'] && 0 === $dry['customers'] && array() === $dry['settings'] && array() === $state ) {
			return;
		}

		echo '<div class="vatright-import"><h2>' . esc_html__( 'Import from another VAT plugin', 'vatright' ) . '</h2>';
		echo '<p>' . esc_html__( 'VAT numbers another VAT plugin saved on orders and customers, copied into VAT Right so invoices, reports and checkout keep them. Numbers VAT Right already holds are kept, and the other plugin\'s data is left as it is.', 'vatright' ) . '</p>';

		if ( 'running' === ( $state['status'] ?? '' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html(
				sprintf(
					/* translators: 1: number of orders, 2: number of customers */
					__( 'Import running: %1$d orders and %2$d customers imported so far.', 'vatright' ),
					(int) ( $state['orders'] ?? 0 ),
					(int) ( $state['customers'] ?? 0 )
				)
			) . '</p></div>';
		} elseif ( 'done' === ( $state['status'] ?? '' ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html(
				sprintf(
					/* translators: 1: number of orders, 2: number of customers, 3: number of settings */
					__( 'Last import: %1$d orders, %2$d customers and %3$d settings.', 'vatright' ),
					(int) ( $state['orders'] ?? 0 ),
					(int) ( $state['customers'] ?? 0 ),
					count( (array) ( $state['settings'] ?? array() ) )
				)
			) . '</p></div>';
		}

		echo '<table class="widefat striped" style="max-width:800px"><thead><tr><th>' . esc_html__( 'Found', 'vatright' ) . '</th><th>' . esc_html__( 'With a VAT number', 'vatright' ) . '</th><th>' . esc_html__( 'To import', 'vatright' ) . '</th></tr></thead><tbody>';
		foreach ( $dry['sources'] as $source ) {
			$name = $source['active'] ? $source['name'] : sprintf(
				/* translators: %s: plugin name */
				__( '%s (inactive)', 'vatright' ),
				$source['name']
			);
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
				esc_html(
					sprintf(
						/* translators: %s: plugin name */
						__( 'Orders from %s', 'vatright' ),
						$name
					)
				),
				esc_html( number_format_i18n( $source['orders'] ) ),
				esc_html( number_format_i18n( $source['orders_to_import'] ) )
			);
		}
		printf(
			'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
			esc_html__( 'Customers', 'vatright' ),
			esc_html( number_format_i18n( $dry['customers'] ) ),
			esc_html( number_format_i18n( $dry['customers_to_import'] ) )
		);
		foreach ( $dry['settings'] as $key => $value ) {
			printf(
				'<tr><td>%1$s</td><td colspan="2">%2$s</td></tr>',
				esc_html( 'store_vat_number_gb' === $key ? __( 'UK store VAT number', 'vatright' ) : __( 'Store VAT number', 'vatright' ) ),
				esc_html( $value )
			);
		}
		echo '</tbody></table>';

		if ( 'running' !== ( $state['status'] ?? '' ) && $dry['orders_to_import'] + $dry['customers_to_import'] + count( $dry['settings'] ) > 0 ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
			echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Import', 'vatright' ) . '</a></p>';
		}
		echo '</div>';
	}

	public function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'You are not allowed to do this.', 'vatright' ), '', array( 'response' => 403 ) );
		}
		if ( 'running' !== ( Importer::state()['status'] ?? '' ) ) {
			$this->importer->start();
		}
		wp_safe_redirect( SettingsSection::url() );
		exit;
	}
}
