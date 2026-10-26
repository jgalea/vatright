<?php
/**
 * Plugin Name:       VAT Right – EU VAT & OSS for WooCommerce
 * Description:       Decides, applies and records EU VAT treatment on every WooCommerce order.
 * Version:           0.1.4
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Jean Galea
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       vatright
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'VATRIGHT_VERSION', '0.1.4' );
define( 'VATRIGHT_FILE', __FILE__ );
define( 'VATRIGHT_DIR', plugin_dir_path( __FILE__ ) );
define( 'VATRIGHT_URL', plugin_dir_url( __FILE__ ) );

if ( ! file_exists( VATRIGHT_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'VAT Right is missing its autoloader. Run "composer install" in the plugin folder.', 'vatright' ) . '</p></div>';
		}
	);
	return;
}

require_once VATRIGHT_DIR . 'vendor/autoload.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VATRIGHT_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', VATRIGHT_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( \Vatright\Ledger\Schema::class, 'install' ) );

add_action( 'plugins_loaded', array( \Vatright\Plugin::class, 'boot' ), 20 );
