<?php

namespace Vatright\Ledger;

use WC_Email;

class ThresholdEmail extends WC_Email {

	const KEY = 'vatright_threshold';

	/** @var string */
	public $level = ThresholdLevels::WARNING;

	/** @var float */
	public $sum = 0.0;

	/** @var float */
	public $threshold = 0.0;

	/** @var int */
	public $year = 0;

	public function __construct() {
		$this->id             = self::KEY;
		$this->customer_email = false;
		$this->title          = __( 'OSS threshold warning', 'vatright' );
		$this->description    = __( 'Sent to the store admin when cross-border EU consumer sales reach the warning share of the EUR 10,000 OSS threshold, and again when they pass it. Each email goes out once a year.', 'vatright' );
		$this->template_html  = 'emails/vatright-threshold.php';
		$this->template_plain = 'emails/plain/vatright-threshold.php';
		$this->template_base  = dirname( __DIR__, 2 ) . '/templates/';
		$this->placeholders   = array(
			'{status}' => '',
			'{year}'   => '',
		);

		parent::__construct();

		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	public function get_default_subject() {
		return __( '[{site_title}]: OSS threshold {status} in {year}', 'vatright' );
	}

	public function get_default_heading() {
		return __( 'OSS threshold {status}', 'vatright' );
	}

	public function trigger( string $level, float $sum, float $threshold, int $year ): bool {
		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		$this->setup_locale();
		$this->level                    = $level;
		$this->sum                      = $sum;
		$this->threshold                = $threshold;
		$this->year                     = $year;
		$this->placeholders['{status}'] = ThresholdLevels::CROSSED === $level
			? __( 'crossed', 'vatright' )
			/* translators: %d: percent of the threshold reached */
			: sprintf( __( 'at %d%%', 'vatright' ), $this->percent() );
		$this->placeholders['{year}'] = (string) $year;

		$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		$this->restore_locale();
		return (bool) $sent;
	}

	public function percent(): int {
		return $this->threshold > 0 ? (int) floor( $this->sum / $this->threshold * 100 ) : 0;
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function template_args( bool $plain_text ): array {
		return array(
			'level'              => $this->level,
			'sum'                => $this->sum,
			'threshold'          => $this->threshold,
			'percent'            => $this->percent(),
			'year'               => $this->year,
			'reports_url'        => admin_url( 'admin.php?page=vatright-reports' ),
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => true,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}
}
