<?php
/**
 * Fires when the plugin is uninstalled. Clears scheduled cron, deletes options, and drops the custom table.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$plugin_dir  = dirname( __FILE__ );
$config_file = $plugin_dir . '/includes/config.php';
$cron_file   = $plugin_dir . '/includes/class-d4h-cron.php';

if ( ! file_exists( $config_file ) ) {
	return;
}

require_once $config_file;
$config = d4h_calendar_get_config();

// Clear scheduled cron.
if ( file_exists( $cron_file ) ) {
	require_once $cron_file;
	D4H_Calendar\Cron::unschedule( $config );
}

// Delete all plugin options.
$option_keys = array(
	'option_token',
	'option_context',
	'option_context_id',
	'option_last_updated',
	'option_tags_map',
	'option_event_color',
	'option_exercise_color',
	'option_tag_colors',
	'option_cron_interval_sec',
	'option_last_sync_error',
	'option_last_sync_status',
	'option_sync_history',
);
foreach ( $option_keys as $key ) {
	if ( isset( $config[ $key ] ) ) {
		delete_option( $config[ $key ] );
	}
}

// Drop the activities table. Table name is built from trusted config and wpdb prefix.
global $wpdb;
$table_prefix = $config['table_name_prefix'] ?? 'd4h_calendar_';
$table_name   = $wpdb->prefix . $table_prefix . 'activities';
$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table_name ) . '`' );
