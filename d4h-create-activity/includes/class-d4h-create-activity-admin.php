<?php
/**
 * Admin: Create activity page, parse AJAX, send AJAX.
 *
 * @package D4H_Create_Activity
 */

namespace D4H_Create_Activity;

defined( 'ABSPATH' ) || exit;

final class Admin {

	/** @var array<string, mixed> */
	private $config;

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Register menu and AJAX hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		$parse = $this->config['ajax_action_parse'] ?? 'd4h_create_activity_ajax_parse';
		$send  = $this->config['ajax_action_send'] ?? 'd4h_create_activity_ajax_send';
		add_action( 'wp_ajax_' . $parse, array( $this, 'ajax_parse' ) );
		add_action( 'wp_ajax_' . $send, array( $this, 'ajax_send' ) );
	}

	/**
	 * Add submenu under D4H Core (or Settings if Core is missing).
	 */
	public function add_menu_page(): void {
		$capability = $this->config['admin_capability'] ?? 'manage_options';
		$slug       = $this->config['admin_menu_slug'] ?? 'd4h-create-activity';
		$page_title = $this->config['admin_page_title'] ?? 'D4H Create activity';
		$menu_title = $this->config['admin_menu_title'] ?? 'D4H Create activity';

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
	 * Load admin CSS/JS on this page only.
	 */
	public function enqueue_scripts( string $hook ): void {
		$slug     = $this->config['admin_menu_slug'] ?? 'd4h-create-activity';
		$expected = defined( 'D4H_CORE_ACTIVE' ) ? 'd4h-core_page_' . $slug : 'settings_page_' . $slug;
		$page     = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( $hook !== $expected && $page !== $slug ) {
			return;
		}

		$plugin_url = plugin_dir_url( D4H_CREATE_ACTIVITY_PLUGIN_FILE );

		wp_enqueue_style(
			'd4h-create-activity-admin',
			$plugin_url . 'assets/admin.css',
			array(),
			D4H_CREATE_ACTIVITY_VERSION
		);
		wp_enqueue_script(
			'd4h-create-activity-admin',
			$plugin_url . 'admin/admin.js',
			array(),
			D4H_CREATE_ACTIVITY_VERSION,
			true
		);

		$tags_map = function_exists( 'd4h_core_get_tags_map' ) ? d4h_core_get_tags_map() : array();

		wp_localize_script(
			'd4h-create-activity-admin',
			'd4hCreateActivity',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'd4h_create_activity_admin' ),
				'actionParse' => $this->config['ajax_action_parse'] ?? 'd4h_create_activity_ajax_parse',
				'actionSend'  => $this->config['ajax_action_send'] ?? 'd4h_create_activity_ajax_send',
				'tags'        => $this->tags_for_js( $tags_map ),
				'i18n'        => array(
					'parsing'        => __( 'Parsing…', 'd4h-create-activity' ),
					'parseError'     => __( 'Could not parse the paste.', 'd4h-create-activity' ),
					'pasteEmpty'     => __( 'Paste at least one activity row first.', 'd4h-create-activity' ),
					'fixInvalid'    => __( 'Fix invalid rows before sending.', 'd4h-create-activity' ),
					'sending'        => __( 'Sending to D4H…', 'd4h-create-activity' ),
					'sendError'      => __( 'Could not send activities.', 'd4h-create-activity' ),
					'confirmSend'    => __( 'Send these activities to D4H? This cannot be undone from WordPress.', 'd4h-create-activity' ),
					'success'        => __( 'Success', 'd4h-create-activity' ),
					'failed'         => __( 'Failed', 'd4h-create-activity' ),
					'attendanceExercise' => __( 'Full-Team Exercise', 'd4h-create-activity' ),
					'attendanceEvent'    => __( 'Full-Team Event', 'd4h-create-activity' ),
					'typeExercise'       => __( 'Exercise', 'd4h-create-activity' ),
					'typeEvent'          => __( 'Event', 'd4h-create-activity' ),
					'typeInvalid'        => __( 'Type must be Exercise or Event.', 'd4h-create-activity' ),
					'noTags'         => __( 'No tags', 'd4h-create-activity' ),
					'updateTagsHint' => __( 'No tags in Core yet. Go to D4H → Settings and click Update tags.', 'd4h-create-activity' ),
					'selectTags'     => __( 'Select tags', 'd4h-create-activity' ),
				),
			)
		);
	}

	/**
	 * AJAX: parse pasted text into a review table payload.
	 */
	public function ajax_parse(): void {
		$this->guard_ajax();

		$paste = isset( $_POST['paste'] ) ? wp_unslash( $_POST['paste'] ) : '';
		$paste = is_string( $paste ) ? $paste : '';

		$tags_map = function_exists( 'd4h_core_get_tags_map' ) ? d4h_core_get_tags_map() : array();
		$parser   = new Parser( $this->config, $tags_map );
		$result   = $parser->parse_paste( $paste );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ), 400 );
		}

		wp_send_json_success(
			array(
				'rows'      => $result['rows'],
				'all_valid' => ! empty( $result['all_valid'] ),
				'tags'      => $this->tags_for_js( $tags_map ),
			)
		);
	}

	/**
	 * AJAX: re-validate and POST each activity to D4H.
	 */
	public function ajax_send(): void {
		$this->guard_ajax();

		$token = function_exists( 'd4h_core_get_token' ) ? d4h_core_get_token() : '';
		if ( $token === '' ) {
			wp_send_json_error( array( 'message' => __( 'API token not set. Configure it in D4H → Settings.', 'd4h-create-activity' ) ), 400 );
		}

		$context    = function_exists( 'd4h_core_get_context' ) ? d4h_core_get_context() : '';
		$context_id = function_exists( 'd4h_core_get_context_id' ) ? d4h_core_get_context_id() : '';
		$context    = in_array( strtolower( $context ), array( 'team', 'organisation' ), true ) ? strtolower( $context ) : '';
		$context_id = preg_match( '/^[a-zA-Z0-9\-]+$/', trim( $context_id ) ) ? trim( $context_id ) : '';

		if ( $context === '' || $context_id === '' ) {
			wp_send_json_error( array( 'message' => __( 'Context and Context ID must be set in D4H → Settings.', 'd4h-create-activity' ) ), 400 );
		}

		$raw_rows = isset( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : '';
		if ( is_string( $raw_rows ) ) {
			$decoded = json_decode( $raw_rows, true );
		} elseif ( is_array( $raw_rows ) ) {
			$decoded = $raw_rows;
		} else {
			$decoded = null;
		}
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid activity payload.', 'd4h-create-activity' ) ), 400 );
		}

		$tags_map  = function_exists( 'd4h_core_get_tags_map' ) ? d4h_core_get_tags_map() : array();
		$parser    = new Parser( $this->config, $tags_map );
		$validated = $parser->validate_rows( $decoded );

		if ( ! empty( $validated['error'] ) ) {
			wp_send_json_error( array( 'message' => $validated['error'] ), 400 );
		}
		if ( empty( $validated['all_valid'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Some rows are invalid. Fix them and try again.', 'd4h-create-activity' ),
					'rows'    => $validated['rows'],
				),
				400
			);
		}

		$needs_tags = false;
		foreach ( $validated['rows'] as $row ) {
			if ( ! empty( $row['tag_ids'] ) ) {
				$needs_tags = true;
				break;
			}
		}
		if ( $needs_tags && $context !== 'team' ) {
			wp_send_json_error(
				array(
					'message' => __( 'Tags can only be set when Core context is “team”. Change context in D4H → Settings, or clear tags and send without them.', 'd4h-create-activity' ),
				),
				400
			);
		}

		$api     = new API_Client( $this->config, $token );
		$results = array();
		$ok      = 0;
		$fail    = 0;
		$start   = microtime( true );

		foreach ( $validated['rows'] as $row ) {
			$activity_label = (string) ( $row['activity_label'] ?? '' );
			$create         = $api->create_activity( $context, $context_id, $row );
			if ( is_wp_error( $create ) ) {
				$fail++;
				$results[] = array(
					'index'          => $row['index'],
					'title'          => $row['title'],
					'activity_label' => $activity_label,
					'success'        => false,
					'message'        => $create->get_error_message(),
				);
				continue;
			}

			$activity_id = isset( $create['id'] ) ? $create['id'] : ( $create['activityId'] ?? null );
			$tag_error   = '';

			if ( ! empty( $row['tag_ids'] ) && $activity_id ) {
				$tag_result = $api->set_activity_tags( $context_id, $activity_id, $row['tag_ids'], (string) ( $row['activity_type'] ?? 'exercise' ) );
				if ( is_wp_error( $tag_result ) ) {
					$tag_error = $tag_result->get_error_message();
				}
			}

			if ( $tag_error !== '' ) {
				$fail++;
				$results[] = array(
					'index'          => $row['index'],
					'title'          => $row['title'],
					'activity_label' => $activity_label,
					'success'        => false,
					'activity_id'    => $activity_id,
					'message'        => sprintf(
						/* translators: 1: activity id, 2: tag error */
						__( 'Created (ID %1$s) but tags failed: %2$s', 'd4h-create-activity' ),
						(string) $activity_id,
						$tag_error
					),
				);
			} else {
				$ok++;
				$results[] = array(
					'index'          => $row['index'],
					'title'          => $row['title'],
					'activity_label' => $activity_label,
					'success'        => true,
					'activity_id'    => $activity_id,
					'message'        => $activity_id
						? sprintf(
							/* translators: %s: D4H activity id */
							__( 'Created (ID %s)', 'd4h-create-activity' ),
							(string) $activity_id
						)
						: __( 'Created', 'd4h-create-activity' ),
				);
			}
		}

		$duration = microtime( true ) - $start;
		$status   = $fail === 0 ? 'success' : 'error';
		$error    = $fail > 0
			? sprintf(
				/* translators: 1: success count, 2: fail count */
				__( '%1$d created, %2$d failed', 'd4h-create-activity' ),
				$ok,
				$fail
			)
			: '';

		if ( class_exists( '\D4H_Core\Logger' ) ) {
			\D4H_Core\Logger::log_sync( 'create-activity', $status, $error, 'manual', $duration, $ok );
		}

		wp_send_json_success(
			array(
				'results' => $results,
				'created' => $ok,
				'failed'  => $fail,
			)
		);
	}

	/**
	 * Render the Create activity admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( $this->config['admin_capability'] ?? 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'd4h-create-activity' ) );
		}

		$tags_map   = function_exists( 'd4h_core_get_tags_map' ) ? d4h_core_get_tags_map() : array();
		$token_ok   = function_exists( 'd4h_core_get_token' ) && d4h_core_get_token() !== '';
		$context    = function_exists( 'd4h_core_get_context' ) ? d4h_core_get_context() : '';
		$context_id = function_exists( 'd4h_core_get_context_id' ) ? d4h_core_get_context_id() : '';
		$max_rows   = (int) ( $this->config['max_rows'] ?? 50 );
		?>
		<div class="wrap d4h-create-activity-wrap">
			<h1><?php echo esc_html( $this->config['admin_page_title'] ?? 'D4H Create activity' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Paste exercise or event rows from Excel or Sheets, review them, then send Full-Team activities to D4H. Nothing is sent until you confirm.', 'd4h-create-activity' ); ?>
			</p>

			<?php if ( ! $token_ok || $context === '' || $context_id === '' ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Set the API token, context, and context ID in D4H → Settings before creating activities.', 'd4h-create-activity' ); ?>
				</p></div>
			<?php endif; ?>

			<div class="d4h-ca-section d4h-ca-available-tags">
				<h2><?php esc_html_e( 'Available tags', 'd4h-create-activity' ); ?></h2>
				<?php if ( empty( $tags_map ) ) : ?>
					<p class="description">
						<?php esc_html_e( 'No tags stored yet. Go to D4H → Settings and click Update tags so you can match tags on each activity.', 'd4h-create-activity' ); ?>
					</p>
				<?php else : ?>
					<p class="description">
						<?php esc_html_e( 'Use these names in the Tags column (comma-separated). Matching is not case-sensitive.', 'd4h-create-activity' ); ?>
					</p>
					<ul class="d4h-ca-tag-list">
						<?php
						$tag_names = array_values( $tags_map );
						natcasesort( $tag_names );
						foreach ( $tag_names as $tag_name ) :
							?>
							<li class="d4h-ca-tag-chip"><?php echo esc_html( (string) $tag_name ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div id="d4h-ca-step-paste" class="d4h-ca-section">
				<h2><?php esc_html_e( '1. Paste activities', 'd4h-create-activity' ); ?></h2>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: max rows */
							__( 'One row per activity. First six columns are required; put one or more tags after that. Max %d rows.', 'd4h-create-activity' ),
							$max_rows
						)
					);
					?>
				</p>
				<ol class="d4h-ca-column-list">
					<li><?php esc_html_e( 'Type (Exercise or Event)', 'd4h-create-activity' ); ?></li>
					<li><?php esc_html_e( 'Name', 'd4h-create-activity' ); ?></li>
					<li><?php esc_html_e( 'Start (date and time)', 'd4h-create-activity' ); ?></li>
					<li><?php esc_html_e( 'End (date and time)', 'd4h-create-activity' ); ?></li>
					<li><?php esc_html_e( 'Pre-plan (HTML allowed)', 'd4h-create-activity' ); ?></li>
					<li><?php esc_html_e( 'Description (HTML allowed)', 'd4h-create-activity' ); ?></li>
					<li><?php esc_html_e( 'Tags (one or more names: commas in one cell, or extra columns)', 'd4h-create-activity' ); ?></li>
				</ol>
				<p class="description">
					<strong><?php esc_html_e( 'Column separator:', 'd4h-create-activity' ); ?></strong>
					<?php esc_html_e( 'a Tab. Copying cells from Excel or Google Sheets already uses Tab, so just paste. If you type rows by hand, separate the columns with a semicolon (;) instead. Several tags can go in the last column (comma-separated) or in extra columns after Description.', 'd4h-create-activity' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Example (typed by hand, semicolons):', 'd4h-create-activity' ); ?>
				</p>
				<pre class="d4h-ca-example"><?php echo esc_html( 'Exercise;B1 - Næturrötun;2026-09-12 18:00;2026-09-12 21:00;Muna eftir höfuðljósi;Æfa næturrötun með korti og áttavita;Nýliðastarf, Námskeið' ); ?></pre>
				<p class="description">
					<?php esc_html_e( 'Start and end need date and time (for example 2026-09-12 18:00). Attendance is always Full-Team. You can change Exercise/Event and tick several tags on the review table. Pre-plan and Description keep HTML such as paragraphs, line breaks, lists, and links. Scripts and other unsafe tags are removed.', 'd4h-create-activity' ); ?>
				</p>
				<textarea id="d4h-ca-paste" class="large-text code" rows="12" placeholder="<?php echo esc_attr__( 'Paste rows from Excel here, or type them with semicolons between the columns.', 'd4h-create-activity' ); ?>"></textarea>
				<p>
					<button type="button" id="d4h-ca-proceed" class="button button-primary"><?php esc_html_e( 'Proceed', 'd4h-create-activity' ); ?></button>
					<span id="d4h-ca-parse-status" class="d4h-ca-status" aria-live="polite"></span>
				</p>
			</div>

			<div id="d4h-ca-step-review" class="d4h-ca-section" style="display:none;">
				<h2><?php esc_html_e( '2. Review before sending', 'd4h-create-activity' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Check each row. Fix tags if needed. Invalid rows are highlighted and cannot be sent.', 'd4h-create-activity' ); ?></p>
				<div class="d4h-ca-table-wrap">
					<table class="wp-list-table widefat fixed striped" id="d4h-ca-review-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Type', 'd4h-create-activity' ); ?></th>
								<th><?php esc_html_e( 'Name', 'd4h-create-activity' ); ?></th>
								<th><?php esc_html_e( 'Start', 'd4h-create-activity' ); ?></th>
								<th><?php esc_html_e( 'End', 'd4h-create-activity' ); ?></th>
								<th><?php esc_html_e( 'Attendance', 'd4h-create-activity' ); ?></th>
								<th><?php esc_html_e( 'Pre-plan', 'd4h-create-activity' ); ?></th>
								<th><?php esc_html_e( 'Description', 'd4h-create-activity' ); ?></th>
								<th><?php esc_html_e( 'Tags', 'd4h-create-activity' ); ?></th>
								<th><?php esc_html_e( 'Status', 'd4h-create-activity' ); ?></th>
							</tr>
						</thead>
						<tbody id="d4h-ca-review-body"></tbody>
					</table>
				</div>
				<p>
					<button type="button" id="d4h-ca-back" class="button"><?php esc_html_e( 'Back', 'd4h-create-activity' ); ?></button>
					<button type="button" id="d4h-ca-send" class="button button-primary"><?php esc_html_e( 'Send to D4H', 'd4h-create-activity' ); ?></button>
					<span id="d4h-ca-send-status" class="d4h-ca-status" aria-live="polite"></span>
				</p>
			</div>

			<div id="d4h-ca-step-results" class="d4h-ca-section" style="display:none;">
				<h2><?php esc_html_e( '3. Results', 'd4h-create-activity' ); ?></h2>
				<div id="d4h-ca-results-summary" class="notice" style="display:none;"></div>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Type', 'd4h-create-activity' ); ?></th>
							<th><?php esc_html_e( 'Name', 'd4h-create-activity' ); ?></th>
							<th><?php esc_html_e( 'Result', 'd4h-create-activity' ); ?></th>
						</tr>
					</thead>
					<tbody id="d4h-ca-results-body"></tbody>
				</table>
				<p>
					<button type="button" id="d4h-ca-again" class="button"><?php esc_html_e( 'Create more', 'd4h-create-activity' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Shared AJAX capability and nonce checks.
	 */
	private function guard_ajax(): void {
		check_ajax_referer( 'd4h_create_activity_admin', 'nonce' );
		if ( ! current_user_can( $this->config['admin_capability'] ?? 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'd4h-create-activity' ) ), 403 );
		}
	}

	/**
	 * Convert Core tags map to a list for the browser.
	 *
	 * @param array<int|string, string> $tags_map
	 * @return array<int, array{id: int, name: string}>
	 */
	private function tags_for_js( array $tags_map ): array {
		$tags = array();
		foreach ( $tags_map as $id => $name ) {
			$tags[] = array(
				'id'   => (int) $id,
				'name' => (string) $name,
			);
		}
		return $tags;
	}
}
