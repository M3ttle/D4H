<?php
/**
 * Plugin Name: D4H Incidents
 * Description: Fetches incidents from D4H Team Manager API, displays statistics, charts, and exports to Excel or PNG. Configure time period and view incident counts, participants, types, and heatmaps.
 * Version: 1.1.1
 * Author: Nonni
 * License: GPL v2 or later
 * Text Domain: d4h-incidents
 * Update URI: https://github.com/M3ttle/D4H
 */

defined( 'ABSPATH' ) || exit;

const D4H_INCIDENTS_VERSION = '1.1.1';
const D4H_INCIDENTS_PLUGIN_FILE = __FILE__;
const D4H_INCIDENTS_PLUGIN_DIR = __DIR__;

require_once D4H_INCIDENTS_PLUGIN_DIR . '/includes/config.php';
require_once D4H_INCIDENTS_PLUGIN_DIR . '/includes/class-d4h-incidents-api-client.php';
require_once D4H_INCIDENTS_PLUGIN_DIR . '/includes/class-d4h-incidents-admin.php';
require_once D4H_INCIDENTS_PLUGIN_DIR . '/includes/class-d4h-incidents-plugin-updater.php';
require_once D4H_INCIDENTS_PLUGIN_DIR . '/includes/class-d4h-incidents-loader.php';

add_action( 'plugins_loaded', function () {
	$config = d4h_incidents_get_config();
	$loader = new D4H_Incidents\Loader( $config );
	$loader->init();
}, 5 );
