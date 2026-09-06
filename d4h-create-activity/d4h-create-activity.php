<?php
/**
 * Plugin Name: D4H Create Activity
 * Description: Paste spreadsheet rows and create Full-Team exercises and events in D4H. Uses credentials and tags from D4H Core.
 * Version: 1.0.7
 * Author: Nonni
 * License: GPL v2 or later
 * Text Domain: d4h-create-activity
 * Update URI: https://github.com/M3ttle/D4H
 * Requires Plugins: d4h-core
 */

defined( 'ABSPATH' ) || exit;

const D4H_CREATE_ACTIVITY_VERSION     = '1.0.7';
const D4H_CREATE_ACTIVITY_PLUGIN_FILE = __FILE__;
const D4H_CREATE_ACTIVITY_PLUGIN_DIR  = __DIR__;

require_once D4H_CREATE_ACTIVITY_PLUGIN_DIR . '/includes/config.php';
require_once D4H_CREATE_ACTIVITY_PLUGIN_DIR . '/includes/class-d4h-create-activity-parser.php';
require_once D4H_CREATE_ACTIVITY_PLUGIN_DIR . '/includes/class-d4h-create-activity-api-client.php';
require_once D4H_CREATE_ACTIVITY_PLUGIN_DIR . '/includes/class-d4h-create-activity-admin.php';
require_once D4H_CREATE_ACTIVITY_PLUGIN_DIR . '/includes/class-d4h-create-activity-plugin-updater.php';
require_once D4H_CREATE_ACTIVITY_PLUGIN_DIR . '/includes/class-d4h-create-activity-loader.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! defined( 'D4H_CORE_ACTIVE' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'D4H Create Activity requires D4H Core. Please activate D4H Core first.', 'd4h-create-activity' );
					echo '</p></div>';
				}
			);
			return;
		}

		$config = d4h_create_activity_get_config();
		$loader = new D4H_Create_Activity\Loader( $config );
		$loader->init();
	},
	5
);
