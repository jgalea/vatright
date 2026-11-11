<?php

namespace Vatright\Rates;

use WC_Admin_Settings;

class RatesScreen {

	const NONCE = 'vatright_rates';

	/** @var RateSync */
	private $sync;

	public function __construct( RateSync $sync ) {
		$this->sync = $sync;
	}

	public function register(): void {
		add_action( 'admin_post_vatright_rates_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_vatright_rates_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'admin_notices', array( $this, 'pending_notice' ) );
		add_action( 'woocommerce_before_settings_tax', array( $this, 'result_message' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=tax&section=vatright' );
	}

	public function render(): void {
		$diff = $this->sync->plan( $this->sync->latest(), RateMapping::load() );
		?>
		<h2><?php esc_html_e( 'EU VAT rates', 'vatright' ); ?></h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: rate source, 2: date the rates apply on. */
					__( 'Rates from %1$s, valid on %2$s. Nothing changes until you apply it.', 'vatright' ),
					strtoupper( $diff->source() ),
					$diff->source_date()
				)
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="vatright_rates_apply" />
			<div style="overflow-x:auto">
			<table class="widefat striped">
				<thead>
					<tr>
						<th></th>
						<th><?php esc_html_e( 'Country', 'vatright' ); ?></th>
						<th><?php esc_html_e( 'Tax class', 'vatright' ); ?></th>
						<th><?php esc_html_e( 'Current', 'vatright' ); ?></th>
						<th><?php esc_html_e( 'New', 'vatright' ); ?></th>
						<th><?php esc_html_e( 'Status', 'vatright' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $diff->entries() as $entry ) : ?>
						<tr>
							<td>
								<?php if ( RateDiff::UNCHANGED !== $entry['status'] ) : ?>
									<input type="checkbox" name="vatright_rate_keys[]" value="<?php echo esc_attr( $entry['key'] ); ?>" <?php checked( RateDiff::EDITED !== $entry['status'] ); ?> />
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( '' === $entry['postcode'] ? $entry['country'] : $entry['country'] . ' (' . $entry['postcode'] . ')' ); ?></td>
							<td><?php echo esc_html( '' === $entry['class'] ? __( 'Standard', 'vatright' ) : $entry['class'] ); ?></td>
							<td><?php echo null === $entry['current'] ? '&ndash;' : esc_html( $entry['current'] . '%' ); ?></td>
							<td><?php echo esc_html( $entry['rate'] . '%' ); ?></td>
							<td><?php echo esc_html( $this->status_label( $entry['status'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p>
				<label>
					<input type="checkbox" name="vatright_overwrite_edited" value="1" />
					<?php esc_html_e( 'Also overwrite rates I edited (tick the rows first)', 'vatright' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply selected rates', 'vatright' ); ?></button>
				<button type="submit" class="button" name="action" value="vatright_rates_refresh"><?php esc_html_e( 'Fetch latest from EU TEDB', 'vatright' ); ?></button>
			</p>
		</form>
		<?php
	}

	public function handle_apply(): void {
		$this->authorise();
		$keys      = isset( $_POST['vatright_rate_keys'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['vatright_rate_keys'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in authorise().
		$overwrite = ! empty( $_POST['vatright_overwrite_edited'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$diff      = $this->sync->plan( $this->sync->latest(), RateMapping::load() );
		$applied   = $this->sync->apply( $diff, $keys, $overwrite );
		wp_safe_redirect( add_query_arg( 'vatright_applied', $applied, self::url() ) );
		exit;
	}

	public function handle_refresh(): void {
		$this->authorise();
		$ok = $this->sync->refresh();
		wp_safe_redirect( add_query_arg( 'vatright_refreshed', $ok ? '1' : '0', self::url() ) );
		exit;
	}

	/**
	 * Reports the outcome of the apply or refresh that redirected here, in the
	 * spot WooCommerce shows "Your settings have been saved".
	 */
	public function result_message(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect's outcome.
		if ( isset( $_GET['vatright_applied'] ) ) {
			$applied = absint( $_GET['vatright_applied'] );
			WC_Admin_Settings::add_message(
				0 === $applied
					? __( 'No VAT rates were changed. Tick the rows you want to apply.', 'vatright' )
					/* translators: %d: number of tax rates written. */
					: sprintf( _n( '%d VAT rate applied.', '%d VAT rates applied.', $applied, 'vatright' ), $applied )
			);
		}
		if ( isset( $_GET['vatright_refreshed'] ) ) {
			if ( '1' === $_GET['vatright_refreshed'] ) {
				WC_Admin_Settings::add_message( __( 'Fetched the latest rates from EU TEDB.', 'vatright' ) );
			} else {
				WC_Admin_Settings::add_error( __( 'EU TEDB could not be reached, so the rates below haven\'t changed.', 'vatright' ) );
			}
		}
		// phpcs:enable
	}

	public function pending_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
			return;
		}
		$pending = (int) get_option( RateSync::OPTION_PENDING, 0 );
		if ( $pending < 1 ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of rate changes. */
					_n( '%d VAT rate change available.', '%d VAT rate changes available.', $pending, 'vatright' ),
					$pending
				)
			),
			esc_url( self::url() ),
			esc_html__( 'Review', 'vatright' )
		);
	}

	private function authorise(): void {
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'You do not have permission to do this.', 'vatright' ), 403 );
		}
	}

	private function status_label( string $status ): string {
		$labels = array(
			RateDiff::ADD       => __( 'New', 'vatright' ),
			RateDiff::CHANGE    => __( 'Rate changed', 'vatright' ),
			RateDiff::EDITED    => __( 'Edited by you', 'vatright' ),
			RateDiff::UNCHANGED => __( 'Up to date', 'vatright' ),
		);
		return $labels[ $status ];
	}
}
