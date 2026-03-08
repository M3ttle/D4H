<?php
/**
 * Config for D4H Incidents plugin. No secrets here; API credentials can be shared with D4H Calendar or stored separately.
 *
 * @package D4H_Incidents
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the plugin configuration array.
 *
 * @return array<string, mixed>
 */
function d4h_incidents_get_config() {
	return array(
		'api_base_url' => 'https://api.team-manager.us.d4h.com',
		'whoami_path'  => '/v3/whoami',

		// Option keys – can share with D4H Calendar or use own
		'option_token'      => 'd4h_incidents_api_token',
		'option_context'    => 'd4h_incidents_api_context',
		'option_context_id' => 'd4h_incidents_api_context_id',

		// Transient for last fetch (1 hour)
		'option_last_fetch' => 'd4h_incidents_last_fetch',
		'transient_ttl'     => HOUR_IN_SECONDS,

		// Default time range: last 1 year
		'default_range_days' => 365,

		// AJAX actions
		'ajax_action_fetch'  => 'd4h_incidents_ajax_fetch',
		'ajax_action_export_excel' => 'd4h_incidents_ajax_export_excel',
		'ajax_action_export_png'   => 'd4h_incidents_ajax_export_png',

		// Self-update from GitHub (same repo as calendar)
		'update_github_repo' => 'M3ttle/D4H',
		'option_github_token' => 'd4h_incidents_github_token',

		// Admin
		'admin_capability' => 'manage_options',
		'admin_menu_slug'  => 'd4h-incidents',
		'admin_page_title' => 'D4H Incidents',
		'admin_menu_title' => 'D4H Incidents',
	);
}
