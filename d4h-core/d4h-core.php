<?php
/**
 * Plugin Name: D4H Core
 * Description: Shared D4H API credentials, logs, and admin menu. Required by D4H Calendar and D4H Incidents.
 * Version: 1.0.0
 * Author: Nonni
 * License: GPL v2 or later
 * Text Domain: d4h-core
 * Update URI: https://github.com/M3ttle/D4H
 */

defined( 'ABSPATH' ) || exit;

define( 'D4H_CORE_ACTIVE', true );
define( 'D4H_CORE_VERSION', '1.0.0' );
define( 'D4H_CORE_PLUGIN_FILE', __FILE__ );
define( 'D4H_CORE_PLUGIN_DIR', __DIR__ );

require_once D4H_CORE_PLUGIN_DIR . '/includes/config.php';
require_once D4H_CORE_PLUGIN_DIR . '/includes/functions.php';
require_once D4H_CORE_PLUGIN_DIR . '/includes/class-d4h-core-admin.php';
require_once D4H_CORE_PLUGIN_DIR . '/includes/class-d4h-core-logger.php';

add_action( 'plugins_loaded', function () {
	$config = d4h_core_get_config();
	$admin  = new D4H_Core\Admin( $config );
	$admin->register_hooks();
}, 1 );
