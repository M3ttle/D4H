<?php
/**
 * Bootstrap: reads config and wires plugin components.
 *
 * @package D4H_Calendar
 */

namespace D4H_Calendar;

defined( 'ABSPATH' ) || exit;

final class Loader {

	/** @var array<string, mixed> */
	private $config;

	/** @var Database|null */
	private $database;

	/** @var Repository|null */
	private $repository;

	/** @var Admin|null */
	private $admin;

	/**
	 * @param array<string, mixed> $config From d4h_calendar_get_config().
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function init(): void {
		$this->config['table_name'] = $this->get_table_name();

		$this->database   = new Database( $this->config );
		$this->repository = new Repository( $this->config, $this->database );
		$this->admin      = new Admin( $this->config, $this->database, $this->repository );

		$this->admin->register_hooks();
	}

	private function get_table_name(): string {
		global $wpdb;
		$prefix = $this->config['table_name_prefix'] ?? 'd4h_calendar_';
		return $wpdb->prefix . $prefix . 'activities';
	}
}
