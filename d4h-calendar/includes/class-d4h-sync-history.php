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
	 * Log a sync run.
	 *
	 * @param array<string, mixed> $config   Plugin config.
	 * @param string               $status   'success' or 'error'.
	 * @param string               $error    Error message if status is error.
	 * @param string               $source   'manual' or 'cron'.
	 * @param float|null           $duration Duration in seconds (optional).
	 * @param int|null             $items_count Number of items synced (optional).
	 */
	public static function log_sync(
		array $config,
		string $status,
		string $error = '',
		string $source = 'manual',
		?float $duration = null,
		?int $items_count = null
	): void {
		$option_key = $config['option_sync_history'] ?? 'd4h_calendar_sync_history';
		$history    = get_option( $option_key, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$entry = array(
			'time'         => time(),
			'status'       => $status === 'success' ? 'success' : 'error',
			'error'        => $error,
			'source'       => $source === 'cron' ? 'cron' : 'manual',
			'duration_sec' => $duration,
			'items_count'  => $items_count,
		);

		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, self::MAX_ENTRIES );
		update_option( $option_key, $history, false );
	}

	/**
	 * Get latest sync history entries.
	 *
	 * @param array<string, mixed> $config Plugin config.
	 * @param int                  $limit Max entries to return.
	 * @return array<int, array{time: int, status: string, error: string, source: string, duration_sec: float|null, items_count: int|null}>
	 */
	public static function get_history( array $config, int $limit = 100 ): array {
		$option_key = $config['option_sync_history'] ?? 'd4h_calendar_sync_history';
		$history    = get_option( $option_key, array() );
		if ( ! is_array( $history ) ) {
			return array();
		}
		return array_slice( $history, 0, $limit );
	}
}
