<?php
/**
 * Fires when the plugin is uninstalled. Clears scheduled cron.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$config = array(
	'cron_hook' => 'd4h_calendar_sync',
);

if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( $config['cron_hook'] );
}
