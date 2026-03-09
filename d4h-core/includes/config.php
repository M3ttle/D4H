<?php
/**
 * Config for D4H Core. No secrets; credentials saved from admin form.
 *
 * @package D4H_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the Core configuration array.
 *
 * @return array<string, mixed>
 */
function d4h_core_get_config() {
	return array(
		'api_base_url' => 'https://api.team-manager.us.d4h.com',
		'whoami_path'  => '/v3/whoami',

		'option_token'       => 'd4h_core_api_token',
		'option_context'     => 'd4h_core_api_context',
		'option_context_id'  => 'd4h_core_api_context_id',
		'option_tags_map'    => 'd4h_core_tags_map',
		'option_api_logs'    => 'd4h_core_api_logs',
		'option_sync_history' => 'd4h_core_sync_history',
		'option_github_token' => 'd4h_core_github_token',

		'max_log_entries'   => 200,
		'admin_capability'  => 'manage_options',
		'admin_menu_slug'   => 'd4h-core',
	);
}
