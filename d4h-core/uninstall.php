<?php
/**
 * Fires when D4H Core is uninstalled.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$config_file = dirname( __FILE__ ) . '/includes/config.php';
if ( ! file_exists( $config_file ) ) {
	return;
}

require_once $config_file;
$config = d4h_core_get_config();

$option_keys = array( 'option_token', 'option_context', 'option_context_id', 'option_tags_map', 'option_api_logs', 'option_sync_history' );
foreach ( $option_keys as $key ) {
	if ( isset( $config[ $key ] ) ) {
		delete_option( $config[ $key ] );
	}
}
