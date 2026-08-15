<?php
/**
 * Uninstall cleanup: remove plugin options and cached API responses.
 *
 * @package Kodepos_Indonesia
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Options from versions before the plugin switched to a bundled, offline
// SQLite database — safe no-ops if they were never set.
delete_option( 'kodepos_indonesia_settings' );
delete_option( 'kodepos_indonesia_cache_salt' );
delete_option( 'kodepos_id_provinces_backup' ); // Leftover from the retired woocommerce_states override.

global $wpdb;

// Drop any cached API transients left over from before the SQLite migration.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_kodepos\_id\_%'
	    OR option_name LIKE '\_transient\_timeout\_kodepos\_id\_%'"
);
