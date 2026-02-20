<?php
/**
 * Fires when the plugin is uninstalled. Clears scheduled cron.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$plugin_dir = dirname( __FILE__ );
$config_file = $plugin_dir . '/includes/config.php';
$cron_file   = $plugin_dir . '/includes/class-d4h-cron.php';

if ( file_exists( $config_file ) && file_exists( $cron_file ) ) {
	require_once $config_file;
	require_once $cron_file;
	$config = d4h_calendar_get_config();
	D4H_Calendar\Cron::unschedule( $config );
}
