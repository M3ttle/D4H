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
		'option_context'     => 'd4h_calendar_api_org',
		'option_context_id'  => 'd4h_calendar_api_org_id',
		'option_last_updated'=> 'd4h_calendar_last_updated',

		// Cron
		'cron_interval_sec'  => 7200, // 2 hours
		'cron_hook'          => 'd4h_calendar_sync',
		'cron_schedule_name' => 'd4h_calendar_every_two_hours',
		'cron_lock_ttl_sec'  => 900, // 15 minutes – transient lock TTL in case of crash
		'option_cron_interval_sec' => 'd4h_calendar_cron_interval_sec', // Admin override; falls back to cron_interval_sec
		'cron_interval_presets'    => array(
			3600   => array( 'name' => 'd4h_calendar_1h',  'label' => '1 hour' ),
			7200   => array( 'name' => 'd4h_calendar_2h',  'label' => '2 hours' ),
			21600  => array( 'name' => 'd4h_calendar_6h',  'label' => '6 hours' ),
			43200  => array( 'name' => 'd4h_calendar_12h', 'label' => '12 hours' ),
			86400  => array( 'name' => 'd4h_calendar_24h', 'label' => '24 hours' ),
		),

		// Sync status options (error handling)
		'option_last_sync_error'  => 'd4h_calendar_last_sync_error',
		'option_last_sync_status' => 'd4h_calendar_last_sync_status',

		// Retention and data
		'retention_days'     => 90,
		'table_name_prefix'  => 'd4h_calendar_',
		'table_name'         => null, // Set at runtime from prefix + $wpdb->prefix

		// Feature flags
		'enable_cron'        => true,
		'enable_delete_btn'  => true,

		// AJAX actions (admin-only)
		'ajax_action_sync'   => 'd4h_calendar_ajax_sync',
		'ajax_action_delete' => 'd4h_calendar_ajax_delete',

		// Admin
		'admin_capability'   => 'manage_options',
		'admin_menu_slug'    => 'd4h-calendar',
		'admin_page_title'   => 'D4H Calendar',
		'admin_menu_title'   => 'D4H Calendar',

		// Shortcode
		'shortcode_name'     => 'd4h_calendar',

		// REST API
		'rest_namespace'     => 'd4h-calendar/v1',
		'rest_activities_route' => 'activities',

		// FullCalendar defaults
		'calendar_default_view'    => 'dayGridMonth',
		'calendar_date_range_days' => 90,
		'calendar_event_color'     => '#3788d8',
		'calendar_exercise_color'  => '#6c757d',
		'calendar_locale'          => 'is',
		'calendar_content_height'  => 800,
	);
}
