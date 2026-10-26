<?php

namespace Vatright\Ledger;

use Vatright\Settings;

class ThresholdNotifier {

	const OPTION = 'vatright_threshold_alerts';

	/** @var ThresholdTracker */
	private $tracker;

	public function __construct( ThresholdTracker $tracker ) {
		$this->tracker = $tracker;
	}

	public function register(): void {
		add_action( 'vatright_ledger_recorded', array( $this, 'check' ) );
		add_filter( 'woocommerce_email_classes', array( $this, 'add_email' ) );
	}

	/**
	 * @param array<string,object> $emails
	 * @return array<string,object>
	 */
	public function add_email( $emails ) {
		$emails[ ThresholdEmail::KEY ] = new ThresholdEmail();
		return $emails;
	}

	public function check( int $year ): void {
		$settings = Settings::get();
		if ( (int) wp_date( 'Y' ) !== $year || $settings->oss_registered() ) {
			return;
		}

		$alerts    = $this->alerts();
		$threshold = $this->tracker->threshold();
		$sum       = $this->tracker->status( $year )->current_eur;
		$level     = ThresholdLevels::due( $sum, $threshold, $settings->threshold_warning_percent(), array_keys( $alerts[ $year ] ?? array() ) );
		if ( null === $level ) {
			return;
		}

		$email = WC()->mailer()->get_emails()[ ThresholdEmail::KEY ] ?? null;
		if ( ! $email instanceof ThresholdEmail ) {
			return;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( ThresholdLevels::covered( $level ) as $covered ) {
			$alerts[ $year ][ $covered ] = $alerts[ $year ][ $covered ] ?? $now;
		}
		update_option( self::OPTION, $alerts, false );

		if ( ! $email->trigger( $level, $sum, $threshold, $year ) ) {
			$this->forget( $year, $level );
		}
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function alerts(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	private function forget( int $year, string $level ): void {
		$alerts = $this->alerts();
		foreach ( ThresholdLevels::covered( $level ) as $covered ) {
			unset( $alerts[ $year ][ $covered ] );
		}
		if ( array() === ( $alerts[ $year ] ?? array() ) ) {
			unset( $alerts[ $year ] );
		}
		if ( array() === $alerts ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $alerts, false );
	}
}
