<?php
/**
 * Admin: menu, API credentials form, and Sync now (Step 2). AJAX in Step 3.
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
		add_action( 'admin_init', array( $this, 'handle_check_updates' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		$action_sync   = $this->config['ajax_action_sync'] ?? 'd4h_calendar_ajax_sync';
		$action_delete = $this->config['ajax_action_delete'] ?? 'd4h_calendar_ajax_delete';
		add_action( 'wp_ajax_' . $action_sync, array( $this, 'ajax_sync' ) );
		add_action( 'wp_ajax_' . $action_delete, array( $this, 'ajax_delete' ) );
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

		if ( $action === 'save_credentials' ) {
			if ( wp_verify_nonce( isset( $_POST['d4h_calendar_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_calendar_nonce'] ) ) : '', 'd4h_calendar_save_credentials' ) ) {
				$this->save_credentials();
			}
		}
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
		if ( $action === 'save_github_token' ) {
			if ( wp_verify_nonce( isset( $_POST['d4h_calendar_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_calendar_nonce'] ) ) : '', 'd4h_calendar_save_github_token' ) ) {
				$this->save_github_token();
			}
		}
		if ( $action === 'save_calendar_content_height' ) {
			if ( wp_verify_nonce( isset( $_POST['d4h_calendar_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_calendar_nonce'] ) ) : '', 'd4h_calendar_save_calendar_content_height' ) ) {
				$this->save_calendar_content_height();
			}
		}
	}

	/**
	 * Handles "Check for plugin updates" link: clears cached update data and redirects to Updates page.
	 */
	public function handle_check_updates(): void {
		$slug = $this->config['admin_menu_slug'] ?? 'd4h-calendar';
		if ( isset( $_GET['page'] ) && sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== $slug ) {
			return;
		}
		if ( ! isset( $_GET['check_updates'] ) || $_GET['check_updates'] !== '1' ) {
			return;
		}
		if ( ! current_user_can( $this->config['admin_capability'] ?? 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'd4h_check_updates' ) ) {
			return;
		}
		delete_site_transient( 'update_plugins' );
		wp_clean_plugins_cache( false );
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'update-core.php' ) );
		exit;
	}

	/**
	 * Enqueue admin JS on our settings page only.
	 *
	 * @param string $hook
	 */
	public function enqueue_scripts( string $hook ): void {
		$slug = $this->config['admin_menu_slug'] ?? 'd4h-calendar';
		if ( $hook !== 'settings_page_' . $slug ) {
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

		$option_name_token = $this->config['option_token'] ?? 'd4h_calendar_api_token';
		$token             = get_option( $option_name_token, '' );

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
			Sync_History::log_sync( $this->config, 'error', $error_message, 'manual', $duration );
			wp_send_json_error( array( 'message' => $error_message ), 500 );
		}

		delete_option( $option_error );
		update_option( $option_status, 'success', false );
		Sync_History::log_sync( $this->config, 'success', '', 'manual', $duration );

		$option_name_last_updated = $this->config['option_last_updated'] ?? 'd4h_calendar_last_updated';
		$updated                  = get_option( $option_name_last_updated, 0 );
		$formatted  = $updated ? wp_date( 'j M Y, H:i', $updated ) : __( 'Never', 'd4h-calendar' );

		wp_send_json_success( array( 'last_updated' => $formatted, 'last_updated_ts' => $updated ) );
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

	private function save_credentials(): void {
		$option_name_token   = $this->config['option_token'] ?? 'd4h_calendar_api_token';
		$option_name_org     = $this->config['option_context'] ?? 'd4h_calendar_api_org';
		$option_name_org_id  = $this->config['option_context_id'] ?? 'd4h_calendar_api_org_id';

		$token   = isset( $_POST['d4h_api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_api_token'] ) ) : '';
		$org_type = isset( $_POST['d4h_api_context'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_api_context'] ) ) : '';
		$org_id   = isset( $_POST['d4h_api_context_id'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_api_context_id'] ) ) : '';

		update_option( $option_name_token, $token, false );
		update_option( $option_name_org, $org_type, false );
		update_option( $option_name_org_id, $org_id, false );

		$url = add_query_arg( array( 'page' => $this->config['admin_menu_slug'], 'saved' => '1' ), admin_url( 'options-general.php' ) );
		wp_safe_redirect( $url );
		exit;
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

		$url = add_query_arg( array( 'page' => $this->config['admin_menu_slug'], 'saved' => '1' ), admin_url( 'options-general.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function save_github_token(): void {
		$option_key = $this->config['option_github_token'] ?? 'd4h_calendar_github_token';
		$token      = isset( $_POST['d4h_github_token'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_github_token'] ) ) : '';
		if ( $token !== '' ) {
			update_option( $option_key, $token, false );
		} else {
			delete_option( $option_key );
		}
		$url = add_query_arg( array( 'page' => $this->config['admin_menu_slug'], 'saved' => '1' ), admin_url( 'options-general.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function save_calendar_content_height(): void {
		$option_key  = $this->config['option_calendar_content_height'] ?? 'd4h_calendar_content_height';
		$config_default = (int) ( $this->config['calendar_content_height'] ?? 600 );
		$raw         = isset( $_POST['d4h_calendar_content_height'] ) ? (int) $_POST['d4h_calendar_content_height'] : 0;

		if ( $raw >= 200 && $raw <= 2000 ) {
			update_option( $option_key, $raw, false );
		} else {
			delete_option( $option_key );
		}

		$url = add_query_arg( array( 'page' => $this->config['admin_menu_slug'], 'saved' => '1' ), admin_url( 'options-general.php' ) );
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

		$url = add_query_arg( array( 'page' => $this->config['admin_menu_slug'], 'saved' => '1' ), admin_url( 'options-general.php' ) );
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

		add_options_page(
			$page_title,
			$menu_title,
			$capability,
			$slug,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the admin page: API credentials form, Sync now, Last updated.
	 */
	public function render_page(): void {
		$option_name_token      = $this->config['option_token'] ?? 'd4h_calendar_api_token';
		$option_name_org        = $this->config['option_context'] ?? 'd4h_calendar_api_org';
		$option_name_org_id     = $this->config['option_context_id'] ?? 'd4h_calendar_api_org_id';
		$option_name_last_updated = $this->config['option_last_updated'] ?? 'd4h_calendar_last_updated';

		$token    = get_option( $option_name_token, '' );
		$org_type = get_option( $option_name_org, '' );
		$org_id   = get_option( $option_name_org_id, '' );
		$updated  = get_option( $option_name_last_updated, 0 );

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
			<?php if ( $last_status === 'error' && $last_error ) : ?>
				<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'Last sync status:', 'd4h-calendar' ); ?></strong> <?php echo esc_html( $last_error ); ?></p></div>
			<?php elseif ( $last_status === 'success' ) : ?>
				<p><strong><?php esc_html_e( 'Last sync status:', 'd4h-calendar' ); ?></strong> <?php esc_html_e( 'Success', 'd4h-calendar' ); ?></p>
			<?php endif; ?>
			<p><strong><?php esc_html_e( 'Last updated:', 'd4h-calendar' ); ?></strong>
				<span id="d4h-last-updated"><?php echo $updated ? esc_html( wp_date( 'j M Y, H:i', $updated ) ) : esc_html__( 'Never', 'd4h-calendar' ); ?></span>
			</p>
			<p>
				<button type="button" id="d4h-update-now" class="button button-secondary"><?php esc_html_e( 'Retrieve Calendar data', 'd4h-calendar' ); ?></button>
				<?php if ( ! empty( $this->config['update_github_repo'] ) ) : ?>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => $this->config['admin_menu_slug'], 'check_updates' => '1' ), admin_url( 'options-general.php' ) ), 'd4h_check_updates' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Check for plugin updates', 'd4h-calendar' ); ?></a>
				<?php endif; ?>
				<?php if ( ! empty( $this->config['enable_delete_btn'] ) ) : ?>
					<?php $retention = (int) ( $this->config['retention_days'] ?? 90 ); ?>
					<button type="button" id="d4h-delete-old" class="button button-secondary"><?php echo esc_html( sprintf( __( 'Delete data older than %d days', 'd4h-calendar' ), $retention ) ); ?></button>
				<?php endif; ?>
			</p>
			<?php if ( ! empty( $this->config['update_github_repo'] ) ) : ?>
				<?php
				$option_github   = $this->config['option_github_token'] ?? 'd4h_calendar_github_token';
				$has_const_token = defined( 'D4H_CALENDAR_GITHUB_TOKEN' );
				$has_opt_token   = ! $has_const_token && get_option( $option_github, '' ) !== '';
				?>
				<p style="margin-top: 1em;">
					<button type="button" id="d4h-github-token-toggle" class="button-link" aria-expanded="false" aria-controls="d4h-github-token-form">
						<span class="d4h-toggle-show"><?php esc_html_e( 'Show GitHub API token', 'd4h-calendar' ); ?></span>
						<span class="d4h-toggle-hide" style="display:none;"><?php esc_html_e( 'Hide GitHub API token', 'd4h-calendar' ); ?></span>
					</button>
				</p>
				<form id="d4h-github-token-form" method="post" action="" style="margin-top: 0.5em; display:none;" aria-hidden="true">
					<?php wp_nonce_field( 'd4h_calendar_save_github_token', 'd4h_calendar_nonce' ); ?>
					<input type="hidden" name="d4h_calendar_action" value="save_github_token" />
					<label for="d4h_github_token"><?php esc_html_e( 'GitHub API token (optional)', 'd4h-calendar' ); ?></label>
					<input type="password" id="d4h_github_token" name="d4h_github_token" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $has_const_token ? esc_attr__( 'Set via wp-config.php', 'd4h-calendar' ) : ( $has_opt_token ? esc_attr__( 'Token saved — enter new to replace', 'd4h-calendar' ) : '' ); ?>" <?php echo $has_const_token ? ' readonly' : ''; ?> />
					<button type="submit" class="button button-secondary"<?php echo $has_const_token ? ' disabled' : ''; ?>><?php esc_attr_e( 'Save', 'd4h-calendar' ); ?></button>
					<p class="description"><?php esc_html_e( 'Increases rate limit from 60 to 5,000 requests/hour. Create at GitHub → Settings → Developer settings → Personal access tokens. No extra scopes needed for public repos.', 'd4h-calendar' ); ?> <?php echo $has_opt_token ? ' ' . esc_html__( 'Leave empty and save to remove.', 'd4h-calendar' ) : ''; ?></p>
				</form>
			<?php endif; ?>
			<div id="d4h-admin-message" class="notice" style="display:none;"></div>

			<h3><?php esc_html_e( 'Sync history', 'd4h-calendar' ); ?></h3>
			<?php
			$sync_history = Sync_History::get_history( $this->config, 100 );
			?>
			<?php if ( empty( $sync_history ) ) : ?>
				<p class="description"><?php esc_html_e( 'No sync runs recorded yet.', 'd4h-calendar' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Time', 'd4h-calendar' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'd4h-calendar' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Source', 'd4h-calendar' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Duration', 'd4h-calendar' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Error', 'd4h-calendar' ); ?></th>
						</tr>
					</thead>
					<tbody>
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
								<td><?php echo esc_html( $source === 'cron' ? __( 'Cron', 'd4h-calendar' ) : __( 'Manual', 'd4h-calendar' ) ); ?></td>
								<td><?php echo $duration_sec !== null ? esc_html( number_format( $duration_sec, 2 ) . ' s' ) : '—'; ?></td>
								<td><?php echo $error ? esc_html( $error ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Latest 100 sync runs.', 'd4h-calendar' ); ?></p>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'API credentials', 'd4h-calendar' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'd4h_calendar_save_credentials', 'd4h_calendar_nonce' ); ?>
				<input type="hidden" name="d4h_calendar_action" value="save_credentials" />
				<table class="form-table">
					<tr>
						<th scope="row"><label for="d4h_api_token"><?php esc_html_e( 'API Token', 'd4h-calendar' ); ?></label></th>
						<td><input type="password" id="d4h_api_token" name="d4h_api_token" value="<?php echo esc_attr( $token ); ?>" class="regular-text" autocomplete="off" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="d4h_api_context"><?php esc_html_e( 'team or organisation (optional)', 'd4h-calendar' ); ?></label></th>
						<td><input type="text" id="d4h_api_context" name="d4h_api_context" value="<?php echo esc_attr( $org_type ); ?>" placeholder="team or organisation" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="d4h_api_context_id"><?php esc_html_e( 'Team ID (optional)', 'd4h-calendar' ); ?></label></th>
						<td><input type="text" id="d4h_api_context_id" name="d4h_api_context_id" value="<?php echo esc_attr( $org_id ); ?>" class="regular-text" /></td>
					</tr>
				</table>
				<p class="submit"><input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save credentials', 'd4h-calendar' ); ?>" /></p>
			</form>

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
		</div>
		<?php
	}
}
