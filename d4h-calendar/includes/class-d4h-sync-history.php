<?php
/**
 * Sync History: log and retrieve sync runs (success/error, time, source).
 *
 * @package D4H_Calendar
 */

namespace D4H_Calendar;

defined( 'ABSPATH' ) || exit;

final class Sync_History {

	private const MAX_ENTRIES = 100;

	/**
	 * Log a sync run. Uses D4H Core when active, else local option.
	 *
	 * @param array<string, mixed> $config    Plugin config.
	 * @param string               $status   'success' or 'error'.
	 * @param string               $error    Error message if status is error.
	 * @param string               $trigger  How the sync started (manual, cron, manual_clean, cron_clean).
	 * @param float|null           $duration Duration in seconds (optional).
	 * @param int|null             $items_count Number of items synced (optional).
	 * @param string               $plugin   Plugin name for Core log (e.g. 'calendar').
	 */
	public static function log_sync(
		array $config,
		string $status,
		string $error = '',
		string $trigger = 'manual',
		?float $duration = null,
		?int $items_count = null,
		string $plugin = 'calendar'
	): void {
		if ( defined( 'D4H_CORE_ACTIVE' ) && class_exists( 'D4H_Core\Logger' ) ) {
			\D4H_Core\Logger::log_sync( $plugin, $status, $error, $trigger, $duration, $items_count );
			return;
		}
		$option_key = $config['option_sync_history'] ?? 'd4h_calendar_sync_history';
		$history    = get_option( $option_key, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$entry = array(
			'time'         => time(),
			'status'       => $status === 'success' ? 'success' : 'error',
			'error'        => $error,
			'source'       => $trigger,
			'duration_sec' => $duration,
			'items_count'  => $items_count,
		);

		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, self::MAX_ENTRIES );
		update_option( $option_key, $history, false );
	}

	/**
	 * Get latest sync history entries. From Core when active, else local option.
	 *
	 * @param array<string, mixed> $config Plugin config.
	 * @param int                  $limit Max entries to return.
	 * @return array<int, array{time: int, status: string, error: string, source: string, duration_sec: float|null, items_count: int|null}>
	 */
	public static function get_history( array $config, int $limit = 100 ): array {
		if ( defined( 'D4H_CORE_ACTIVE' ) && class_exists( 'D4H_Core\Logger' ) ) {
			$all = \D4H_Core\Logger::get_sync_history( $limit );
			$history = array_values( array_filter( $all, function ( $entry ) {
				return ( $entry['source'] ?? '' ) === 'calendar';
			} ) );
			return array_map( function ( $entry ) {
				$entry['source'] = $entry['trigger'] ?? $entry['source'] ?? '';
				return $entry;
			}, $history );
		}
		$option_key = $config['option_sync_history'] ?? 'd4h_calendar_sync_history';
		$history    = get_option( $option_key, array() );
		if ( ! is_array( $history ) ) {
			return array();
		}
		return array_slice( $history, 0, $limit );
	}
}
