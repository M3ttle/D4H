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

		$url = plugin_dir_url( D4H_CALENDAR_PLUGIN_FILE ) . 'admin/admin.js';
		wp_enqueue_script(
			'd4h-calendar-admin',
			$url,
			array( 'jquery' ),
			D4H_CALENDAR_VERSION,
			true
		);
		wp_localize_script( 'd4h-calendar-admin', 'd4hCalendarAdmin', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'd4h_calendar_admin' ),
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

		$opt_token = $this->config['option_token'] ?? 'd4h_calendar_api_token';
		$token     = get_option( $opt_token, '' );

		if ( $token === '' ) {
			wp_send_json_error( array( 'message' => __( 'API token not set.', 'd4h-calendar' ) ), 400 );
		}

		$api    = new API_Client( $this->config, $token );
		$sync   = new Sync( $this->config, $api, $this->repository );
		$result = $sync->run_full_sync();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		$opt_updated = $this->config['option_last_updated'] ?? 'd4h_calendar_last_updated';
		$updated    = get_option( $opt_updated, 0 );
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
		$opt_token = $this->config['option_token'] ?? 'd4h_calendar_api_token';
		$opt_ctx   = $this->config['option_context'] ?? 'd4h_calendar_api_context';
		$opt_ctxid = $this->config['option_context_id'] ?? 'd4h_calendar_api_context_id';

		$token = isset( $_POST['d4h_api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_api_token'] ) ) : '';
		$ctx   = isset( $_POST['d4h_api_context'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_api_context'] ) ) : '';
		$ctxid = isset( $_POST['d4h_api_context_id'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_api_context_id'] ) ) : '';

		update_option( $opt_token, $token, false );
		update_option( $opt_ctx, $ctx, false );
		update_option( $opt_ctxid, $ctxid, false );

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
		$opt_token  = $this->config['option_token'] ?? 'd4h_calendar_api_token';
		$opt_ctx    = $this->config['option_context'] ?? 'd4h_calendar_api_context';
		$opt_ctxid  = $this->config['option_context_id'] ?? 'd4h_calendar_api_context_id';
		$opt_updated = $this->config['option_last_updated'] ?? 'd4h_calendar_last_updated';

		$token  = get_option( $opt_token, '' );
		$ctx    = get_option( $opt_ctx, '' );
		$ctxid  = get_option( $opt_ctxid, '' );
		$updated = get_option( $opt_updated, 0 );

		$page_title = esc_html( $this->config['admin_page_title'] ?? 'D4H Calendar' );

		$saved = isset( $_GET['saved'] ) && $_GET['saved'] === '1';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		?>
		<div class="wrap">
			<h1><?php echo $page_title; ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'API credentials saved.', 'd4h-calendar' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

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
						<td><input type="text" id="d4h_api_context" name="d4h_api_context" value="<?php echo esc_attr( $ctx ); ?>" placeholder="team or organisation" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="d4h_api_context_id"><?php esc_html_e( 'Team ID (optional)', 'd4h-calendar' ); ?></label></th>
						<td><input type="text" id="d4h_api_context_id" name="d4h_api_context_id" value="<?php echo esc_attr( $ctxid ); ?>" class="regular-text" /></td>
					</tr>
				</table>
				<p class="submit"><input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save credentials', 'd4h-calendar' ); ?>" /></p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Sync', 'd4h-calendar' ); ?></h2>
			<p><strong><?php esc_html_e( 'Last updated:', 'd4h-calendar' ); ?></strong>
				<span id="d4h-last-updated"><?php echo $updated ? esc_html( wp_date( 'j M Y, H:i', $updated ) ) : esc_html__( 'Never', 'd4h-calendar' ); ?></span>
			</p>
			<p>
				<button type="button" id="d4h-update-now" class="button button-secondary"><?php esc_html_e( 'Update now', 'd4h-calendar' ); ?></button>
				<?php if ( ! empty( $this->config['enable_delete_btn'] ) ) : ?>
					<?php $retention = (int) ( $this->config['retention_days'] ?? 90 ); ?>
					<button type="button" id="d4h-delete-old" class="button button-secondary"><?php echo esc_html( sprintf( __( 'Delete data older than %d days', 'd4h-calendar' ), $retention ) ); ?></button>
				<?php endif; ?>
			</p>
			<div id="d4h-admin-message" class="notice" style="display:none;"></div>
		</div>
		<?php
	}
}
