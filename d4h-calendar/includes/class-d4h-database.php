<?php
/**
 * Database: custom table name and schema (schema created in Step 2).
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
	 * Create or update the activities table. Called on activation; schema defined in Step 2.
	 *
	 * @return void
	 */
	public function maybe_create_tables(): void {
		// Schema and dbDelta will be added in Step 2.
	}
}
