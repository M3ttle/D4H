<?php
/**
 * Plugin Name: D4H Calendar
 * Description: Fetches events and exercises from D4H Team Manager API, stores them locally, and displays them in a calendar. Sync via cron or manual update from admin.
 * Version: 1.0.0
 * Author: Nonni
 * License: GPL v2 or later
 * Text Domain: d4h-calendar
 */

defined( 'ABSPATH' ) || exit;

const D4H_CALENDAR_VERSION = '1.0.0';
const D4H_CALENDAR_PLUGIN_FILE = __FILE__;
const D4H_CALENDAR_PLUGIN_DIR = __DIR__;

require_once D4H_CALENDAR_PLUGIN_DIR . '/includes/config.php';
require_once D4H_CALENDAR_PLUGIN_DIR . '/includes/class-d4h-database.php';
require_once D4H_CALENDAR_PLUGIN_DIR . '/includes/class-d4h-api-client.php';
require_once D4H_CALENDAR_PLUGIN_DIR . '/includes/class-d4h-repository.php';
require_once D4H_CALENDAR_PLUGIN_DIR . '/includes/class-d4h-sync.php';
require_once D4H_CALENDAR_PLUGIN_DIR . '/includes/class-d4h-cron.php';
require_once D4H_CALENDAR_PLUGIN_DIR . '/includes/class-d4h-rest.php';
require_once D4H_CALENDAR_PLUGIN_DIR . '/includes/class-d4h-shortcode.php';
require_once D4H_CALENDAR_PLUGIN_DIR . '/includes/class-d4h-admin.php';
require_once D4H_CALENDAR_PLUGIN_DIR . '/includes/class-d4h-loader.php';

add_action( 'plugins_loaded', function () {
	$config = d4h_calendar_get_config();

	// Migrate old option names to new (context → org).
	$option_name_org    = $config['option_context'] ?? 'd4h_calendar_api_org';
	$option_name_org_id = $config['option_context_id'] ?? 'd4h_calendar_api_org_id';
	$legacy_org_value   = get_option( 'd4h_calendar_api_context', '' );
	$legacy_org_id      = get_option( 'd4h_calendar_api_context_id', '' );
	if ( empty( get_option( $option_name_org, '' ) ) && ( $legacy_org_value !== '' || $legacy_org_id !== '' ) ) {
		if ( $legacy_org_value !== '' ) {
			update_option( $option_name_org, $legacy_org_value );
			delete_option( 'd4h_calendar_api_context' );
		}
		if ( $legacy_org_id !== '' ) {
			update_option( $option_name_org_id, $legacy_org_id );
			delete_option( 'd4h_calendar_api_context_id' );
		}
	}

	$loader = new D4H_Calendar\Loader( $config );
	$loader->init();
}, 5 );

register_activation_hook( D4H_CALENDAR_PLUGIN_FILE, function () {
	$config   = d4h_calendar_get_config();
	global $wpdb;
	$config['table_name'] = $wpdb->prefix . ( $config['table_name_prefix'] ?? 'd4h_calendar_' ) . 'activities';
	$database = new D4H_Calendar\Database( $config );
	$database->maybe_create_tables();

	if ( ! empty( $config['enable_cron'] ) ) {
		$cron = new D4H_Calendar\Cron( $config );
		$cron->schedule();
	}
} );
