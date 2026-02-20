<?php
/**
 * Database: custom table schema and table name. Storage methods in Repository.
 *
 * @package D4H_Calendar
 */

namespace D4H_Calendar;

defined( 'ABSPATH' ) || exit;

final class Database {

	/** @var array<string, mixed> */
	private $config;

	/**
	 * @param array<string, mixed> $config Must include 'table_name'.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Returns the full custom table name (from config).
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		$name = $this->config['table_name'] ?? '';
		return (string) $name;
	}

	/**
	 * Create or update the activities table. Uses dbDelta.
	 *
	 * @return void
	 */
	public function maybe_create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = $this->get_table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id varchar(64) NOT NULL,
			resource_type varchar(32) NOT NULL,
			starts_at datetime NOT NULL,
			ends_at datetime DEFAULT NULL,
			payload longtext,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id, resource_type),
			KEY starts_at (starts_at),
			KEY ends_at (ends_at)
		) {$charset};";

		dbDelta( $sql );
	}
}
