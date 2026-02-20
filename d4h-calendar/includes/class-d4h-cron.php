<?php
/**
 * Cron: custom interval, schedule sync event, run sync on hook.
 *
 * @package D4H_Calendar
 */

namespace D4H_Calendar;

defined( 'ABSPATH' ) || exit;

final class Cron {

	/** @var array<string, mixed> */
	private $config;

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
		add_action( $this->config['cron_hook'] ?? 'd4h_calendar_sync', array( $this, 'run_sync' ) );
	}

	/**
	 * Add custom cron schedule.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function add_schedule( array $schedules ): array {
		$name   = $this->config['cron_schedule_name'] ?? 'd4h_calendar_every_two_hours';
		$sec    = (int) ( $this->config['cron_interval_sec'] ?? 7200 );

		$schedules[ $name ] = array(
			'interval' => $sec,
			'display'  => sprintf(
				/* translators: %d: interval in hours */
				__( 'Every %d hours', 'd4h-calendar' ),
				$sec / 3600
			),
		);
		return $schedules;
	}

	/**
	 * Cron callback: run full sync.
	 */
	public function run_sync(): void {
		if ( empty( $this->config['enable_cron'] ) ) {
			return;
		}

		$token = get_option( $this->config['option_token'] ?? 'd4h_calendar_api_token', '' );
		if ( $token === '' ) {
			return;
		}

		global $wpdb;
		$config = $this->config;
		$config['table_name'] = $wpdb->prefix . ( $config['table_name_prefix'] ?? 'd4h_calendar_' ) . 'activities';

		$database   = new Database( $config );
		$repository = new Repository( $config, $database );
		$api        = new API_Client( $config, $token );
		$sync       = new Sync( $config, $api, $repository );

		$sync->run_full_sync();
	}

	/**
	 * Schedule the cron event. Call on activation.
	 */
	public function schedule(): void {
		$hook   = $this->config['cron_hook'] ?? 'd4h_calendar_sync';
		$name   = $this->config['cron_schedule_name'] ?? 'd4h_calendar_every_two_hours';

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), $name, $hook );
		}
	}

	/**
	 * Clear the cron event. Call on uninstall.
	 */
	public static function unschedule( array $config ): void {
		$hook = $config['cron_hook'] ?? 'd4h_calendar_sync';
		$ts   = wp_next_scheduled( $hook );
		if ( $ts ) {
			wp_unschedule_event( $ts, $hook );
		}
		wp_clear_scheduled_hook( $hook );
	}
}
