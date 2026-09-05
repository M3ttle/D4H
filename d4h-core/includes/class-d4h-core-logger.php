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
	 * Allowed values for how a sync was started.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_triggers(): array {
		return array( 'cron', 'cron_clean', 'manual', 'manual_clean' );
	}

	/**
	 * Store a known trigger; unknown values become "manual".
	 *
	 * @param string $trigger Raw trigger from a plugin.
	 * @return string
	 */
	public static function normalize_trigger( string $trigger ): string {
		return in_array( $trigger, self::allowed_triggers(), true ) ? $trigger : 'manual';
	}

	/**
	 * Log an API call.
	 *
	 * @param string     $endpoint e.g. /v3/team/123/events
	 * @param int        $code     HTTP status code
	 * @param float|null $duration Duration in seconds
	 * @param string     $source   e.g. 'calendar', 'incidents'
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
		$logs = self::prune_entries( $logs, $max, 'api' );
		update_option( $option, $logs, false );
	}

	/**
	 * Log a sync run.
	 *
	 * @param string     $source      'calendar', 'incidents', etc.
	 * @param string     $status      'success' or 'error'
	 * @param string     $error       Error message if status is error
	 * @param string     $trigger     How the sync started
	 * @param float|null $duration    Duration in seconds
	 * @param int|null   $items_count Items synced (optional)
	 */
	public static function log_sync( string $source, string $status, string $error = '', string $trigger = 'manual', ?float $duration = null, ?int $items_count = null ): void {
		if ( ! defined( 'D4H_CORE_ACTIVE' ) ) {
			return;
		}
		$config  = d4h_core_get_config();
		$option  = $config['option_sync_history'] ?? 'd4h_core_sync_history';
		$max     = (int) ( $config['max_log_entries'] ?? 200 );
		$history = get_option( $option, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		$entry = array(
			'time'         => time(),
			'source'       => $source,
			'status'       => $status === 'success' ? 'success' : 'error',
			'error'        => $error,
			'trigger'      => self::normalize_trigger( $trigger ),
			'duration_sec' => $duration,
			'items_count'  => $items_count,
		);
		array_unshift( $history, $entry );
		$history = self::prune_entries( $history, $max, 'sync' );
		update_option( $option, $history, false );
	}

	/**
	 * Get sync history. Pass 0 to return every stored entry.
	 *
	 * @param int $limit Max entries; 0 means all
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_sync_history( int $limit = 0 ): array {
		if ( ! defined( 'D4H_CORE_ACTIVE' ) ) {
			return array();
		}
		$config  = d4h_core_get_config();
		$option  = $config['option_sync_history'] ?? 'd4h_core_sync_history';
		$history = get_option( $option, array() );
		if ( ! is_array( $history ) ) {
			return array();
		}
		if ( $limit > 0 ) {
			return array_slice( $history, 0, $limit );
		}
		return $history;
	}

	/**
	 * Get API logs. Pass 0 to return every stored entry.
	 *
	 * @param int $limit Max entries; 0 means all
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_api_logs( int $limit = 0 ): array {
		if ( ! defined( 'D4H_CORE_ACTIVE' ) ) {
			return array();
		}
		$config = d4h_core_get_config();
		$option = $config['option_api_logs'] ?? 'd4h_core_api_logs';
		$logs   = get_option( $option, array() );
		if ( ! is_array( $logs ) ) {
			return array();
		}
		if ( $limit > 0 ) {
			return array_slice( $logs, 0, $limit );
		}
		return $logs;
	}

	/**
	 * Keep recent errors for 60 days, and the newest other rows up to $max.
	 *
	 * @param array<int, mixed> $entries
	 * @param int               $max
	 * @param string            $kind 'sync' or 'api'
	 * @return array<int, array<string, mixed>>
	 */
	private static function prune_entries( array $entries, int $max, string $kind ): array {
		$config = d4h_core_get_config();
		$days   = (int) ( $config['log_error_retain_days'] ?? 60 );
		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$kept   = array();
		$regular_count = 0;

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$is_recent_error = self::entry_is_error( $entry, $kind ) && (int) ( $entry['time'] ?? 0 ) >= $cutoff;
			if ( $is_recent_error ) {
				$kept[] = $entry;
				continue;
			}
			if ( $regular_count < $max ) {
				$kept[] = $entry;
				$regular_count++;
			}
		}

		return $kept;
	}

	/**
	 * Whether a log row is a failed sync or a failed API call.
	 *
	 * @param array<string, mixed> $entry
	 * @param string               $kind 'sync' or 'api'
	 */
	private static function entry_is_error( array $entry, string $kind ): bool {
		if ( $kind === 'sync' ) {
			return ( $entry['status'] ?? '' ) !== 'success';
		}
		$code = (int) ( $entry['code'] ?? 0 );
		return $code < 200 || $code >= 300;
	}
}
