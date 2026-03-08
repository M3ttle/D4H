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
			<p class="description"><?php esc_html_e( 'Shared by D4H Calendar and D4H Incidents.', 'd4h-core' ); ?></p>
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
				<p class="description"><?php esc_html_e( 'Increases rate limit from 60 to 5,000 requests/hour for plugin updates from GitHub. Create at GitHub → Settings → Developer settings → Personal access tokens. No extra scopes needed for public repos. Shared by D4H Core, Calendar, and Incidents.', 'd4h-core' ); ?> <?php echo $has_opt_token ? ' ' . esc_html__( 'Leave empty and save to remove.', 'd4h-core' ) : ''; ?></p>
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

			<h2><?php esc_html_e( 'Sync history', 'd4h-core' ); ?></h2>
			<?php
			$history = Logger::get_sync_history( 100 );
			if ( empty( $history ) ) :
				?>
				<p class="description"><?php esc_html_e( 'No sync runs recorded yet.', 'd4h-core' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'd4h-core' ); ?></th>
							<th><?php esc_html_e( 'Source', 'd4h-core' ); ?></th>
							<th><?php esc_html_e( 'Status', 'd4h-core' ); ?></th>
							<th><?php esc_html_e( 'Trigger', 'd4h-core' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'd4h-core' ); ?></th>
							<th><?php esc_html_e( 'Error', 'd4h-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $history as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( isset( $entry['time'] ) ? wp_date( 'j M Y, H:i:s', (int) $entry['time'] ) : '—' ); ?></td>
								<td><?php echo esc_html( $entry['source'] ?? '—' ); ?></td>
								<td>
									<?php
									$status = $entry['status'] ?? '';
									echo $status === 'success' ? '<span style="color:#00a32a;">' . esc_html__( 'Success', 'd4h-core' ) . '</span>' : '<span style="color:#d63638;">' . esc_html__( 'Error', 'd4h-core' ) . '</span>';
									?>
								</td>
								<td><?php echo esc_html( ( $entry['trigger'] ?? '' ) === 'cron' ? __( 'Cron', 'd4h-core' ) : __( 'Manual', 'd4h-core' ) ); ?></td>
								<td><?php echo isset( $entry['duration_sec'] ) && $entry['duration_sec'] !== null ? esc_html( number_format( (float) $entry['duration_sec'], 2 ) . ' s' ) : '—'; ?></td>
								<td><?php echo esc_html( $entry['error'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'API logs', 'd4h-core' ); ?></h2>
			<?php
			$logs = Logger::get_api_logs( 100 );
			if ( empty( $logs ) ) :
				?>
				<p class="description"><?php esc_html_e( 'No API calls logged yet.', 'd4h-core' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
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
							<tr>
								<td><?php echo esc_html( isset( $entry['time'] ) ? wp_date( 'j M Y, H:i:s', (int) $entry['time'] ) : '—' ); ?></td>
								<td><code><?php echo esc_html( $entry['endpoint'] ?? '—' ); ?></code></td>
								<td><?php echo esc_html( (string) ( $entry['code'] ?? '—' ) ); ?></td>
								<td><?php echo isset( $entry['duration'] ) && $entry['duration'] !== null ? esc_html( number_format( (float) $entry['duration'], 2 ) . ' s' ) : '—'; ?></td>
								<td><?php echo esc_html( $entry['source'] ?? '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
