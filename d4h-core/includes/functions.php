<?php
/**
 * Shared helper functions for credentials and context.
 *
 * @package D4H_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get D4H API token from Core (if active) or legacy options.
 *
 * @return string
 */
function d4h_core_get_token(): string {
	if ( ! defined( 'D4H_CORE_ACTIVE' ) ) {
		return get_option( 'd4h_calendar_api_token', '' )
			?: get_option( 'd4h_incidents_api_token', '' );
	}
	$config = d4h_core_get_config();
	return (string) get_option( $config['option_token'] ?? 'd4h_core_api_token', '' );
}

/**
 * Get D4H API context (team or organisation).
 *
 * @return string
 */
function d4h_core_get_context(): string {
	if ( ! defined( 'D4H_CORE_ACTIVE' ) ) {
		return get_option( 'd4h_calendar_api_org', '' )
			?: get_option( 'd4h_incidents_api_context', '' );
	}
	$config = d4h_core_get_config();
	return (string) get_option( $config['option_context'] ?? 'd4h_core_api_context', '' );
}

/**
 * Get D4H API context ID (team or organisation ID).
 *
 * @return string
 */
function d4h_core_get_context_id(): string {
	if ( ! defined( 'D4H_CORE_ACTIVE' ) ) {
		return get_option( 'd4h_calendar_api_org_id', '' )
			?: get_option( 'd4h_incidents_api_context_id', '' );
	}
	$config = d4h_core_get_config();
	return (string) get_option( $config['option_context_id'] ?? 'd4h_core_api_context_id', '' );
}
