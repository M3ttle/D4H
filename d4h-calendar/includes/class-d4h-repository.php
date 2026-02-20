<?php
/**
 * Repository: storage for activities (events/exercises). Upsert, query, delete.
 *
 * @package D4H_Calendar
 */

namespace D4H_Calendar;

defined( 'ABSPATH' ) || exit;

final class Repository {

	/** @var array<string, mixed> */
	private $config;

	/** @var Database */
	private $database;

	/**
	 * @param array<string, mixed> $config
	 * @param Database             $database
	 */
	public function __construct( array $config, Database $database ) {
		$this->config   = $config;
		$this->database = $database;
	}

	/**
	 * Replace/insert activities. Uses REPLACE INTO (or delete + insert) for upsert by id + resource_type.
	 *
	 * @param array<int, array{id: string, resource_type: string, starts_at: string, ends_at?: string|null, payload: string}> $items
	 * @return bool|\WP_Error
	 */
	public function replace_activities( array $items ) {
		global $wpdb;
		$table = $this->database->get_table_name();

		if ( empty( $items ) ) {
			return true;
		}

		$now = current_time( 'mysql' );

		foreach ( $items as $item ) {
			$id           = isset( $item['id'] ) ? (string) $item['id'] : '';
			$resource_type = isset( $item['resource_type'] ) ? sanitize_key( $item['resource_type'] ) : '';
			$starts_at    = isset( $item['starts_at'] ) ? sanitize_text_field( $item['starts_at'] ) : '';
			$ends_at      = isset( $item['ends_at'] ) && $item['ends_at'] !== null && $item['ends_at'] !== ''
				? sanitize_text_field( $item['ends_at'] )
				: null;
			$payload      = isset( $item['payload'] ) ? wp_json_encode( $item['payload'] ) : '{}';

			if ( $id === '' || $resource_type === '' || $starts_at === '' ) {
				continue;
			}

			$wpdb->replace(
				$table,
				array(
					'id'            => $id,
					'resource_type' => $resource_type,
					'starts_at'     => $starts_at,
					'ends_at'       => $ends_at,
					'payload'       => $payload,
					'created_at'    => $now,
					'updated_at'    => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( $wpdb->last_error ) {
				return new \WP_Error( 'd4h_db_error', $wpdb->last_error );
			}
		}

		return true;
	}

	/**
	 * Get activities in a date range.
	 *
	 * @param string $from Y-m-d or datetime.
	 * @param string $to   Y-m-d or datetime.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_activities( string $from, string $to ): array {
		global $wpdb;
		$table = $this->database->get_table_name();

		$from = sanitize_text_field( $from );
		$to   = sanitize_text_field( $to );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, resource_type, starts_at, ends_at, payload FROM {$table}
				WHERE starts_at <= %s AND (ends_at IS NULL OR ends_at >= %s)
				ORDER BY starts_at ASC",
				$to,
				$from
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$payload = isset( $row['payload'] ) ? json_decode( $row['payload'], true ) : array();
			$out[] = array(
				'id'            => $row['id'] ?? '',
				'resource_type' => $row['resource_type'] ?? '',
				'starts_at'     => $row['starts_at'] ?? '',
				'ends_at'       => $row['ends_at'] ?? null,
				'payload'       => is_array( $payload ) ? $payload : array(),
			);
		}
		return $out;
	}

	/**
	 * Delete activities older than N days (by starts_at).
	 *
	 * @param int $days
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public function delete_older_than( int $days ) {
		global $wpdb;
		$table   = $this->database->get_table_name();
		$cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE starts_at < %s",
				$cutoff
			)
		);
	}
}
