<?php
/**
 * Config for D4H Create Activity. No secrets; credentials come from D4H Core.
 *
 * @package D4H_Create_Activity
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the plugin configuration array.
 *
 * @return array<string, mixed>
 */
function d4h_create_activity_get_config() {
	return array(
		'api_base_url' => 'https://api.team-manager.us.d4h.com',

		'max_rows'           => 50,
		'title_max_length'   => 100,
		'text_max_length'    => 65535,

		'ajax_action_parse' => 'd4h_create_activity_ajax_parse',
		'ajax_action_send'  => 'd4h_create_activity_ajax_send',

		'update_github_repo' => 'M3ttle/D4H',

		'admin_capability' => 'manage_options',
		'admin_menu_slug'  => 'd4h-create-activity',
		'admin_page_title' => 'D4H Create activity',
		'admin_menu_title' => 'D4H Create activity',
	);
}
