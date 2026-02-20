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
		add_action( 'init', array( $this, 'maybe_reschedule' ) );
	}

	/**
	 * Add custom cron schedule(s).
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function add_schedule( array $schedules ): array {
		$presets = $this->config['cron_interval_presets'] ?? array();
		$default_interval = (int) ( $this->config['cron_interval_sec'] ?? 7200 );
		$default_name     = $this->config['cron_schedule_name'] ?? 'd4h_calendar_every_two_hours';

		foreach ( $presets as $interval_seconds => $preset ) {
			$name   = is_array( $preset ) ? ( $preset['name'] ?? 'd4h_calendar_' . ( $interval_seconds / 3600 ) . 'h' ) : $preset;
			$label  = is_array( $preset ) ? ( $preset['label'] ?? sprintf( __( 'Every %d hours', 'd4h-calendar' ), $interval_seconds / 3600 ) ) : sprintf( __( 'Every %d hours', 'd4h-calendar' ), $interval_seconds / 3600 );
			$schedules[ $name ] = array(
				'interval' => (int) $interval_seconds,
				'display'  => $label,
			);
		}

		// Fallback: add default if not in presets.
		if ( ! isset( $schedules[ $default_name ] ) ) {
			$schedules[ $default_name ] = array(
				'interval' => $default_interval,
				'display'  => sprintf(
					/* translators: %d: interval in hours */
					__( 'Every %d hours', 'd4h-calendar' ),
					$default_interval / 3600
				),
			);
		}
		return $schedules;
	}

	/**
	 * Get effective cron interval and schedule name (option override or config).
	 *
	 * @return array{interval: int, schedule_name: string}
	 */
	private function get_effective_cron_config(): array {
		$option_key  = $this->config['option_cron_interval_sec'] ?? 'd4h_calendar_cron_interval_sec';
		$config_sec  = (int) ( $this->config['cron_interval_sec'] ?? 7200 );
		$config_name = $this->config['cron_schedule_name'] ?? 'd4h_calendar_every_two_hours';
		$presets     = $this->config['cron_interval_presets'] ?? array();

		$option_sec = get_option( $option_key, 0 );
		$option_sec = $option_sec !== '' && $option_sec !== false ? (int) $option_sec : 0;

		if ( $option_sec > 0 && isset( $presets[ $option_sec ] ) ) {
			$preset = $presets[ $option_sec ];
			return array(
				'interval'      => $option_sec,
				'schedule_name' => is_array( $preset ) ? ( $preset['name'] ?? $config_name ) : $config_name,
			);
		}

		return array(
			'interval'      => $config_sec,
			'schedule_name' => $config_name,
		);
	}

	/** @var string Transient key for overlap lock. */
	private const LOCK_KEY = 'd4h_calendar_sync_lock';

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

		$lock_ttl = (int) ( $this->config['cron_lock_ttl_sec'] ?? 900 );
		if ( get_transient( self::LOCK_KEY ) ) {
			return; // Another sync is already running.
		}
		set_transient( self::LOCK_KEY, 1, $lock_ttl );

		global $wpdb;
		$config = $this->config;
		$config['table_name'] = $wpdb->prefix . ( $config['table_name_prefix'] ?? 'd4h_calendar_' ) . 'activities';

		$option_error  = $this->config['option_last_sync_error'] ?? 'd4h_calendar_last_sync_error';
		$option_status = $this->config['option_last_sync_status'] ?? 'd4h_calendar_last_sync_status';

		try {
			$database   = new Database( $config );
			$repository = new Repository( $config, $database );
			$api        = new API_Client( $config, $token );
			$sync       = new Sync( $config, $api, $repository );

			$result = $sync->run_full_sync();

			if ( is_wp_error( $result ) ) {
				update_option( $option_error, $result->get_error_message(), false );
				update_option( $option_status, 'error', false );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'D4H Calendar cron sync failed: ' . $result->get_error_message() );
				}
			} else {
				delete_option( $option_error );
				update_option( $option_status, 'success', false );
			}
		} catch ( \Throwable $exception ) {
			$message = $exception->getMessage();
			update_option( $option_error, $message, false );
			update_option( $option_status, 'error', false );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'D4H Calendar cron sync exception: ' . $message );
			}
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Schedule the cron event. Call on activation.
	 */
	public function schedule(): void {
		$hook_name   = $this->config['cron_hook'] ?? 'd4h_calendar_sync';
		$effective   = $this->get_effective_cron_config();

		if ( ! wp_next_scheduled( $hook_name ) ) {
			wp_schedule_event( time(), $effective['schedule_name'], $hook_name );
		}
	}

	/**
	 * If config/option schedule or interval changed, unschedule and reschedule. Runs on init.
	 */
	public function maybe_reschedule(): void {
		if ( empty( $this->config['enable_cron'] ) ) {
			return;
		}

		$hook_name   = $this->config['cron_hook'] ?? 'd4h_calendar_sync';
		$effective   = $this->get_effective_cron_config();

		$event = wp_get_scheduled_event( $hook_name );
		if ( ! $event ) {
			return;
		}

		$current_interval = isset( $event->interval ) ? (int) $event->interval : 0;
		$current_schedule = isset( $event->schedule ) ? $event->schedule : '';

		if ( $current_schedule !== $effective['schedule_name'] || $current_interval !== $effective['interval'] ) {
			self::unschedule( $this->config );
			wp_schedule_event( time(), $effective['schedule_name'], $hook_name );
		}
	}

	/**
	 * Clear the cron event. Call on uninstall.
	 */
	public static function unschedule( array $config ): void {
		$hook_name         = $config['cron_hook'] ?? 'd4h_calendar_sync';
		$next_run_timestamp = wp_next_scheduled( $hook_name );
		if ( $next_run_timestamp ) {
			wp_unschedule_event( $next_run_timestamp, $hook_name );
		}
		wp_clear_scheduled_hook( $hook_name );
	}
}
