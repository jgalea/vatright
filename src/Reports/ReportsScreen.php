<?php

namespace Vatright\Reports;

class ReportsScreen {

	const SLUG       = 'vatright-reports';
	const CAPABILITY = 'view_woocommerce_reports';
	const NONCE      = 'vatright_export';

	/** @var OssReport */
	private $oss;

	/** @var EcSalesList */
	private $esl;

	/** @var IntrastatReport */
	private $intrastat;

	public function __construct( OssReport $oss, EcSalesList $esl, IntrastatReport $intrastat ) {
		$this->oss       = $oss;
		$this->esl       = $esl;
		$this->intrastat = $intrastat;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_vatright_export_oss', array( $this, 'export_oss' ) );
		add_action( 'admin_post_vatright_export_esl', array( $this, 'export_esl' ) );
		add_action( 'admin_post_vatright_export_intrastat', array( $this, 'export_intrastat' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'EU VAT reports', 'vatright' ),
			__( 'EU VAT reports', 'vatright' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		list( $year, $quarter ) = $this->selection( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$missing                = array();
		$oss                    = $this->oss->build( $year, $quarter );
		$esl                    = $this->esl_or_null( $year, $quarter, $missing );
		$month                  = $this->month( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$intrastat_orders       = array();
		$intrastat              = $this->intrastat_or_null( $year, $month, $intrastat_orders );
		$this_year              = (int) wp_date( 'Y' );
		?>
		<div class="wrap woocommerce">
			<h1><?php esc_html_e( 'EU VAT reports', 'vatright' ); ?></h1>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<select name="year">
					<?php for ( $y = $this_year; $y >= $this_year - 5; $y-- ) : ?>
						<option value="<?php echo esc_attr( (string) $y ); ?>" <?php selected( $y, $year ); ?>><?php echo esc_html( (string) $y ); ?></option>
					<?php endfor; ?>
				</select>
				<select name="quarter">
					<?php for ( $q = 1; $q <= 4; $q++ ) : ?>
						<option value="<?php echo esc_attr( (string) $q ); ?>" <?php selected( $q, $quarter ); ?>><?php echo esc_html( 'Q' . $q ); ?></option>
					<?php endfor; ?>
				</select>
				<select name="month" aria-label="<?php esc_attr_e( 'Month for INTRASTAT', 'vatright' ); ?>">
					<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
						<option value="<?php echo esc_attr( (string) $m ); ?>" <?php selected( $m, $month ); ?>><?php echo esc_html( (string) wp_date( 'F', (int) gmmktime( 0, 0, 0, $m, 1, 2000 ) ) ); ?></option>
					<?php endfor; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Show', 'vatright' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'OSS return', 'vatright' ); ?></h2>
			<p><?php echo esc_html( sprintf( /* translators: %s: conversion rule. */ __( 'Non-euro orders are converted at the %s.', 'vatright' ), $oss['rate_rule'] ) ); ?></p>
			<?php $this->oss_table( $oss ); ?>
			<?php $this->export_form( 'vatright_export_oss', $year, $quarter, __( 'Download OSS CSV', 'vatright' ) ); ?>

			<h2><?php esc_html_e( 'EC Sales List', 'vatright' ); ?></h2>
			<?php if ( null === $esl ) : ?>
				<div class="notice notice-error inline"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: order numbers. */
							__( 'No ECB rate was available for these orders, so the list is not shown: %s', 'vatright' ),
							implode( ', ', $missing )
						)
					);
					?>
				</p></div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'VAT number', 'vatright' ); ?></th>
							<th><?php esc_html_e( 'Country', 'vatright' ); ?></th>
							<th><?php esc_html_e( 'Goods or services', 'vatright' ); ?></th>
							<th><?php esc_html_e( 'Net amount (EUR)', 'vatright' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $esl as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['customer_vat'] ); ?></td>
								<td><?php echo esc_html( $row['country'] ); ?></td>
								<td><?php echo esc_html( $row['type'] ); ?></td>
								<td><?php echo esc_html( number_format( $row['net_eur'], 2, '.', '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( array() === $esl ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No intra-EU B2B sales in this period.', 'vatright' ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
				<?php $this->export_form( 'vatright_export_esl', $year, $quarter, __( 'Download EC Sales List CSV', 'vatright' ) ); ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'INTRASTAT dispatches', 'vatright' ); ?></h2>
			<p><?php echo esc_html( sprintf( /* translators: %s: month and year. */ __( 'Physical goods dispatched to other member states in %s.', 'vatright' ), (string) wp_date( 'F Y', (int) gmmktime( 0, 0, 0, $month, 1, $year ) ) ) ); ?></p>
			<p class="description"><?php echo esc_html( self::intrastat_note() ); ?></p>
			<?php if ( null === $intrastat ) : ?>
				<div class="notice notice-error inline"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: order numbers. */
							__( 'No ECB rate was available for these orders, so the INTRASTAT report is not shown: %s', 'vatright' ),
							implode( ', ', $intrastat_orders )
						)
					);
					?>
				</p></div>
			<?php else : ?>
				<?php $this->intrastat_table( $intrastat ); ?>
				<?php $this->export_form( 'vatright_export_intrastat', $year, $quarter, __( 'Download INTRASTAT CSV', 'vatright' ), $month ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function export_oss(): void {
		list( $year, $quarter ) = $this->authorised_selection();
		$csv                    = OssReport::csv( $this->oss->build( $year, $quarter ) );
		Csv::send( sprintf( 'oss-%d-q%d.csv', $year, $quarter ), $csv[0], $csv[1] );
	}

	public function export_esl(): void {
		list( $year, $quarter ) = $this->authorised_selection();
		try {
			$rows = $this->esl->build( $year, $quarter );
		} catch ( IncompleteReport $e ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: order numbers. */
						__( 'The EC Sales List cannot be exported: no ECB rate was available for orders %s.', 'vatright' ),
						implode( ', ', $e->order_ids() )
					)
				)
			);
		}
		$csv = EcSalesList::csv( $rows );
		Csv::send( sprintf( 'ec-sales-list-%d-q%d.csv', $year, $quarter ), $csv[0], $csv[1] );
	}

	public function export_intrastat(): void {
		list( $year ) = $this->authorised_selection();
		$month        = $this->month( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorised_selection() checked the nonce.
		try {
			$report = $this->intrastat->build( $year, $month );
		} catch ( IncompleteReport $e ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: order numbers. */
						__( 'The INTRASTAT report cannot be exported: no ECB rate was available for orders %s.', 'vatright' ),
						implode( ', ', $e->order_ids() )
					)
				)
			);
		}
		$csv = IntrastatReport::csv( $report );
		Csv::send( sprintf( 'intrastat-%s.csv', $report['period'] ), $csv[0], $csv[1] );
	}

	public static function intrastat_note(): string {
		return __( 'INTRASTAT covers physical goods only, never digital goods or services. Whether you have to file, the thresholds, the deadlines and the file format are set by your member state, and they differ between member states, so this CSV holds the data to file, not the filing itself. Since 2022 a dispatch declaration also carries the partner\'s VAT number and the country of origin.', 'vatright' );
	}

	/**
	 * @param int[] $missing
	 * @return array<int,array{customer_vat:string,country:string,type:string,net_eur:float}>|null
	 */
	private function esl_or_null( int $year, int $quarter, array &$missing ): ?array {
		try {
			return $this->esl->build( $year, $quarter );
		} catch ( IncompleteReport $e ) {
			$missing = $e->order_ids();
			return null;
		}
	}

	/**
	 * @param int[] $order_ids
	 * @return array{year:int,month:int,period:string,rows:array<int,array<string,mixed>>,missing:array<int,array<string,mixed>>}|null
	 */
	private function intrastat_or_null( int $year, int $month, array &$order_ids ): ?array {
		try {
			return $this->intrastat->build( $year, $month );
		} catch ( IncompleteReport $e ) {
			$order_ids = $e->order_ids();
			return null;
		}
	}

	/**
	 * The quarter chosen without a month shows the last month of that quarter.
	 *
	 * @param array<string,mixed> $source
	 */
	private function month( array $source ): int {
		if ( isset( $source['month'] ) ) {
			return max( 1, min( 12, absint( $source['month'] ) ) );
		}
		return isset( $source['quarter'] ) ? max( 1, min( 4, absint( $source['quarter'] ) ) ) * 3 : (int) wp_date( 'n' );
	}

	/**
	 * @return array{0:int,1:int}
	 */
	private function authorised_selection(): array {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export these reports.', 'vatright' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
		return $this->selection( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * @param array<string,mixed> $source
	 * @return array{0:int,1:int}
	 */
	private function selection( array $source ): array {
		$year    = isset( $source['year'] ) ? absint( $source['year'] ) : (int) wp_date( 'Y' );
		$quarter = isset( $source['quarter'] ) ? absint( $source['quarter'] ) : (int) ceil( (int) wp_date( 'n' ) / 3 );
		return array( max( 2000, min( 2100, $year ) ), max( 1, min( 4, $quarter ) ) );
	}

	/**
	 * @param array<string,mixed> $oss
	 */
	private function oss_table( array $oss ): void {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Member state', 'vatright' ); ?></th>
					<th><?php esc_html_e( 'VAT rate %', 'vatright' ); ?></th>
					<th><?php esc_html_e( 'Supply type', 'vatright' ); ?></th>
					<th><?php echo esc_html( sprintf( /* translators: %s: currency code. */ __( 'Taxable amount (%s)', 'vatright' ), $oss['currency'] ) ); ?></th>
					<th><?php echo esc_html( sprintf( /* translators: %s: currency code. */ __( 'VAT amount (%s)', 'vatright' ), $oss['currency'] ) ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $oss['rows'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['member_state'] ); ?></td>
						<td><?php echo esc_html( (string) $row['rate_percent'] ); ?></td>
						<td><?php echo esc_html( $row['supply_type'] ); ?></td>
						<td><?php echo esc_html( number_format( $row['taxable'], 2, '.', '' ) ); ?></td>
						<td><?php echo esc_html( number_format( $row['vat'], 2, '.', '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( array() === $oss['rows'] ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No OSS sales in this period.', 'vatright' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php foreach ( $oss['rates'] as $rate ) : ?>
			<?php if ( 'EUR' !== $rate['currency'] ) : ?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: currency, 2: rate, 3: date, 4: period. */
							__( '%1$s: %2$s per EUR on %3$s (%4$s).', 'vatright' ),
							$rate['currency'],
							$rate['rate'],
							$rate['rate_date'],
							$rate['period']
						)
					);
					?>
				</p>
			<?php endif; ?>
		<?php endforeach; ?>
		<?php if ( array() !== $oss['needs_correction'] ) : ?>
			<h3><?php echo esc_html( OssReport::needs_correction_title() ); ?></h3>
			<p><?php esc_html_e( 'Your tax tables had no rate for these destinations, so checkout charged your own country\'s rate. That VAT is not the destination VAT these sales owe, so they are left out of the figures above. Decide with your adviser how to declare each one, then add the missing rates.', 'vatright' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order', 'vatright' ); ?></th>
						<th><?php esc_html_e( 'Member state', 'vatright' ); ?></th>
						<th><?php esc_html_e( 'Rate charged %', 'vatright' ); ?></th>
						<th><?php esc_html_e( 'Supply type', 'vatright' ); ?></th>
						<th><?php echo esc_html( sprintf( /* translators: %s: currency code. */ __( 'Taxable amount (%s)', 'vatright' ), $oss['currency'] ) ); ?></th>
						<th><?php echo esc_html( sprintf( /* translators: %s: currency code. */ __( 'VAT charged (%s)', 'vatright' ), $oss['currency'] ) ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $oss['needs_correction'] as $row ) : ?>
						<?php $order = wc_get_order( $row['order_id'] ); ?>
						<tr>
							<td>
								<?php if ( $order instanceof \WC_Order ) : ?>
									<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
								<?php else : ?>
									<?php echo esc_html( (string) $row['order_id'] ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['member_state'] ); ?></td>
							<td><?php echo esc_html( (string) $row['rate_percent'] ); ?></td>
							<td><?php echo esc_html( $row['supply_type'] ); ?></td>
							<td><?php echo esc_html( number_format( $row['taxable'], 2, '.', '' ) ); ?></td>
							<td><?php echo esc_html( number_format( $row['vat'], 2, '.', '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php if ( array() !== $oss['corrections'] ) : ?>
			<p><?php esc_html_e( 'Refunds booked in this period against earlier periods are listed in the CSV so those returns can be amended.', 'vatright' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array{year:int,month:int,period:string,rows:array<int,array<string,mixed>>,missing:array<int,array<string,mixed>>} $report
	 */
	private function intrastat_table( array $report ): void {
		if ( array() !== $report['missing'] ) :
			?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'These products are missing data an INTRASTAT declaration needs:', 'vatright' ); ?>
				<?php foreach ( $report['missing'] as $index => $product ) : ?>
					<?php echo 0 === $index ? '' : ', '; ?>
					<?php if ( $product['product_id'] > 0 ) : ?>
						<a href="<?php echo esc_url( (string) get_edit_post_link( $product['product_id'] ) ); ?>"><?php echo esc_html( $product['name'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $product['name'] ); ?>
					<?php endif; ?>
					(<?php echo esc_html( implode( ', ', array_map( array( self::class, 'field_label' ), $product['fields'] ) ) ); ?>)
				<?php endforeach; ?>
			</p></div>
		<?php endif; ?>
		<table class="widefat striped vatright-intrastat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Partner member state', 'vatright' ); ?></th>
					<th><?php esc_html_e( 'CN8 commodity code', 'vatright' ); ?></th>
					<th><?php esc_html_e( 'Nature of transaction', 'vatright' ); ?></th>
					<th><?php esc_html_e( 'Country of origin', 'vatright' ); ?></th>
					<th><?php esc_html_e( 'Partner VAT number', 'vatright' ); ?></th>
					<th><?php esc_html_e( 'Net mass (kg)', 'vatright' ); ?></th>
					<th><?php esc_html_e( 'Invoice value (EUR)', 'vatright' ); ?></th>
					<th><?php esc_html_e( 'Supplementary units', 'vatright' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php list( , $lines ) = IntrastatReport::csv( $report ); ?>
				<?php foreach ( $lines as $line ) : ?>
					<tr>
						<?php foreach ( $line as $cell ) : ?>
							<td><?php echo esc_html( (string) $cell ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				<?php if ( array() === $lines ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No dispatches to other member states in this month.', 'vatright' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private static function field_label( string $field ): string {
		$labels = array(
			'cn8'      => __( 'CN8 code', 'vatright' ),
			'origin'   => __( 'country of origin', 'vatright' ),
			'net_mass' => __( 'net mass', 'vatright' ),
		);
		return $labels[ $field ] ?? $field;
	}

	private function export_form( string $action, int $year, int $quarter, string $label, ?int $month = null ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="year" value="<?php echo esc_attr( (string) $year ); ?>" />
			<input type="hidden" name="quarter" value="<?php echo esc_attr( (string) $quarter ); ?>" />
			<?php if ( null !== $month ) : ?>
				<input type="hidden" name="month" value="<?php echo esc_attr( (string) $month ); ?>" />
			<?php endif; ?>
			<p><button type="submit" class="button"><?php echo esc_html( $label ); ?></button></p>
		</form>
		<?php
	}
}
