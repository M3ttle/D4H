<?php
/**
 * Shared helper functions for credentials and context.
 *
 * @package D4H_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get D4H API token from Core.
 *
 * @return string
 */
function d4h_core_get_token(): string {
	$config = d4h_core_get_config();
	return (string) get_option( $config['option_token'] ?? 'd4h_core_api_token', '' );
}

/**
 * Get D4H API context (team or organisation).
 *
 * @return string
 */
function d4h_core_get_context(): string {
	$config = d4h_core_get_config();
	return (string) get_option( $config['option_context'] ?? 'd4h_core_api_context', '' );
}

/**
 * Get GitHub API token for plugin updates. Used by Calendar, Incidents, and Create Activity to increase rate limit (60→5000/hour).
 *
 * @return string
 */
function d4h_core_get_github_token(): string {
	if ( defined( 'D4H_CORE_GITHUB_TOKEN' ) && is_string( D4H_CORE_GITHUB_TOKEN ) ) {
		return trim( D4H_CORE_GITHUB_TOKEN );
	}
	if ( defined( 'D4H_CALENDAR_GITHUB_TOKEN' ) && is_string( D4H_CALENDAR_GITHUB_TOKEN ) ) {
		return trim( D4H_CALENDAR_GITHUB_TOKEN );
	}
	if ( defined( 'D4H_INCIDENTS_GITHUB_TOKEN' ) && is_string( D4H_INCIDENTS_GITHUB_TOKEN ) ) {
		return trim( D4H_INCIDENTS_GITHUB_TOKEN );
	}
	$config = d4h_core_get_config();
	$token  = get_option( $config['option_github_token'] ?? 'd4h_core_github_token', '' );
	return is_string( $token ) ? trim( $token ) : '';
}

/**
 * Get D4H tags map (id => name). Used by D4H Incidents and D4H Create Activity.
 * Call "Update tags" in D4H Settings to fetch and store tags from the API.
 *
 * @return array<int, string> Tag ID => tag name
 */
function d4h_core_get_tags_map(): array {
	$config = d4h_core_get_config();
	$map    = get_option( $config['option_tags_map'] ?? 'd4h_core_tags_map', array() );
	return is_array( $map ) ? $map : array();
}

/**
 * Get D4H API context ID (team or organisation ID).
 *
 * @return string
 */
function d4h_core_get_context_id(): string {
	$config = d4h_core_get_config();
	return (string) get_option( $config['option_context_id'] ?? 'd4h_core_api_context_id', '' );
}
