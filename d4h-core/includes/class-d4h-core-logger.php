<?php
/**
 * API logs and sync history (shared across D4H plugins).
 *
 * @package D4H_Core
 */

namespace D4H_Core;

defined( 'ABSPATH' ) || exit;

final class Logger {

	/**
	 * Log an API call.
	 *
	 * @param string   $endpoint  e.g. /v3/team/123/events
	 * @param int      $code      HTTP status code
	 * @param float|null $duration Duration in seconds
	 * @param string   $source    e.g. 'calendar', 'incidents'
	 */
	public static function log_api( string $endpoint, int $code, ?float $duration = null, string $source = '' ): void {
		if ( ! defined( 'D4H_CORE_ACTIVE' ) ) {
			return;
		}
		$config = d4h_core_get_config();
		$option = $config['option_api_logs'] ?? 'd4h_core_api_logs';
		$max    = (int) ( $config['max_log_entries'] ?? 200 );
		$logs   = get_option( $option, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}
		$entry = array(
			'time'     => time(),
			'endpoint' => $endpoint,
			'code'     => $code,
			'duration' => $duration,
			'source'   => $source,
		);
		array_unshift( $logs, $entry );
		$logs = array_slice( $logs, 0, $max );
		update_option( $option, $logs, false );
	}

	/**
	 * Log a sync run.
	 *
	 * @param string   $source   'calendar', 'incidents', etc.
	 * @param string   $status   'success' or 'error'
	 * @param string   $error    Error message if status is error
	 * @param string   $trigger  'manual' or 'cron'
	 * @param float|null $duration Duration in seconds
	 * @param int|null $items_count Items synced (optional)
	 */
	public static function log_sync( string $source, string $status, string $error = '', string $trigger = 'manual', ?float $duration = null, ?int $items_count = null ): void {
		if ( ! defined( 'D4H_CORE_ACTIVE' ) ) {
			return;
		}
		$config = d4h_core_get_config();
		$option = $config['option_sync_history'] ?? 'd4h_core_sync_history';
		$max    = (int) ( $config['max_log_entries'] ?? 200 );
		$history = get_option( $option, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		$entry = array(
			'time'         => time(),
			'source'       => $source,
			'status'       => $status === 'success' ? 'success' : 'error',
			'error'        => $error,
			'trigger'      => $trigger === 'cron' ? 'cron' : 'manual',
			'duration_sec' => $duration,
			'items_count'  => $items_count,
		);
		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, $max );
		update_option( $option, $history, false );
	}

	/**
	 * Get sync history.
	 *
	 * @param int $limit Max entries
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_sync_history( int $limit = 100 ): array {
		if ( ! defined( 'D4H_CORE_ACTIVE' ) ) {
			return array();
		}
		$config  = d4h_core_get_config();
		$option  = $config['option_sync_history'] ?? 'd4h_core_sync_history';
		$history = get_option( $option, array() );
		if ( ! is_array( $history ) ) {
			return array();
		}
		return array_slice( $history, 0, $limit );
	}

	/**
	 * Get API logs.
	 *
	 * @param int $limit Max entries
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_api_logs( int $limit = 100 ): array {
		if ( ! defined( 'D4H_CORE_ACTIVE' ) ) {
			return array();
		}
		$config = d4h_core_get_config();
		$option = $config['option_api_logs'] ?? 'd4h_core_api_logs';
		$logs   = get_option( $option, array() );
		if ( ! is_array( $logs ) ) {
			return array();
		}
		return array_slice( $logs, 0, $limit );
	}
}
