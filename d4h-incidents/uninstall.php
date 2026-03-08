<?php
/**
 * Fires when the D4H Incidents plugin is uninstalled.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$plugin_dir = dirname( __FILE__ );
$config_file = $plugin_dir . '/includes/config.php';

if ( ! file_exists( $config_file ) ) {
	return;
}

require_once $config_file;
$config = d4h_incidents_get_config();

$option_keys = array(
	'option_token',
	'option_context',
	'option_context_id',
	'option_last_fetch',
);

foreach ( $option_keys as $key ) {
	if ( isset( $config[ $key ] ) ) {
		delete_option( $config[ $key ] );
	}
}
