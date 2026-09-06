<?php
/**
 * Core admin: top-level D4H menu, Settings (credentials, logs).
 *
 * @package D4H_Core
 */

namespace D4H_Core;

defined( 'ABSPATH' ) || exit;

final class Admin {

	/** @var array<string, mixed> */
	private $config;

	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 5 );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_scripts' ) );
		add_action( 'wp_ajax_d4h_core_update_tags', array( $this, 'ajax_update_tags' ) );
	}

	/**
	 * Load scripts and styles on the D4H Settings page.
	 */
	public function enqueue_settings_scripts( string $hook ): void {
		$slug     = $this->config['admin_menu_slug'] ?? 'd4h-core';
		$expected = 'toplevel_page_' . $slug;
		if ( $hook !== $expected ) {
			return;
		}

		$plugin_url = plugin_dir_url( D4H_CORE_PLUGIN_FILE );
		wp_enqueue_style(
			'd4h-core-admin',
			$plugin_url . 'admin/admin.css',
			array(),
			D4H_CORE_VERSION
		);
		wp_enqueue_script(
			'd4h-core-admin',
			$plugin_url . 'admin/admin.js',
			array(),
			D4H_CORE_VERSION,
			true
		);
		wp_localize_script( 'd4h-core-admin', 'd4hCoreSettings', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'd4h_core_update_tags' ),
			'i18n'    => array(
				'updating'     => __( 'Updating...', 'd4h-core' ),
				'success'      => __( 'Tags updated successfully.', 'd4h-core' ),
				'error'        => __( 'Failed to update tags.', 'd4h-core' ),
				'showingRows'  => __( 'Showing %1$d of %2$d matching rows.', 'd4h-core' ),
				'truncated'    => __( 'Only the first %d are listed. Choose 100 rows to see more.', 'd4h-core' ),
			),
		) );
	}

	public function ajax_update_tags(): void {
		check_ajax_referer( 'd4h_core_update_tags', 'nonce' );
		if ( ! current_user_can( $this->config['admin_capability'] ?? 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'd4h-core' ) ), 403 );
		}

		$token = function_exists( 'd4h_core_get_token' ) ? d4h_core_get_token() : '';
		if ( $token === '' ) {
			wp_send_json_error( array( 'message' => __( 'API token not set.', 'd4h-core' ) ), 400 );
		}

		$context    = function_exists( 'd4h_core_get_context' ) ? d4h_core_get_context() : '';
		$context_id = function_exists( 'd4h_core_get_context_id' ) ? d4h_core_get_context_id() : '';
		if ( $context === '' || $context_id === '' ) {
			wp_send_json_error( array( 'message' => __( 'Context and Context ID must be set.', 'd4h-core' ) ), 400 );
		}

		$context    = in_array( strtolower( $context ), array( 'team', 'organisation' ), true ) ? strtolower( $context ) : 'team';
		$base_url   = rtrim( (string) ( $this->config['api_base_url'] ?? 'https://api.team-manager.us.d4h.com' ), '/' );
		$path       = sprintf( '/v3/%s/%s/tags', $context, $context_id );
		$url        = $base_url . $path . '?page=0&size=500';

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 500 );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'API returned %d', 'd4h-core' ), $code ) ), $code );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid API response.', 'd4h-core' ) ), 500 );
		}

		$raw_tags = $data['results'] ?? $data['data'] ?? $data['content'] ?? $data['tags'] ?? $data['items'] ?? $data['records'] ?? array();
		if ( ! is_array( $raw_tags ) ) {
			$raw_tags = array();
		}
		if ( empty( $raw_tags ) && isset( $data[0] ) && is_array( $data[0] ) ) {
			$raw_tags = $data;
		}

		$tags_map = $this->build_tags_map( $raw_tags );
		$opt      = $this->config['option_tags_map'] ?? 'd4h_core_tags_map';
		update_option( $opt, $tags_map, false );

		wp_send_json_success( array( 'count' => count( $tags_map ) ) );
	}

	/**
	 * Build id => name map from tag objects.
	 *
	 * @param array<int, array<string, mixed>> $tags
	 * @return array<int, string>
	 */
	private function build_tags_map( array $tags ): array {
		$tags_map = array();
		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) ) {
				continue;
			}
			$id = isset( $tag['id'] ) ? (int) $tag['id'] : ( isset( $tag['_id'] ) ? (int) $tag['_id'] : null );
			if ( $id === null || $id === 0 ) {
				continue;
			}
			$name = $tag['name'] ?? $tag['label'] ?? $tag['title'] ?? '';
			$name = is_string( $name ) ? trim( $name ) : '';
			$tags_map[ $id ] = $name !== '' ? $name : sprintf( __( 'Tag %d', 'd4h-core' ), $id );
		}
		return $tags_map;
	}

	public function add_menu(): void {
		$cap  = $this->config['admin_capability'] ?? 'manage_options';
		$slug = $this->config['admin_menu_slug'] ?? 'd4h-core';

		add_menu_page(
			__( 'D4H Settings', 'd4h-core' ),
			'D4H',
			$cap,
			$slug,
			array( $this, 'render_settings' ),
			'dashicons-groups',
			30
		);

		add_submenu_page(
			$slug,
			__( 'D4H Settings', 'd4h-core' ),
			__( 'Settings', 'd4h-core' ),
			$cap,
			$slug,
			array( $this, 'render_settings' )
		);
	}

	public function handle_save(): void {
		$slug = $this->config['admin_menu_slug'] ?? 'd4h-core';
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== $slug ) {
			return;
		}
		if ( ! current_user_can( $this->config['admin_capability'] ?? 'manage_options' ) ) {
			return;
		}
		if ( empty( $_POST['d4h_core_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['d4h_core_action'] ) );

		if ( $action === 'save_credentials' ) {
			if ( ! wp_verify_nonce( isset( $_POST['d4h_core_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_core_nonce'] ) ) : '', 'd4h_core_save_credentials' ) ) {
				return;
			}
			$opt_token   = $this->config['option_token'] ?? 'd4h_core_api_token';
			$opt_context = $this->config['option_context'] ?? 'd4h_core_api_context';
			$opt_id      = $this->config['option_context_id'] ?? 'd4h_core_api_context_id';

			$token      = isset( $_POST['d4h_core_api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_core_api_token'] ) ) : '';
			$context    = isset( $_POST['d4h_core_api_context'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_core_api_context'] ) ) : '';
			$context_id = isset( $_POST['d4h_core_api_context_id'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_core_api_context_id'] ) ) : '';

			update_option( $opt_token, $token, false );
			update_option( $opt_context, $context, false );
			update_option( $opt_id, $context_id, false );
		}

		if ( $action === 'save_github_token' ) {
			if ( ! wp_verify_nonce( isset( $_POST['d4h_core_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_core_nonce'] ) ) : '', 'd4h_core_save_github_token' ) ) {
				return;
			}
			$opt_github = $this->config['option_github_token'] ?? 'd4h_core_github_token';
			$token     = isset( $_POST['d4h_github_token'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_github_token'] ) ) : '';
			if ( $token !== '' ) {
				update_option( $opt_github, $token, false );
			} else {
				delete_option( $opt_github );
			}
		}

		$url = add_query_arg( array( 'page' => $slug, 'saved' => '1' ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	public function render_settings(): void {
		$opt_token   = $this->config['option_token'] ?? 'd4h_core_api_token';
		$opt_context = $this->config['option_context'] ?? 'd4h_core_api_context';
		$opt_id      = $this->config['option_context_id'] ?? 'd4h_core_api_context_id';

		$token      = get_option( $opt_token, '' );
		$context    = get_option( $opt_context, '' );
		$context_id = get_option( $opt_id, '' );
		$slug       = $this->config['admin_menu_slug'] ?? 'd4h-core';
		$saved      = isset( $_GET['saved'] ) && $_GET['saved'] === '1';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'D4H Settings', 'd4h-core' ); ?></h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'd4h-core' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'API credentials', 'd4h-core' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Shared by D4H Calendar, D4H Incidents, and D4H Create Activity.', 'd4h-core' ); ?></p>
			<form method="post" action="">
				<?php wp_nonce_field( 'd4h_core_save_credentials', 'd4h_core_nonce' ); ?>
				<input type="hidden" name="d4h_core_action" value="save_credentials" />
				<table class="form-table">
					<tr>
						<th><label for="d4h_core_api_token"><?php esc_html_e( 'API Token', 'd4h-core' ); ?></label></th>
						<td><input type="password" id="d4h_core_api_token" name="d4h_core_api_token" value="<?php echo esc_attr( $token ); ?>" class="regular-text" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="d4h_core_api_context"><?php esc_html_e( 'Context (team or organisation)', 'd4h-core' ); ?></label></th>
						<td><input type="text" id="d4h_core_api_context" name="d4h_core_api_context" value="<?php echo esc_attr( $context ); ?>" placeholder="team" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="d4h_core_api_context_id"><?php esc_html_e( 'Context ID', 'd4h-core' ); ?></label></th>
						<td><input type="text" id="d4h_core_api_context_id" name="d4h_core_api_context_id" value="<?php echo esc_attr( $context_id ); ?>" class="regular-text" /></td>
					</tr>
				</table>
				<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save', 'd4h-core' ); ?>" /></p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Plugin updates', 'd4h-core' ); ?></h2>
			<?php
			$option_github   = $this->config['option_github_token'] ?? 'd4h_core_github_token';
			$has_const_token = defined( 'D4H_CORE_GITHUB_TOKEN' );
			$has_opt_token   = ! $has_const_token && get_option( $option_github, '' ) !== '';
			?>
			<p style="margin-bottom: 0.5em;">
				<button type="button" id="d4h-github-token-toggle" class="button-link" aria-expanded="false" aria-controls="d4h-github-token-form">
					<span class="d4h-toggle-show"><?php esc_html_e( 'Show GitHub API token', 'd4h-core' ); ?></span>
					<span class="d4h-toggle-hide" style="display:none;"><?php esc_html_e( 'Hide GitHub API token', 'd4h-core' ); ?></span>
				</button>
			</p>
			<form id="d4h-github-token-form" method="post" action="" style="margin-top: 0.5em; display:none;" aria-hidden="true">
				<?php wp_nonce_field( 'd4h_core_save_github_token', 'd4h_core_nonce' ); ?>
				<input type="hidden" name="d4h_core_action" value="save_github_token" />
				<label for="d4h_github_token"><?php esc_html_e( 'GitHub API token (optional)', 'd4h-core' ); ?></label>
				<input type="password" id="d4h_github_token" name="d4h_github_token" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $has_const_token ? esc_attr__( 'Set via wp-config.php', 'd4h-core' ) : ( $has_opt_token ? esc_attr__( 'Token saved — enter new to replace', 'd4h-core' ) : '' ); ?>" <?php echo $has_const_token ? ' readonly' : ''; ?> />
				<button type="submit" class="button button-secondary"<?php echo $has_const_token ? ' disabled' : ''; ?>><?php esc_attr_e( 'Save', 'd4h-core' ); ?></button>
				<p class="description"><?php esc_html_e( 'Increases rate limit from 60 to 5,000 requests/hour for plugin updates from GitHub. Create at GitHub → Settings → Developer settings → Personal access tokens. No extra scopes needed for public repos. Shared by D4H Core, Calendar, Incidents, and Create Activity.', 'd4h-core' ); ?> <?php echo $has_opt_token ? ' ' . esc_html__( 'Leave empty and save to remove.', 'd4h-core' ) : ''; ?></p>
			</form>
			<script>
			(function() {
				var toggle = document.getElementById('d4h-github-token-toggle');
				var form = document.getElementById('d4h-github-token-form');
				if (toggle && form) {
					var showLabel = toggle.querySelector('.d4h-toggle-show');
					var hideLabel = toggle.querySelector('.d4h-toggle-hide');
					toggle.addEventListener('click', function() {
						var hidden = form.style.display === 'none';
						form.style.display = hidden ? 'block' : 'none';
						form.setAttribute('aria-hidden', hidden ? 'false' : 'true');
						toggle.setAttribute('aria-expanded', hidden ? 'true' : 'false');
						if (showLabel) showLabel.style.display = hidden ? 'none' : 'inline';
						if (hideLabel) hideLabel.style.display = hidden ? 'inline' : 'none';
					});
				}
			})();
			</script>

			<hr />

			<h2><?php esc_html_e( 'Tags', 'd4h-core' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Retrieve tag names from the D4H API. Used by D4H Incidents for filtering and by D4H Create Activity when matching tags on new exercises and events.', 'd4h-core' ); ?></p>
			<p>
				<button type="button" id="d4h-core-update-tags" class="button button-secondary">
					<?php esc_html_e( 'Update tags', 'd4h-core' ); ?>
				</button>
				<span id="d4h-core-tags-status" style="margin-left: 0.5em;"></span>
			</p>
			<?php
			$tags_map = function_exists( 'd4h_core_get_tags_map' ) ? d4h_core_get_tags_map() : array();
			if ( ! empty( $tags_map ) ) :
				?>
				<p class="description"><?php echo esc_html( sprintf( __( 'Stored %d tags.', 'd4h-core' ), count( $tags_map ) ) ); ?></p>
			<?php endif; ?>
			<script>
			(function() {
				var btn = document.getElementById('d4h-core-update-tags');
				var status = document.getElementById('d4h-core-tags-status');
				if (!btn || !status) return;
				var cfg = window.d4hCoreSettings || {};
				btn.addEventListener('click', function() {
					btn.disabled = true;
					status.textContent = cfg.i18n && cfg.i18n.updating ? cfg.i18n.updating : 'Updating...';
					status.style.color = '';
					var fd = new FormData();
					fd.append('action', 'd4h_core_update_tags');
					fd.append('nonce', cfg.nonce || '');
					fetch(cfg.ajaxUrl || '', { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function(r) { return r.json(); })
						.then(function(res) {
							if (res.success) {
								status.textContent = (cfg.i18n && cfg.i18n.success ? cfg.i18n.success : 'Tags updated.') + (res.data && res.data.count != null ? ' (' + res.data.count + ')' : '');
								status.style.color = '#00a32a';
								location.reload();
							} else {
								status.textContent = (cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Failed.') + (res.data && res.data.message ? ' ' + res.data.message : '');
								status.style.color = '#d63638';
							}
						})
						.catch(function() {
							status.textContent = cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Failed.';
							status.style.color = '#d63638';
						})
						.finally(function() { btn.disabled = false; });
				});
			})();
			</script>

			<hr />

			<h2><?php esc_html_e( 'Sync history', 'd4h-core' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Each row is one calendar (or other plugin) update. “Started by” says whether the timer ran it or someone clicked a button.', 'd4h-core' ); ?></p>
			<?php
			$history = Logger::get_sync_history( 0 );
			if ( empty( $history ) ) :
				?>
				<p class="description"><?php esc_html_e( 'No sync runs recorded yet.', 'd4h-core' ); ?></p>
			<?php else : ?>
				<?php $this->render_log_filters( 'd4h-sync-history', $this->unique_sources( $history ) ); ?>
				<table class="wp-list-table widefat fixed striped d4h-log-table" id="d4h-sync-history">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'd4h-core' ); ?></th>
							<th><?php esc_html_e( 'Source', 'd4h-core' ); ?></th>
							<th><?php esc_html_e( 'Status', 'd4h-core' ); ?></th>
							<th><?php esc_html_e( 'Started by', 'd4h-core' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'd4h-core' ); ?></th>
							<th><?php esc_html_e( 'Error', 'd4h-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $history as $entry ) : ?>
							<?php
							$status = ( $entry['status'] ?? '' ) === 'success' ? 'success' : 'error';
							$source = (string) ( $entry['source'] ?? '' );
							$time   = (int) ( $entry['time'] ?? 0 );
							?>
							<tr class="d4h-log-row" data-time="<?php echo esc_attr( (string) $time ); ?>" data-source="<?php echo esc_attr( $source ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
								<td><?php echo $time ? esc_html( wp_date( 'j M Y, H:i:s', $time ) ) : '—'; ?></td>
								<td><?php echo esc_html( $source !== '' ? $source : '—' ); ?></td>
								<td>
									<?php
									echo $status === 'success'
										? '<span style="color:#00a32a;">' . esc_html__( 'Success', 'd4h-core' ) . '</span>'
										: '<span style="color:#d63638;">' . esc_html__( 'Error', 'd4h-core' ) . '</span>';
									?>
								</td>
								<td><?php echo esc_html( $this->trigger_label( (string) ( $entry['trigger'] ?? '' ) ) ); ?></td>
								<td><?php echo isset( $entry['duration_sec'] ) && $entry['duration_sec'] !== null ? esc_html( number_format( (float) $entry['duration_sec'], 2 ) . ' s' ) : '—'; ?></td>
								<td><?php echo esc_html( $entry['error'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'API logs', 'd4h-core' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Each row is one call to the D4H API. Failed calls (not 2xx) count as errors.', 'd4h-core' ); ?></p>
			<?php
			$logs = Logger::get_api_logs( 0 );
			if ( empty( $logs ) ) :
				?>
				<p class="description"><?php esc_html_e( 'No API calls logged yet.', 'd4h-core' ); ?></p>
			<?php else : ?>
				<?php $this->render_log_filters( 'd4h-api-logs', $this->unique_sources( $logs ) ); ?>
				<table class="wp-list-table widefat fixed striped d4h-log-table" id="d4h-api-logs">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'd4h-core' ); ?></th>
							<th><?php esc_html_e( 'Endpoint', 'd4h-core' ); ?></th>
							<th><?php esc_html_e( 'Code', 'd4h-core' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'd4h-core' ); ?></th>
							<th><?php esc_html_e( 'Source', 'd4h-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $entry ) : ?>
							<?php
							$code   = (int) ( $entry['code'] ?? 0 );
							$status = ( $code >= 200 && $code < 300 ) ? 'success' : 'error';
							$source = (string) ( $entry['source'] ?? '' );
							$time   = (int) ( $entry['time'] ?? 0 );
							?>
							<tr class="d4h-log-row" data-time="<?php echo esc_attr( (string) $time ); ?>" data-source="<?php echo esc_attr( $source ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
								<td><?php echo $time ? esc_html( wp_date( 'j M Y, H:i:s', $time ) ) : '—'; ?></td>
								<td><code><?php echo esc_html( $entry['endpoint'] ?? '—' ); ?></code></td>
								<td><?php echo esc_html( (string) ( $entry['code'] ?? '—' ) ); ?></td>
								<td><?php echo isset( $entry['duration'] ) && $entry['duration'] !== null ? esc_html( number_format( (float) $entry['duration'], 2 ) . ' s' ) : '—'; ?></td>
								<td><?php echo esc_html( $source !== '' ? $source : '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Plain-English label for how a sync was started.
	 */
	private function trigger_label( string $trigger ): string {
		switch ( $trigger ) {
			case 'cron':
				return __( 'Scheduled', 'd4h-core' );
			case 'cron_clean':
				return __( 'Scheduled (full refresh)', 'd4h-core' );
			case 'manual_clean':
				return __( 'Clean data button', 'd4h-core' );
			default:
				return __( 'Update button', 'd4h-core' );
		}
	}

	/**
	 * Unique source names from log rows, for the filter dropdown.
	 *
	 * @param array<int, array<string, mixed>> $entries
	 * @return array<int, string>
	 */
	private function unique_sources( array $entries ): array {
		$sources = array();
		foreach ( $entries as $entry ) {
			$source = isset( $entry['source'] ) ? trim( (string) $entry['source'] ) : '';
			if ( $source !== '' ) {
				$sources[ $source ] = $source;
			}
		}
		ksort( $sources );
		return array_values( $sources );
	}

	/**
	 * Row-count, source, status, and period filters for a log table.
	 *
	 * @param string             $table_id HTML id of the table
	 * @param array<int, string> $sources
	 */
	private function render_log_filters( string $table_id, array $sources ): void {
		$default_rows = (int) ( $this->config['log_table_default_rows'] ?? 10 );
		$period_days  = (int) ( $this->config['log_error_retain_days'] ?? 60 );
		?>
		<div class="d4h-log-controls" data-table="<?php echo esc_attr( $table_id ); ?>">
			<label>
				<?php esc_html_e( 'Rows', 'd4h-core' ); ?>
				<select class="d4h-log-rows">
					<option value="10" <?php selected( $default_rows, 10 ); ?>>10</option>
					<option value="20" <?php selected( $default_rows, 20 ); ?>>20</option>
					<option value="100" <?php selected( $default_rows, 100 ); ?>>100</option>
				</select>
			</label>
			<label>
				<?php esc_html_e( 'Source', 'd4h-core' ); ?>
				<select class="d4h-log-source">
					<option value=""><?php esc_html_e( 'All', 'd4h-core' ); ?></option>
					<?php foreach ( $sources as $source ) : ?>
						<option value="<?php echo esc_attr( $source ); ?>"><?php echo esc_html( $source ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<?php esc_html_e( 'Status', 'd4h-core' ); ?>
				<select class="d4h-log-status">
					<option value=""><?php esc_html_e( 'All', 'd4h-core' ); ?></option>
					<option value="success"><?php esc_html_e( 'Success', 'd4h-core' ); ?></option>
					<option value="error"><?php esc_html_e( 'Error', 'd4h-core' ); ?></option>
				</select>
			</label>
			<label>
				<?php esc_html_e( 'Period', 'd4h-core' ); ?>
				<select class="d4h-log-period">
					<option value=""><?php esc_html_e( 'All time', 'd4h-core' ); ?></option>
					<option value="<?php echo esc_attr( (string) $period_days ); ?>">
						<?php echo esc_html( sprintf( __( 'Last %d days', 'd4h-core' ), $period_days ) ); ?>
					</option>
				</select>
			</label>
			<button type="button" class="button d4h-log-errors-60">
				<?php echo esc_html( sprintf( __( 'Show errors (last %d days)', 'd4h-core' ), $period_days ) ); ?>
			</button>
			<p class="d4h-log-summary description"></p>
		</div>
		<?php
	}
}
