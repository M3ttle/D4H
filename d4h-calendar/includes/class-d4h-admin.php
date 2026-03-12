<?php
/**
 * Admin: menu, Sync now, update history, and calendar settings. AJAX in Step 3.
 *
 * @package D4H_Calendar
 */

namespace D4H_Calendar;

defined( 'ABSPATH' ) || exit;

final class Admin {

	/** @var array<string, mixed> */
	private $config;

	/** @var Database */
	private $database;

	/** @var Repository */
	private $repository;

	/**
	 * @param array<string, mixed> $config
	 * @param Database             $database
	 * @param Repository           $repository
	 */
	public function __construct( array $config, Database $database, Repository $repository ) {
		$this->config    = $config;
		$this->database  = $database;
		$this->repository = $repository;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		$action_sync   = $this->config['ajax_action_sync'] ?? 'd4h_calendar_ajax_sync';
		$action_delete = $this->config['ajax_action_delete'] ?? 'd4h_calendar_ajax_delete';
		$action_clean  = $this->config['ajax_action_clean'] ?? 'd4h_calendar_ajax_clean';
		add_action( 'wp_ajax_' . $action_sync, array( $this, 'ajax_sync' ) );
		add_action( 'wp_ajax_' . $action_delete, array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_' . $action_clean, array( $this, 'ajax_clean' ) );
	}

	/**
	 * Handles POST: save credentials or run sync.
	 */
	public function handle_post(): void {
		$slug = $this->config['admin_menu_slug'] ?? 'd4h-calendar';
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== $slug ) {
			return;
		}
		if ( ! current_user_can( $this->config['admin_capability'] ?? 'manage_options' ) ) {
			return;
		}
		if ( empty( $_POST ) || ! isset( $_POST['d4h_calendar_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['d4h_calendar_action'] ) );

		if ( $action === 'save_sync_interval' ) {
			if ( wp_verify_nonce( isset( $_POST['d4h_calendar_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_calendar_nonce'] ) ) : '', 'd4h_calendar_save_sync_interval' ) ) {
				$this->save_sync_interval();
			}
		}
		if ( $action === 'save_colors' ) {
			if ( wp_verify_nonce( isset( $_POST['d4h_calendar_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_calendar_nonce'] ) ) : '', 'd4h_calendar_save_colors' ) ) {
				$this->save_colors();
			}
		}
		if ( $action === 'save_calendar_content_height' ) {
			if ( wp_verify_nonce( isset( $_POST['d4h_calendar_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_calendar_nonce'] ) ) : '', 'd4h_calendar_save_calendar_content_height' ) ) {
				$this->save_calendar_content_height();
			}
		}
		if ( $action === 'save_custom_css' ) {
			if ( wp_verify_nonce( isset( $_POST['d4h_calendar_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_calendar_nonce'] ) ) : '', 'd4h_calendar_save_custom_css' ) ) {
				$this->save_custom_css();
			}
		}
	}

	/**
	 * Enqueue admin JS on our settings page only.
	 *
	 * @param string $hook
	 */
	public function enqueue_scripts( string $hook ): void {
		$slug   = $this->config['admin_menu_slug'] ?? 'd4h-calendar';
		$hooks  = array( 'd4h-core_page_' . $slug, 'settings_page_' . $slug );
		$is_our_page = in_array( $hook, $hooks, true );
		$page   = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! $is_our_page && $page !== $slug ) {
			return;
		}

		wp_enqueue_script( 'jquery-ui-sortable' );
		$url = plugin_dir_url( D4H_CALENDAR_PLUGIN_FILE ) . 'admin/admin.js';
		wp_enqueue_script(
			'd4h-calendar-admin',
			$url,
			array( 'jquery', 'jquery-ui-sortable' ),
			D4H_CALENDAR_VERSION,
			true
		);
		wp_localize_script( 'd4h-calendar-admin', 'd4hCalendarAdmin', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'd4h_calendar_admin' ),
			'actionSync'   => $this->config['ajax_action_sync'] ?? 'd4h_calendar_ajax_sync',
			'actionDelete' => $this->config['ajax_action_delete'] ?? 'd4h_calendar_ajax_delete',
			'actionClean'  => $this->config['ajax_action_clean'] ?? 'd4h_calendar_ajax_clean',
			'i18n'         => array(
				'lastSyncStatus' => __( 'Last sync status:', 'd4h-calendar' ),
				'success'        => __( 'Success', 'd4h-calendar' ),
				'error'          => __( 'Error', 'd4h-calendar' ),
				'manual'         => __( 'Manual', 'd4h-calendar' ),
				'manualClean'    => __( 'Manual (clean)', 'd4h-calendar' ),
				'cron'           => __( 'Cron', 'd4h-calendar' ),
				'cronClean'      => __( 'Cron (clean)', 'd4h-calendar' ),
				'updating'       => __( 'Updating...', 'd4h-calendar' ),
				'syncSuccess'    => __( 'Sync completed successfully.', 'd4h-calendar' ),
				'cleanSuccess'   => __( 'Data cleaned and re-fetched. Calendar will show fresh data.', 'd4h-calendar' ),
			),
		) );
	}

	/**
	 * AJAX handler: Update now (run sync).
	 */
	public function ajax_sync(): void {
		check_ajax_referer( 'd4h_calendar_admin', 'nonce' );
		if ( ! current_user_can( $this->config['admin_capability'] ?? 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'd4h-calendar' ) ), 403 );
		}

		$token = function_exists( 'd4h_core_get_token' ) ? d4h_core_get_token() : '';

		if ( $token === '' ) {
			wp_send_json_error( array( 'message' => __( 'API token not set.', 'd4h-calendar' ) ), 400 );
		}

		$api    = new API_Client( $this->config, $token );
		$sync   = new Sync( $this->config, $api, $this->repository );
		$start  = microtime( true );
		$result = $sync->run_full_sync( true ); // Include tags on manual update
		$duration = microtime( true ) - $start;

		$option_error  = $this->config['option_last_sync_error'] ?? 'd4h_calendar_last_sync_error';
		$option_status = $this->config['option_last_sync_status'] ?? 'd4h_calendar_last_sync_status';

		if ( is_wp_error( $result ) ) {
			$error_message = $result->get_error_message();
			update_option( $option_error, $error_message, false );
			update_option( $option_status, 'error', false );
			Sync_History::log_sync( $this->config, 'error', $error_message, 'manual', $duration, null, 'calendar' );
			$entry_time = time();
			$new_entry  = array(
				'time'           => $entry_time,
				'formatted_time' => wp_date( 'j M Y, H:i:s', $entry_time ),
				'status'         => 'error',
				'source'         => 'manual',
				'duration_sec'   => round( $duration, 2 ),
				'error'          => $error_message,
			);
			wp_send_json_error( array(
				'message'           => $error_message,
				'last_sync_status'  => 'error',
				'last_sync_error'   => $error_message,
				'sync_history_entry' => $new_entry,
			), 500 );
		}

		delete_option( $option_error );
		update_option( $option_status, 'success', false );
		Sync_History::log_sync( $this->config, 'success', '', 'manual', $duration, null, 'calendar' );

		$option_name_last_updated = $this->config['option_last_updated'] ?? 'd4h_calendar_last_updated';
		$updated                  = get_option( $option_name_last_updated, 0 );
		$formatted  = $updated ? wp_date( 'j M Y, H:i', $updated ) : __( 'Never', 'd4h-calendar' );

		$entry_time = time();
		$new_entry  = array(
			'time'           => $entry_time,
			'formatted_time' => wp_date( 'j M Y, H:i:s', $entry_time ),
			'status'         => 'success',
			'source'         => 'manual',
			'duration_sec'   => round( $duration, 2 ),
			'error'          => '',
		);

		wp_send_json_success( array(
			'last_updated'       => $formatted,
			'last_updated_ts'    => $updated,
			'last_sync_status'   => 'success',
			'last_sync_error'    => '',
			'sync_history_entry' => $new_entry,
		) );
	}

	/**
	 * AJAX handler: Delete all calendar data and re-fetch from API. Cleans duplicates.
	 */
	public function ajax_clean(): void {
		check_ajax_referer( 'd4h_calendar_admin', 'nonce' );
		if ( ! current_user_can( $this->config['admin_capability'] ?? 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'd4h-calendar' ) ), 403 );
		}

		$token = function_exists( 'd4h_core_get_token' ) ? d4h_core_get_token() : '';
		if ( $token === '' ) {
			wp_send_json_error( array( 'message' => __( 'API token not set.', 'd4h-calendar' ) ), 400 );
		}

		$this->repository->delete_all();

		$api    = new API_Client( $this->config, $token );
		$sync   = new Sync( $this->config, $api, $this->repository );
		$start  = microtime( true );
		$result = $sync->run_full_sync( true );
		$duration = microtime( true ) - $start;

		$option_error  = $this->config['option_last_sync_error'] ?? 'd4h_calendar_last_sync_error';
		$option_status = $this->config['option_last_sync_status'] ?? 'd4h_calendar_last_sync_status';

		if ( is_wp_error( $result ) ) {
			$error_message = $result->get_error_message();
			update_option( $option_error, $error_message, false );
			update_option( $option_status, 'error', false );
			Sync_History::log_sync( $this->config, 'error', $error_message, 'manual_clean', $duration, null, 'calendar' );
			$entry_time = time();
			$new_entry  = array(
				'time'           => $entry_time,
				'formatted_time' => wp_date( 'j M Y, H:i:s', $entry_time ),
				'status'         => 'error',
				'source'         => 'manual_clean',
				'duration_sec'   => round( $duration, 2 ),
				'error'          => $error_message,
			);
			wp_send_json_error( array(
				'message'            => $error_message,
				'last_sync_status'   => 'error',
				'last_sync_error'    => $error_message,
				'sync_history_entry' => $new_entry,
			), 500 );
		}

		delete_option( $option_error );
		update_option( $option_status, 'success', false );
		Sync_History::log_sync( $this->config, 'success', '', 'manual_clean', $duration, null, 'calendar' );

		$option_name_last_updated = $this->config['option_last_updated'] ?? 'd4h_calendar_last_updated';
		$updated                  = get_option( $option_name_last_updated, 0 );
		$formatted  = $updated ? wp_date( 'j M Y, H:i', $updated ) : __( 'Never', 'd4h-calendar' );

		$entry_time = time();
		$new_entry  = array(
			'time'           => $entry_time,
			'formatted_time' => wp_date( 'j M Y, H:i:s', $entry_time ),
			'status'         => 'success',
			'source'         => 'manual_clean',
			'duration_sec'   => round( $duration, 2 ),
			'error'          => '',
		);

		wp_send_json_success( array(
			'last_updated'       => $formatted,
			'last_updated_ts'    => $updated,
			'last_sync_status'   => 'success',
			'last_sync_error'    => '',
			'sync_history_entry' => $new_entry,
		) );
	}

	/**
	 * AJAX handler: Delete data older than retention days.
	 */
	public function ajax_delete(): void {
		check_ajax_referer( 'd4h_calendar_admin', 'nonce' );
		if ( ! current_user_can( $this->config['admin_capability'] ?? 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'd4h-calendar' ) ), 403 );
		}

		if ( empty( $this->config['enable_delete_btn'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Delete is disabled.', 'd4h-calendar' ) ), 400 );
		}

		$days   = (int) ( $this->config['retention_days'] ?? 90 );
		$result = $this->repository->delete_older_than( $days );

		if ( $result === false ) {
			wp_send_json_error( array( 'message' => __( 'Delete failed.', 'd4h-calendar' ) ), 500 );
		}

		wp_send_json_success( array( 'deleted' => $result ) );
	}

	private function save_sync_interval(): void {
		$option_key = $this->config['option_cron_interval_sec'] ?? 'd4h_calendar_cron_interval_sec';
		$presets    = $this->config['cron_interval_presets'] ?? array();
		$raw        = isset( $_POST['d4h_cron_interval_sec'] ) ? (int) $_POST['d4h_cron_interval_sec'] : 0;

		if ( $raw > 0 && isset( $presets[ $raw ] ) ) {
			update_option( $option_key, $raw, false );
		} else {
			delete_option( $option_key );
		}

		if ( ! empty( $this->config['enable_cron'] ) ) {
			Cron::unschedule( $this->config );
			$cron = new Cron( $this->config );
			$cron->schedule();
		}

		$url = add_query_arg( array( 'page' => $this->config['admin_menu_slug'], 'saved' => '1' ), admin_url( defined( 'D4H_CORE_ACTIVE' ) ? 'admin.php' : 'options-general.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function save_calendar_content_height(): void {
		$option_key  = $this->config['option_calendar_content_height'] ?? 'd4h_calendar_content_height';
		$config_default = (int) ( $this->config['calendar_content_height'] ?? 600 );
		$raw         = isset( $_POST['d4h_calendar_content_height'] ) ? (int) $_POST['d4h_calendar_content_height'] : 0;

		$show_description_option = $this->config['option_show_description'] ?? 'd4h_calendar_show_description';
		$show_description_raw    = isset( $_POST['d4h_show_description'] ) ? (int) $_POST['d4h_show_description'] : 0;
		$option_team_manager_url = $this->config['option_team_manager_base_url'] ?? 'd4h_calendar_team_manager_base_url';
		$team_manager_base_url   = isset( $_POST['d4h_team_manager_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['d4h_team_manager_base_url'] ), array( 'https' ) ) : '';
		if ( $team_manager_base_url !== '' ) {
			$team_manager_base_url = rtrim( $team_manager_base_url, '/' );
		}
		update_option( $option_team_manager_url, $team_manager_base_url, false );

		if ( $raw >= 200 && $raw <= 2000 ) {
			update_option( $option_key, $raw, false );
		} else {
			delete_option( $option_key );
		}

		update_option( $show_description_option, $show_description_raw === 1 ? 1 : 0, false );

		$url = add_query_arg( array( 'page' => $this->config['admin_menu_slug'], 'saved' => '1' ), admin_url( defined( 'D4H_CORE_ACTIVE' ) ? 'admin.php' : 'options-general.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function save_custom_css(): void {
		$option_key = $this->config['option_custom_css'] ?? 'd4h_calendar_custom_css';
		$custom_css = isset( $_POST['d4h_custom_css'] ) ? wp_strip_all_tags( wp_unslash( $_POST['d4h_custom_css'] ) ) : '';

		update_option( $option_key, $custom_css, false );

		$url = add_query_arg( array( 'page' => $this->config['admin_menu_slug'], 'saved' => '1' ), admin_url( defined( 'D4H_CORE_ACTIVE' ) ? 'admin.php' : 'options-general.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function save_colors(): void {
		$option_event    = $this->config['option_event_color'] ?? 'd4h_calendar_event_color';
		$option_exercise = $this->config['option_exercise_color'] ?? 'd4h_calendar_exercise_color';
		$option_tag_colors = $this->config['option_tag_colors'] ?? 'd4h_calendar_tag_colors';

		$event_color = isset( $_POST['d4h_event_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['d4h_event_color'] ) ) : '';
		$exercise_color = isset( $_POST['d4h_exercise_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['d4h_exercise_color'] ) ) : '';

		if ( $event_color ) {
			update_option( $option_event, $event_color, false );
		} else {
			delete_option( $option_event );
		}
		if ( $exercise_color ) {
			update_option( $option_exercise, $exercise_color, false );
		} else {
			delete_option( $option_exercise );
		}

		$tag_colors   = array();
		$tag_priority = array();
		$option_tag_priority = $this->config['option_tag_priority'] ?? 'd4h_calendar_tag_priority';
		$raw_tags     = isset( $_POST['d4h_tag_colors'] ) && is_array( $_POST['d4h_tag_colors'] ) ? $_POST['d4h_tag_colors'] : array();
		foreach ( $raw_tags as $tag_name => $hex ) {
			$name  = sanitize_text_field( $tag_name );
			$color = sanitize_hex_color( wp_unslash( $hex ) );
			if ( $name !== '' && $color ) {
				$tag_colors[ $name ] = $color;
				$tag_priority[]      = $name;
			}
		}
		update_option( $option_tag_colors, $tag_colors, false );
		update_option( $option_tag_priority, $tag_priority, false );

		$url = add_query_arg( array( 'page' => $this->config['admin_menu_slug'], 'saved' => '1' ), admin_url( defined( 'D4H_CORE_ACTIVE' ) ? 'admin.php' : 'options-general.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Registers the admin menu and page.
	 */
	public function add_menu_page(): void {
		$capability = $this->config['admin_capability'] ?? 'manage_options';
		$slug       = $this->config['admin_menu_slug'] ?? 'd4h-calendar';
		$page_title = $this->config['admin_page_title'] ?? 'D4H Calendar';
		$menu_title = $this->config['admin_menu_title'] ?? 'D4H Calendar';

		if ( defined( 'D4H_CORE_ACTIVE' ) ) {
			add_submenu_page(
				'd4h-core',
				$page_title,
				$menu_title,
				$capability,
				$slug,
				array( $this, 'render_page' )
			);
		} else {
			add_options_page(
				$page_title,
				$menu_title,
				$capability,
				$slug,
				array( $this, 'render_page' )
			);
		}
	}

	/**
	 * Renders the admin page: API credentials form, Sync now, Last updated.
	 */
	public function render_page(): void {
		$option_name_last_updated = $this->config['option_last_updated'] ?? 'd4h_calendar_last_updated';

		$updated = get_option( $option_name_last_updated, 0 );

		$page_title = esc_html( $this->config['admin_page_title'] ?? 'D4H Calendar' );

		$saved = isset( $_GET['saved'] ) && $_GET['saved'] === '1';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		?>
		<div class="wrap">
			<h1><?php echo $page_title; ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'd4h-calendar' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Update', 'd4h-calendar' ); ?></h2>
			<?php
			$option_error  = $this->config['option_last_sync_error'] ?? 'd4h_calendar_last_sync_error';
			$option_status = $this->config['option_last_sync_status'] ?? 'd4h_calendar_last_sync_status';
			$last_status   = get_option( $option_status, '' );
			$last_error    = get_option( $option_error, '' );
			?>
			<div id="d4h-last-sync-status" class="d4h-last-sync-status">
				<?php if ( $last_status === 'error' && $last_error ) : ?>
					<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'Last sync status:', 'd4h-calendar' ); ?></strong> <span id="d4h-last-sync-status-text"><?php echo esc_html( $last_error ); ?></span></p></div>
				<?php elseif ( $last_status === 'success' ) : ?>
					<p><strong><?php esc_html_e( 'Last sync status:', 'd4h-calendar' ); ?></strong> <span id="d4h-last-sync-status-text"><?php esc_html_e( 'Success', 'd4h-calendar' ); ?></span></p>
				<?php else : ?>
					<p><strong><?php esc_html_e( 'Last sync status:', 'd4h-calendar' ); ?></strong> <span id="d4h-last-sync-status-text"><?php esc_html_e( '—', 'd4h-calendar' ); ?></span></p>
				<?php endif; ?>
			</div>
			<p><strong><?php esc_html_e( 'Last updated:', 'd4h-calendar' ); ?></strong>
				<span id="d4h-last-updated"><?php echo $updated ? esc_html( wp_date( 'j M Y, H:i', $updated ) ) : esc_html__( 'Never', 'd4h-calendar' ); ?></span>
			</p>
			<p>
				<button type="button" id="d4h-update-now" class="button button-secondary"><?php esc_html_e( 'Update calendar', 'd4h-calendar' ); ?></button>
				<button type="button" id="d4h-clean-data" class="button button-secondary"><?php esc_html_e( 'Clean data', 'd4h-calendar' ); ?></button>
				<?php if ( ! empty( $this->config['enable_delete_btn'] ) ) : ?>
					<?php $retention = (int) ( $this->config['retention_days'] ?? 90 ); ?>
					<button type="button" id="d4h-delete-old" class="button button-secondary"><?php echo esc_html( sprintf( __( 'Delete data older than %d days', 'd4h-calendar' ), $retention ) ); ?></button>
				<?php endif; ?>
			</p>
			<p class="description"><?php esc_html_e( 'Clean data: deletes all events/exercises and re-fetches from D4H. Use to remove duplicates. A cron job does this automatically every 12 hours.', 'd4h-calendar' ); ?></p>
			<div id="d4h-admin-message" class="notice" style="display:none;"></div>

			<h3><?php esc_html_e( 'Update history', 'd4h-calendar' ); ?></h3>
			<?php
			$sync_history = Sync_History::get_history( $this->config, 100 );
			?>
			<table class="wp-list-table widefat fixed striped" id="d4h-sync-history-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Time', 'd4h-calendar' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'd4h-calendar' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Source', 'd4h-calendar' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Duration', 'd4h-calendar' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Error', 'd4h-calendar' ); ?></th>
						</tr>
					</thead>
					<tbody id="d4h-sync-history-tbody">
						<?php if ( empty( $sync_history ) ) : ?>
							<tr id="d4h-sync-history-empty-row"><td colspan="5" class="description"><?php esc_html_e( 'No sync runs recorded yet.', 'd4h-calendar' ); ?></td></tr>
						<?php else : ?>
						<?php foreach ( $sync_history as $entry ) : ?>
							<?php
							$time         = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
							$status       = isset( $entry['status'] ) ? $entry['status'] : '';
							$source       = isset( $entry['source'] ) ? $entry['source'] : '';
							$duration_sec = isset( $entry['duration_sec'] ) ? (float) $entry['duration_sec'] : null;
							$error        = isset( $entry['error'] ) ? $entry['error'] : '';
							?>
							<tr>
								<td><?php echo esc_html( $time ? wp_date( 'j M Y, H:i:s', $time ) : '—' ); ?></td>
								<td>
									<?php if ( $status === 'success' ) : ?>
										<span style="color:#00a32a;"><?php esc_html_e( 'Success', 'd4h-calendar' ); ?></span>
									<?php else : ?>
										<span style="color:#d63638;"><?php esc_html_e( 'Error', 'd4h-calendar' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php
								$source_label = ( $source === 'cron' || $source === 'cron_clean' )
									? ( $source === 'cron_clean' ? __( 'Cron (clean)', 'd4h-calendar' ) : __( 'Cron', 'd4h-calendar' ) )
									: ( $source === 'manual_clean' ? __( 'Manual (clean)', 'd4h-calendar' ) : __( 'Manual', 'd4h-calendar' ) );
								echo esc_html( $source_label );
								?></td>
								<td><?php echo $duration_sec !== null ? esc_html( number_format( $duration_sec, 2 ) . ' s' ) : '—'; ?></td>
								<td><?php echo $error ? esc_html( $error ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			<p class="description"><?php esc_html_e( 'Latest 100 updates.', 'd4h-calendar' ); ?></p>

			<hr />

			<h2><?php esc_html_e( 'Sync interval', 'd4h-calendar' ); ?></h2>
			<?php
			$option_interval  = $this->config['option_cron_interval_sec'] ?? 'd4h_calendar_cron_interval_sec';
			$config_interval  = (int) ( $this->config['cron_interval_sec'] ?? 7200 );
			$current_interval = (int) get_option( $option_interval, 0 );
			$effective_interval = $current_interval > 0 ? $current_interval : $config_interval;
			$presets = $this->config['cron_interval_presets'] ?? array();
			?>
			<form method="post" action="">
				<?php wp_nonce_field( 'd4h_calendar_save_sync_interval', 'd4h_calendar_nonce' ); ?>
				<input type="hidden" name="d4h_calendar_action" value="save_sync_interval" />
				<label for="d4h_cron_interval_sec"><?php esc_html_e( 'Sync interval:', 'd4h-calendar' ); ?></label>
				<select id="d4h_cron_interval_sec" name="d4h_cron_interval_sec">
					<option value="0" <?php selected( $current_interval, 0 ); ?>><?php esc_html_e( 'Use config default', 'd4h-calendar' ); ?></option>
					<?php foreach ( $presets as $sec => $preset ) : ?>
						<?php $label = is_array( $preset ) ? ( $preset['label'] ?? $sec . 's' ) : $sec . 's'; ?>
						<option value="<?php echo (int) $sec; ?>" <?php selected( $current_interval, (int) $sec ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button button-secondary"><?php esc_attr_e( 'Save interval', 'd4h-calendar' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'How often the calendar syncs with D4H (when using cron).', 'd4h-calendar' ); ?></p>

			<hr />

			<h2><?php esc_html_e( 'Calendar display', 'd4h-calendar' ); ?></h2>
			<?php
		$option_content_height  = $this->config['option_calendar_content_height'] ?? 'd4h_calendar_content_height';
		$config_content_height  = (int) ( $this->config['calendar_content_height'] ?? 600 );
		$current_content_height = (int) get_option( $option_content_height, 0 );
		$effective_content_height = $current_content_height >= 200 ? $current_content_height : $config_content_height;
		$option_show_description = $this->config['option_show_description'] ?? 'd4h_calendar_show_description';
		$show_description_value  = get_option( $option_show_description, 1 );
		$option_team_manager_url = $this->config['option_team_manager_base_url'] ?? 'd4h_calendar_team_manager_base_url';
		$team_manager_base_url   = get_option( $option_team_manager_url, '' );
		?>
			<form method="post" action="">
				<?php wp_nonce_field( 'd4h_calendar_save_calendar_content_height', 'd4h_calendar_nonce' ); ?>
				<input type="hidden" name="d4h_calendar_action" value="save_calendar_content_height" />
				<table class="form-table">
					<tr>
						<th scope="row"><label for="d4h_calendar_content_height"><?php esc_html_e( 'Content height (px)', 'd4h-calendar' ); ?></label></th>
						<td>
							<input type="number" id="d4h_calendar_content_height" name="d4h_calendar_content_height" value="<?php echo esc_attr( $effective_content_height ); ?>" min="0" max="2000" step="50" class="small-text" />
							<span class="description"><?php echo esc_html( sprintf( __( '200–2000 px. Enter 0 to use config default (%d).', 'd4h-calendar' ), $config_content_height ) ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Event description', 'd4h-calendar' ); ?></th>
						<td>
							<label for="d4h_show_description">
								<input type="checkbox" id="d4h_show_description" name="d4h_show_description" value="1" <?php checked( (int) $show_description_value, 1 ); ?> />
								<?php esc_html_e( 'Show description in event popup', 'd4h-calendar' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'If disabled, the event popup will hide the description text.', 'd4h-calendar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="d4h_team_manager_base_url"><?php esc_html_e( 'D4H Team Manager base URL', 'd4h-calendar' ); ?></label></th>
						<td>
							<input type="url" id="d4h_team_manager_base_url" name="d4h_team_manager_base_url" value="<?php echo esc_attr( $team_manager_base_url ); ?>" class="regular-text" placeholder="https://xxx.team-manager.us.d4h.com" />
							<p class="description"><?php esc_html_e( 'Base URL for D4H Team Manager (e.g. https://xxx.team-manager.us.d4h.com). If set, event popups will include a link to view the event in D4H.', 'd4h-calendar' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit"><input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save', 'd4h-calendar' ); ?>" /></p>
			</form>
			<p class="description"><?php esc_html_e( 'Height of the calendar content area in pixels.', 'd4h-calendar' ); ?></p>

			<hr />

			<h2><?php esc_html_e( 'Event colors', 'd4h-calendar' ); ?></h2>
			<?php
			$option_event_color   = $this->config['option_event_color'] ?? 'd4h_calendar_event_color';
			$option_exercise_color= $this->config['option_exercise_color'] ?? 'd4h_calendar_exercise_color';
			$option_tag_colors    = $this->config['option_tag_colors'] ?? 'd4h_calendar_tag_colors';
			$option_tag_priority  = $this->config['option_tag_priority'] ?? 'd4h_calendar_tag_priority';
			$event_color   = get_option( $option_event_color, $this->config['calendar_event_color'] ?? '#3788d8' );
			$exercise_color= get_option( $option_exercise_color, $this->config['calendar_exercise_color'] ?? '#6c757d' );
			$tag_colors    = get_option( $option_tag_colors, array() );
			$tag_colors    = is_array( $tag_colors ) ? $tag_colors : array();
			$tag_priority  = get_option( $option_tag_priority, array() );
			$tag_priority  = is_array( $tag_priority ) ? $tag_priority : array();
			$tags_map      = get_option( $this->config['option_tags_map'] ?? 'd4h_calendar_tags_map', array() );
			$tags_map      = is_array( $tags_map ) ? $tags_map : array();
			$synced_names  = array_values( array_filter( array_map( 'trim', $tags_map ) ) );
			$all_tag_names = array_values( array_unique( array_merge( $synced_names, array_keys( $tag_colors ) ) ) );
			// Order: priority list first (preserving order), then new tags alphabetically.
			$tag_names = array();
			foreach ( $tag_priority as $name ) {
				if ( in_array( $name, $all_tag_names, true ) ) {
					$tag_names[] = $name;
				}
			}
			$remaining = array_diff( $all_tag_names, $tag_names );
			sort( $remaining );
			$tag_names = array_merge( $tag_names, $remaining );
			?>
			<p class="description"><?php esc_html_e( 'Set colors by type (event/exercise) or by tag. Tag colors override type colors. Drag tags to set priority: when an event has multiple tags, the first in the list wins.', 'd4h-calendar' ); ?></p>
			<form method="post" action="">
				<?php wp_nonce_field( 'd4h_calendar_save_colors', 'd4h_calendar_nonce' ); ?>
				<input type="hidden" name="d4h_calendar_action" value="save_colors" />
				<table class="form-table">
					<tr>
						<th scope="row"><label for="d4h_event_color"><?php esc_html_e( 'Event', 'd4h-calendar' ); ?></label></th>
						<td>
							<input type="color" id="d4h_event_color" name="d4h_event_color" value="<?php echo esc_attr( $event_color ); ?>" />
							<input type="text" class="small-text d4h-hex-input" value="<?php echo esc_attr( $event_color ); ?>" aria-label="<?php esc_attr_e( 'Event color hex', 'd4h-calendar' ); ?>" style="margin-left: 8px; width: 7em;" data-color-for="d4h_event_color" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="d4h_exercise_color"><?php esc_html_e( 'Exercise', 'd4h-calendar' ); ?></label></th>
						<td>
							<input type="color" id="d4h_exercise_color" name="d4h_exercise_color" value="<?php echo esc_attr( $exercise_color ); ?>" />
							<input type="text" class="small-text d4h-hex-input" value="<?php echo esc_attr( $exercise_color ); ?>" aria-label="<?php esc_attr_e( 'Exercise color hex', 'd4h-calendar' ); ?>" style="margin-left: 8px; width: 7em;" data-color-for="d4h_exercise_color" />
						</td>
					</tr>
					<?php if ( ! empty( $tag_names ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Tags', 'd4h-calendar' ); ?></th>
							<td style="padding: 0;">
								<style>.d4h-tags-colors-sortable{list-style:none;margin:0;padding:0;}.d4h-tag-color-item{display:flex;align-items:center;gap:4px;min-width:0;padding:4px 0;}.d4h-tag-color-item .d4h-drag-handle{cursor:grab;color:#72777c;padding:0 4px;}.d4h-tag-color-item .d4h-drag-handle:hover{color:#1d2327;}.d4h-tag-color-item label{margin:0;padding:0;width:8em;min-width:8em;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:400;}.d4h-tag-color-item input[type="color"]{flex-shrink:0;width:28px;height:24px;}.d4h-tag-color-item .d4h-hex-input{width:5.5em;flex-shrink:0;min-width:0;}.ui-sortable-placeholder{visibility:visible!important;border:1px dashed #c3c4c7;background:#f0f0f1;height:2.5em;}</style>
								<ul id="d4h-tags-sortable" class="d4h-tags-colors-sortable">
									<?php foreach ( $tag_names as $tag_name ) : ?>
										<?php
										$current = isset( $tag_colors[ $tag_name ] ) ? $tag_colors[ $tag_name ] : '#3788d8';
										$input_id = 'd4h_tag_color_' . sanitize_key( $tag_name );
										?>
										<li class="d4h-tag-color-item">
											<span class="d4h-drag-handle dashicons dashicons-move" aria-hidden="true"></span>
											<label for="<?php echo esc_attr( $input_id ); ?>" title="<?php echo esc_attr( $tag_name ); ?>"><?php echo esc_html( $tag_name ); ?></label>
											<input type="color" id="<?php echo esc_attr( $input_id ); ?>" name="d4h_tag_colors[<?php echo esc_attr( $tag_name ); ?>]" value="<?php echo esc_attr( $current ); ?>" />
											<input type="text" class="small-text d4h-hex-input" value="<?php echo esc_attr( $current ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Color for tag %s', 'd4h-calendar' ), $tag_name ) ); ?>" data-color-for="<?php echo esc_attr( $input_id ); ?>" />
										</li>
									<?php endforeach; ?>
								</ul>
							</td>
						</tr>
					<?php else : ?>
						<tr>
							<td colspan="2" class="description"><?php esc_html_e( 'No tags yet. Run "Update now" to sync tags from D4H.', 'd4h-calendar' ); ?></td>
						</tr>
					<?php endif; ?>
				</table>
				<p class="submit"><input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save colors', 'd4h-calendar' ); ?>" /></p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Custom CSS', 'd4h-calendar' ); ?></h2>
			<?php
			$option_custom_css = $this->config['option_custom_css'] ?? 'd4h_calendar_custom_css';
			$custom_css       = get_option( $option_custom_css, '' );
			?>
			<form method="post" action="">
				<?php wp_nonce_field( 'd4h_calendar_save_custom_css', 'd4h_calendar_nonce' ); ?>
				<input type="hidden" name="d4h_calendar_action" value="save_custom_css" />
				<p class="description"><?php esc_html_e( 'Add custom CSS to style the calendar. It will be loaded after the plugin stylesheet.', 'd4h-calendar' ); ?></p>
				<textarea id="d4h_custom_css" name="d4h_custom_css" rows="12" class="large-text code" style="font-family: Consolas, Monaco, monospace;"><?php echo esc_textarea( $custom_css ); ?></textarea>
				<p class="submit"><input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save custom CSS', 'd4h-calendar' ); ?>" /></p>
			</form>
		</div>
		<?php
	}
}
