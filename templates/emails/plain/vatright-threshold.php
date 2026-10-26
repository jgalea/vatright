<?php
/**
 * Admin email (plain text): cross-border EU consumer sales are approaching or have passed the OSS threshold.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/vatright-threshold.php.
 *
 * @var string   $level
 * @var float    $sum
 * @var float    $threshold
 * @var int      $percent
 * @var int      $year
 * @var string   $reports_url
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $plain_text
 * @var WC_Email $email
 */

use Vatright\Ledger\ThresholdLevels;

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

if ( ThresholdLevels::CROSSED === $level ) {
	/* translators: 1: year, 2: sales in EUR, 3: threshold in EUR */
	$vatright_text = __( 'Your cross-border EU consumer sales for %1$s are EUR %2$s, over the EUR %3$s distance-selling threshold. VAT Right now charges the customer\'s country VAT on these sales.', 'vatright' );
} else {
	/* translators: 1: year, 2: sales in EUR, 3: threshold in EUR, 4: percent of the threshold */
	$vatright_text = __( 'Your cross-border EU consumer sales for %1$s are EUR %2$s, %4$s%% of the EUR %3$s distance-selling threshold. Once they pass it, VAT Right charges the customer\'s country VAT and you need to declare that VAT, usually through the One Stop Shop.', 'vatright' );
}
echo esc_html( sprintf( $vatright_text, $year, number_format_i18n( $sum, 2 ), number_format_i18n( $threshold, 2 ), $percent ) ) . "\n\n";

echo esc_url( $reports_url ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
