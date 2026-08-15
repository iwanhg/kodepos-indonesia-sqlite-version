<?php
/**
 * Plugin Name: Kodepos Indonesia SQLite Version
 * Plugin URI:  https://github.com/iwanhg/kodepos-indonesia-sqlite-version
 * Description: Replaces WooCommerce Indonesian address entry with cascading Province, City, District, Sub-district and Postal Code dropdowns powered by a bundled offline postal code database.
 * Version:     0.0.1
 * Author:      Iwan
 * Text Domain: kodepos-indonesia-sqlite-version
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'KODEPOS_ID_VERSION', '1.2.2' );
define( 'KODEPOS_ID_FILE', __FILE__ );
define( 'KODEPOS_ID_DIR', plugin_dir_path( __FILE__ ) );
define( 'KODEPOS_ID_URL', plugin_dir_url( __FILE__ ) );

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
						esc_html__( 'Kodepos Indonesia requires WooCommerce to be installed and active.', 'kodepos-indonesia' ) .
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
