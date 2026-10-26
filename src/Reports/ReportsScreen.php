<?php

namespace Vatright\Reports;

use DateTimeImmutable;

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
		$period               = $this->selection( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		list( $year, $month ) = $this->intrastat_month( $_GET, $period ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$missing              = array();
		$oss                  = $this->oss->build_for( $period );
		$esl                  = $this->esl_or_null( $period, $missing );
		$intrastat_orders     = array();
		$intrastat            = $this->intrastat_or_null( $year, $month, $intrastat_orders );
		?>
		<div class="wrap woocommerce">
			<h1><?php esc_html_e( 'EU VAT reports', 'vatright' ); ?></h1>
			<?php $this->period_form( $period, $month ); ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: first day, 2: last day, both as Y-m-d. */
						__( 'Sales made from %1$s to %2$s.', 'vatright' ),
						$period->start(),
						$period->end()
					)
				);
				?>
			</p>

			<h2><?php esc_html_e( 'OSS return', 'vatright' ); ?></h2>
			<p><?php echo esc_html( sprintf( /* translators: %s: conversion rule. */ __( 'Non-euro orders are converted at the %s.', 'vatright' ), $oss['rate_rule'] ) ); ?></p>
			<?php $this->oss_table( $oss ); ?>
			<?php $this->export_form( 'vatright_export_oss', $period, __( 'Download OSS CSV', 'vatright' ) ); ?>

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
				<?php $this->export_form( 'vatright_export_esl', $period, __( 'Download EC Sales List CSV', 'vatright' ) ); ?>
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
				<?php $this->export_form( 'vatright_export_intrastat', $period, __( 'Download INTRASTAT CSV', 'vatright' ), $year, $month ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function export_oss(): void {
		$period = $this->authorised_selection();
		$csv    = OssReport::csv( $this->oss->build_for( $period ) );
		Csv::send( sprintf( 'oss-%s.csv', $period->slug() ), $csv[0], $csv[1] );
	}

	public function export_esl(): void {
		$period = $this->authorised_selection();
		try {
			$rows = $this->esl->build_for( $period );
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
		Csv::send( sprintf( 'ec-sales-list-%s.csv', $period->slug() ), $csv[0], $csv[1] );
	}

	public function export_intrastat(): void {
		$period               = $this->authorised_selection();
		list( $year, $month ) = $this->intrastat_month( $_POST, $period ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorised_selection() checked the nonce.
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
	private function esl_or_null( ReportPeriod $period, array &$missing ): ?array {
		try {
			return $this->esl->build_for( $period );
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
	 * The INTRASTAT year and month. A month period uses its own; a custom range
	 * uses the month it starts in; a quarter keeps the month chosen beside it,
	 * or its last month when none was, or the current month when no quarter was
	 * chosen either.
	 *
	 * @param array<string,mixed> $source
	 * @return array{0:int,1:int}
	 */
	private function intrastat_month( array $source, ReportPeriod $period ): array {
		if ( ReportPeriod::RANGE === $period->kind() ) {
			return array( $period->year(), (int) substr( $period->start(), 5, 2 ) );
		}
		if ( ReportPeriod::MONTH === $period->kind() ) {
			return array( $period->year(), (int) substr( $period->start(), 5, 2 ) );
		}
		if ( isset( $source['month'] ) ) {
			return array( $period->year(), max( 1, min( 12, absint( $source['month'] ) ) ) );
		}
		return array( $period->year(), isset( $source['quarter'] ) ? (int) $period->quarter_number() * 3 : (int) wp_date( 'n' ) );
	}

	private function authorised_selection(): ReportPeriod {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export these reports.', 'vatright' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
		return $this->selection( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * What the form chose, with anything missing or out of range replaced by
	 * the current quarter or month.
	 *
	 * @param array<string,mixed> $source
	 */
	private function selection( array $source ): ReportPeriod {
		$text   = static function ( string $key ) use ( $source ): string {
			return isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? trim( wp_unslash( (string) $source[ $key ] ) ) : '';
		};
		$today  = new DateTimeImmutable( 'now', wp_timezone() );
		$number = static function ( string $key, int $fallback, int $min, int $max ) use ( $text ): int {
			$value = absint( $text( $key ) );
			return 0 === $value ? $fallback : max( $min, min( $max, $value ) );
		};
		$year   = $number( 'year', (int) $today->format( 'Y' ), 2000, 2100 );

		switch ( $text( 'period' ) ) {
			case ReportPeriod::MONTH:
				return ReportPeriod::month( $year, $number( 'month', (int) $today->format( 'n' ), 1, 12 ) );
			case ReportPeriod::RANGE:
				$range = ReportPeriod::range( $text( 'from' ), $text( 'to' ) );
				if ( null !== $range ) {
					return $range;
				}
				break;
		}

		return ReportPeriod::quarter( $year, $number( 'quarter', (int) ceil( (int) $today->format( 'n' ) / 3 ), 1, 4 ) );
	}

	private function period_form( ReportPeriod $period, int $intrastat_month ): void {
		global $wp_locale;

		$today     = new DateTimeImmutable( 'now', wp_timezone() );
		$this_year = (int) $today->format( 'Y' );
		$quarter   = $period->quarter_number() ?? (int) ceil( $intrastat_month / 3 );
		$month     = ReportPeriod::MONTH === $period->kind() ? (int) substr( $period->start(), 5, 2 ) : $intrastat_month;
		$from      = ReportPeriod::RANGE === $period->kind() ? $period->start() : $today->format( 'Y-m-01' );
		$to        = ReportPeriod::RANGE === $period->kind() ? $period->end() : $today->format( 'Y-m-d' );
		$kinds     = array(
			ReportPeriod::QUARTER => __( 'Quarter', 'vatright' ),
			ReportPeriod::MONTH   => __( 'Month', 'vatright' ),
			ReportPeriod::RANGE   => __( 'Custom range', 'vatright' ),
		);
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="vatright-period">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<label for="vatright-period"><?php esc_html_e( 'Period', 'vatright' ); ?></label>
			<select name="period" id="vatright-period">
				<?php foreach ( $kinds as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $period->kind() ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<span data-period-kinds="quarter month">
				<label class="screen-reader-text" for="vatright-year"><?php esc_html_e( 'Year', 'vatright' ); ?></label>
				<select name="year" id="vatright-year">
					<?php for ( $y = $this_year; $y >= $this_year - 5; $y-- ) : ?>
						<option value="<?php echo esc_attr( (string) $y ); ?>" <?php selected( $y, $period->year() ); ?>><?php echo esc_html( (string) $y ); ?></option>
					<?php endfor; ?>
				</select>
			</span>
			<span data-period-kinds="quarter">
				<label class="screen-reader-text" for="vatright-quarter"><?php esc_html_e( 'Quarter', 'vatright' ); ?></label>
				<select name="quarter" id="vatright-quarter">
					<?php for ( $q = 1; $q <= 4; $q++ ) : ?>
						<option value="<?php echo esc_attr( (string) $q ); ?>" <?php selected( $q, $quarter ); ?>><?php echo esc_html( 'Q' . $q ); ?></option>
					<?php endfor; ?>
				</select>
			</span>
			<span data-period-kinds="quarter month">
				<label class="screen-reader-text" for="vatright-month"><?php esc_html_e( 'Month, also the month of the INTRASTAT report', 'vatright' ); ?></label>
				<select name="month" id="vatright-month">
					<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
						<option value="<?php echo esc_attr( (string) $m ); ?>" <?php selected( $m, $month ); ?>><?php echo esc_html( $wp_locale->get_month( $m ) ); ?></option>
					<?php endfor; ?>
				</select>
			</span>
			<span data-period-kinds="range">
				<label for="vatright-from"><?php esc_html_e( 'From', 'vatright' ); ?></label>
				<input type="date" name="from" id="vatright-from" value="<?php echo esc_attr( $from ); ?>" />
				<label for="vatright-to"><?php esc_html_e( 'To', 'vatright' ); ?></label>
				<input type="date" name="to" id="vatright-to" value="<?php echo esc_attr( $to ); ?>" />
			</span>
			<button type="submit" class="button"><?php esc_html_e( 'Show', 'vatright' ); ?></button>
		</form>
		<?php
		wp_print_inline_script_tag(
			"( function () {
	var form = document.querySelector( '.vatright-period' );
	if ( ! form ) { return; }
	var kind = form.querySelector( '[name=period]' );
	function sync() {
		form.querySelectorAll( '[data-period-kinds]' ).forEach( function ( el ) {
			el.hidden = el.getAttribute( 'data-period-kinds' ).split( ' ' ).indexOf( kind.value ) === -1;
		} );
	}
	kind.addEventListener( 'change', sync );
	sync();
} )();"
		);
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

	private function export_form( string $action, ReportPeriod $period, string $label, ?int $year = null, ?int $month = null ): void {
		$fields = array( 'period' => $period->kind() );
		if ( ReportPeriod::RANGE === $period->kind() ) {
			$fields['from'] = $period->start();
			$fields['to']   = $period->end();
		} else {
			$fields['year'] = (string) $period->year();
			if ( ReportPeriod::QUARTER === $period->kind() ) {
				$fields['quarter'] = (string) $period->quarter_number();
			} else {
				$fields['month'] = (string) (int) substr( $period->start(), 5, 2 );
			}
		}
		if ( null !== $month ) {
			$fields['month'] = (string) $month;
		}
		if ( null !== $year ) {
			$fields['year'] = (string) $year;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
			<?php endforeach; ?>
			<p><button type="submit" class="button"><?php echo esc_html( $label ); ?></button></p>
		</form>
		<?php
	}
}
