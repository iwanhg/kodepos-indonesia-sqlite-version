<?php
/**
 * Plugin Name: Kodepos Indonesia SQLite Version
 * Plugin URI:  https://github.com/iwanhg/kodepos-indonesia-sqlite-version
 * Description: Replaces WooCommerce Indonesian address entry with cascading Province, City, District, Sub-district and Postal Code dropdowns powered by a bundled offline postal code database.
 * Version:     1.2.2
 * Author:      Iwan HG
 * Text Domain: kodepos-indonesia-sqlite-version
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * License: GPLv2 or later
 */

defined( 'ABSPATH' ) || exit;

define( 'KODEPOS_ID_VERSION', '1.2.2' );
define( 'KODEPOS_ID_FILE', __FILE__ );
define( 'KODEPOS_ID_DIR', plugin_dir_path( __FILE__ ) );
define( 'KODEPOS_ID_URL', plugin_dir_url( __FILE__ ) );

/**
 * Write the data/.htaccess deny rule that blocks direct HTTP access to the
 * bundled SQLite file. Not shipped as a static file in the plugin package
 * (WordPress.org disallows distributing dotfiles), written at runtime
 * instead, on activation and opportunistically if it's ever missing.
 */
function kodepos_id_write_data_htaccess() {
	$file = KODEPOS_ID_DIR . 'data/.htaccess';

	if ( file_exists( $file ) ) {
		return;
	}

	$contents = "# Deny direct access to the bundled Kodepos Indonesia SQLite database.\n"
		. "<IfModule mod_authz_core.c>\n"
		. "\tRequire all denied\n"
		. "</IfModule>\n"
		. "<IfModule !mod_authz_core.c>\n"
		. "\tOrder allow,deny\n"
		. "\tDeny from all\n"
		. "</IfModule>\n";

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Runs before/without WP_Filesystem context (activation, early plugins_loaded); writes a static deny rule inside the plugin's own data directory only.
	@file_put_contents( $file, $contents );
}
register_activation_hook( KODEPOS_ID_FILE, 'kodepos_id_write_data_htaccess' );
add_action( 'plugins_loaded', 'kodepos_id_write_data_htaccess', 1 );

/**
 * Declare compatibility with WooCommerce HPOS and the Cart/Checkout blocks.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'Kodepos Indonesia requires WooCommerce to be installed and active.', 'kodepos-indonesia-sqlite-version' ) .
						'</p></div>';
				}
			);
			return;
		}

		require_once KODEPOS_ID_DIR . 'includes/class-kodepos-db.php';
		require_once KODEPOS_ID_DIR . 'includes/class-kodepos-settings.php';
		require_once KODEPOS_ID_DIR . 'includes/class-kodepos-rest-proxy.php';
		require_once KODEPOS_ID_DIR . 'includes/class-kodepos-checkout.php';
		require_once KODEPOS_ID_DIR . 'includes/class-kodepos-admin.php';

		$db = new Kodepos_DB();

		new Kodepos_Settings( $db );
		new Kodepos_Rest_Proxy( $db );
		new Kodepos_Checkout( $db );
		new Kodepos_Admin( $db );
	}
);
