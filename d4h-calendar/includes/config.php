<?php
/**
 * Single config for D4H Calendar plugin. No secrets here; API credentials are saved via the admin form to options.
 *
 * @package D4H_Calendar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the plugin configuration array. All magic values and option/table names live here.
 *
 * @return array<string, mixed>
 */
function d4h_calendar_get_config() {
	return array(
		// API (base URL and path only; token and context come from options)
		'api_base_url'       => 'https://api.team-manager.us.d4h.com',
		'api_version_path'   => '/v3',
		'whoami_path'        => '/v3/whoami',

		// Option keys (credentials saved from admin form; do not put secrets in this file)
		'option_token'       => 'd4h_calendar_api_token',
		'option_context'     => 'd4h_calendar_api_context',
		'option_context_id'  => 'd4h_calendar_api_context_id',
		'option_last_updated'=> 'd4h_calendar_last_updated',

		// Cron
		'cron_interval_sec'  => 7200, // 2 hours
		'cron_hook'          => 'd4h_calendar_sync',
		'cron_schedule_name' => 'd4h_calendar_every_two_hours',

		// Retention and data
		'retention_days'     => 90,
		'table_name_prefix'  => 'd4h_calendar_',
		'table_name'         => null, // Set at runtime from prefix + $wpdb->prefix

		// Feature flags
		'enable_cron'        => true,
		'enable_delete_btn'  => true,

		// Admin
		'admin_capability'   => 'manage_options',
		'admin_menu_slug'    => 'd4h-calendar',
		'admin_page_title'   => 'D4H Calendar',
		'admin_menu_title'   => 'D4H Calendar',

		// Shortcode (Step 4)
		'shortcode_name'     => 'd4h_calendar',

		// REST API (Step 4)
		'rest_namespace'     => 'd4h-calendar/v1',
		'rest_activities_route' => 'activities',

		// FullCalendar defaults (Step 4)
		'calendar_default_view' => 'dayGridMonth',
		'calendar_date_range_days' => 90,
	);
}
