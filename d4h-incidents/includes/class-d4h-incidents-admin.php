<?php
/**
 * Admin: incidents report page, fetch, statistics, charts, export.
 *
 * @package D4H_Incidents
 */

namespace D4H_Incidents;

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

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'handle_save_credentials' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		$action_fetch = $this->config['ajax_action_fetch'] ?? 'd4h_incidents_ajax_fetch';
		$action_excel = $this->config['ajax_action_export_excel'] ?? 'd4h_incidents_ajax_export_excel';
		$action_png   = $this->config['ajax_action_export_png'] ?? 'd4h_incidents_ajax_export_png';

		add_action( 'wp_ajax_' . $action_fetch, array( $this, 'ajax_fetch' ) );
		add_action( 'wp_ajax_' . $action_excel, array( $this, 'ajax_export_excel' ) );
		add_action( 'wp_ajax_' . $action_png, array( $this, 'ajax_export_png' ) );
	}

	public function add_menu_page(): void {
		$capability = $this->config['admin_capability'] ?? 'manage_options';
		$slug       = $this->config['admin_menu_slug'] ?? 'd4h-incidents';
		$page_title = $this->config['admin_page_title'] ?? 'D4H Incidents';
		$menu_title = $this->config['admin_menu_title'] ?? 'D4H Incidents';

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
	 * Get API token from D4H Core or legacy options.
	 */
	private function get_token(): string {
		return function_exists( 'd4h_core_get_token' ) ? d4h_core_get_token() : get_option( $this->config['option_token'] ?? 'd4h_incidents_api_token', '' );
	}

	/**
	 * Get context and context_id from D4H Core or legacy options.
	 */
	private function get_api_context(): array {
		if ( function_exists( 'd4h_core_get_context' ) ) {
			return array( d4h_core_get_context(), d4h_core_get_context_id() );
		}
		return array(
			get_option( $this->config['option_context'] ?? 'd4h_incidents_api_context', '' ),
			get_option( $this->config['option_context_id'] ?? 'd4h_incidents_api_context_id', '' ),
		);
	}

	public function handle_save_credentials(): void {
		if ( defined( 'D4H_CORE_ACTIVE' ) ) {
			return;
		}
		$slug = $this->config['admin_menu_slug'] ?? 'd4h-incidents';
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== $slug ) {
			return;
		}
		if ( ! current_user_can( $this->config['admin_capability'] ?? 'manage_options' ) ) {
			return;
		}
		if ( empty( $_POST['d4h_incidents_action'] ) || $_POST['d4h_incidents_action'] !== 'save_credentials' ) {
			return;
		}
		if ( ! wp_verify_nonce( isset( $_POST['d4h_incidents_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_incidents_nonce'] ) ) : '', 'd4h_incidents_save_credentials' ) ) {
			return;
		}

		$opt_token   = $this->config['option_token'] ?? 'd4h_incidents_api_token';
		$opt_context = $this->config['option_context'] ?? 'd4h_incidents_api_context';
		$opt_id      = $this->config['option_context_id'] ?? 'd4h_incidents_api_context_id';

		$token   = isset( $_POST['d4h_incidents_api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_incidents_api_token'] ) ) : '';
		$context = isset( $_POST['d4h_incidents_api_context'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_incidents_api_context'] ) ) : '';
		$context_id = isset( $_POST['d4h_incidents_api_context_id'] ) ? sanitize_text_field( wp_unslash( $_POST['d4h_incidents_api_context_id'] ) ) : '';

		update_option( $opt_token, $token, false );
		update_option( $opt_context, $context, false );
		update_option( $opt_id, $context_id, false );

		$url = add_query_arg( array( 'page' => $slug, 'saved' => '1' ), admin_url( defined( 'D4H_CORE_ACTIVE' ) ? 'admin.php' : 'options-general.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	public function enqueue_scripts( string $hook ): void {
		$slug     = $this->config['admin_menu_slug'] ?? 'd4h-incidents';
		$expected = defined( 'D4H_CORE_ACTIVE' ) ? 'd4h-core_page_' . $slug : 'settings_page_' . $slug;
		if ( $hook !== $expected ) {
			return;
		}

		wp_enqueue_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
			array(),
			'4.4.1',
			true
		);

		$admin_js_url = plugin_dir_url( D4H_INCIDENTS_PLUGIN_FILE ) . 'admin/admin.js';
		wp_enqueue_script(
			'd4h-incidents-admin',
			$admin_js_url,
			array( 'jquery', 'chartjs' ),
			D4H_INCIDENTS_VERSION,
			true
		);

		wp_localize_script( 'd4h-incidents-admin', 'd4hIncidentsAdmin', array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'd4h_incidents_admin' ),
			'actionFetch'    => $this->config['ajax_action_fetch'] ?? 'd4h_incidents_ajax_fetch',
			'actionExportExcel' => $this->config['ajax_action_export_excel'] ?? 'd4h_incidents_ajax_export_excel',
			'actionExportPng'   => $this->config['ajax_action_export_png'] ?? 'd4h_incidents_ajax_export_png',
		) );

		wp_enqueue_style(
			'd4h-incidents-admin',
			plugin_dir_url( D4H_INCIDENTS_PLUGIN_FILE ) . 'assets/admin.css',
			array(),
			D4H_INCIDENTS_VERSION
		);
	}

	public function ajax_fetch(): void {
		check_ajax_referer( 'd4h_incidents_admin', 'nonce' );
		if ( ! current_user_can( $this->config['admin_capability'] ?? 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'd4h-incidents' ) ), 403 );
		}

		$token = $this->get_token();
		if ( $token === '' ) {
			wp_send_json_error( array( 'message' => __( 'API token not set.', 'd4h-incidents' ) ), 400 );
		}

		$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';

		$default_days = (int) ( $this->config['default_range_days'] ?? 365 );
		if ( $from === '' ) {
			$from = gmdate( 'Y-m-d', strtotime( "-{$default_days} days" ) );
		}
		if ( $to === '' ) {
			$to = gmdate( 'Y-m-d' );
		}

		$from_ts = strtotime( $from );
		$to_ts   = strtotime( $to );
		if ( ! $from_ts || ! $to_ts || $from_ts > $to_ts ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date range.', 'd4h-incidents' ) ), 400 );
		}

		$api = new API_Client( $this->config, $token );
		list( $context, $context_id ) = $this->get_api_context();

		if ( empty( $context ) || empty( $context_id ) ) {
			$whoami = $api->whoami();
			if ( is_wp_error( $whoami ) ) {
				wp_send_json_error( array( 'message' => $whoami->get_error_message() ), 500 );
			}
			if ( is_array( $whoami ) ) {
				$context    = $whoami['context'] ?? $whoami['contextType'] ?? '';
				$context_id = (string) ( $whoami['id'] ?? $whoami['contextId'] ?? '' );
			}
		}

		if ( empty( $context ) || empty( $context_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not determine API context. Set context and context ID in settings.', 'd4h-incidents' ) ), 400 );
		}

		$context    = in_array( strtolower( $context ), array( 'team', 'organisation' ), true ) ? strtolower( $context ) : 'team';
		$context_id = preg_match( '/^[a-zA-Z0-9\-]+$/', trim( $context_id ) ) ? trim( $context_id ) : '';
		if ( $context_id === '' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid context ID.', 'd4h-incidents' ) ), 400 );
		}

		$incidents = $api->get_incidents( $context, $context_id, array(
			'starts_after'  => gmdate( 'Y-m-d', $from_ts ) . 'T00:00:00Z',
			'ends_before'   => gmdate( 'Y-m-d', $to_ts ) . 'T23:59:59Z',
		) );

		if ( is_wp_error( $incidents ) ) {
			wp_send_json_error( array( 'message' => $incidents->get_error_message() ), 500 );
		}

		$processed = $this->process_incidents( $incidents, $api, $context, $context_id );

		$transient_key = 'd4h_incidents_data_' . md5( $from . $to );
		set_transient( $transient_key, array(
			'from'      => $from,
			'to'        => $to,
			'incidents' => $incidents,
			'processed' => $processed,
		), $this->config['transient_ttl'] ?? HOUR_IN_SECONDS );

		update_option( $this->config['option_last_fetch'] ?? 'd4h_incidents_last_fetch', array(
			'transient_key' => $transient_key,
			'from'          => $from,
			'to'            => $to,
		), false );

		wp_send_json_success( $processed );
	}

	/**
	 * Process incidents into stats and chart data.
	 *
	 * @param array<int, array<string, mixed>> $incidents
	 * @param API_Client                       $api
	 * @param string                           $context
	 * @param string                           $context_id
	 * @return array<string, mixed>
	 */
	private function process_incidents( array $incidents, API_Client $api, string $context, string $context_id ): array {
		$stats = array(
			'total_incidents'   => count( $incidents ),
			'total_participants' => 0,
			'types'             => array(),
			'participant_counts' => array(),
			'month_hour'        => array(),
			'participants_by_incident' => array(),
		);

		$participant_totals = array();
		$month_hour_map     = array();

		foreach ( $incidents as $incident ) {
			$type = $this->get_incident_type( $incident );
			$stats['types'][ $type ] = ( $stats['types'][ $type ] ?? 0 ) + 1;

			$starts_at = $incident['startsAt'] ?? $incident['starts_at'] ?? '';
			$timestamp = is_numeric( $starts_at ) ? (int) $starts_at : strtotime( $starts_at );
			if ( $timestamp ) {
				$month = gmdate( 'Y-m', $timestamp );
				$hour  = (int) gmdate( 'G', $timestamp );
				$key   = $month . '-' . $hour;
				if ( ! isset( $month_hour_map[ $key ] ) ) {
					$month_hour_map[ $key ] = array( 'incidents' => 0, 'participants' => 0 );
				}
				$month_hour_map[ $key ]['incidents']++;
			}

			$participants = $this->extract_participants( $incident );
			$incident_id  = $incident['id'] ?? '';

			if ( empty( $participants ) && $incident_id !== '' ) {
				$attendance = $api->get_incident_attendance( $context, $context_id, (string) $incident_id );
				if ( ! is_wp_error( $attendance ) ) {
					$participants = $this->participants_from_attendance( $attendance );
				}
			}

			$participant_count = count( $participants );
			$stats['participants_by_incident'][] = $participant_count;
			$stats['total_participants'] += $participant_count;

			if ( $timestamp && isset( $month_hour_map[ $key ] ) ) {
				$month_hour_map[ $key ]['participants'] += $participant_count;
			}

			foreach ( $participants as $participant_name ) {
				$participant_totals[ $participant_name ] = ( $participant_totals[ $participant_name ] ?? 0 ) + 1;
			}
		}

		arsort( $participant_totals );
		$stats['participant_counts'] = $participant_totals;

		$months = array();
		$hours  = range( 0, 23 );
		foreach ( array_keys( $month_hour_map ) as $key ) {
			$parts = explode( '-', $key );
			if ( count( $parts ) >= 2 ) {
				$months[] = $parts[0];
			}
		}
		$months = array_values( array_unique( $months ) );
		sort( $months );

		$stats['month_hour'] = array(
			'months' => $months,
			'hours'  => $hours,
			'data'   => $month_hour_map,
		);

		$chart_types    = array();
		$chart_labels   = array();
		foreach ( $stats['types'] as $label => $count ) {
			$chart_labels[] = $label;
			$chart_types[]  = $count;
		}

		$chart_participants_labels = array_slice( array_keys( $participant_totals ), 0, 30 );
		$chart_participants_data   = array_slice( array_values( $participant_totals ), 0, 30 );

		return array(
			'stats'                  => $stats,
			'chart_types_labels'     => $chart_labels,
			'chart_types_data'       => $chart_types,
			'chart_participants_labels' => $chart_participants_labels,
			'chart_participants_data'   => $chart_participants_data,
			'chart_month_hour'       => $stats['month_hour'],
			'raw_incidents'          => $incidents,
		);
	}

	private function get_incident_type( array $incident ): string {
		$type = $incident['type'] ?? $incident['referenceType'] ?? $incident['activityType'] ?? 'incident';
		if ( is_string( $type ) && trim( $type ) !== '' ) {
			return ucfirst( trim( $type ) );
		}
		return __( 'Incident', 'd4h-incidents' );
	}

	private function extract_participants( array $incident ): array {
		$participants = array();
		$attendance   = $incident['attendance'] ?? $incident['activityAttendance'] ?? $incident['participants'] ?? array();
		if ( is_array( $attendance ) ) {
			foreach ( $attendance as $item ) {
				$member = $item['member'] ?? $item;
				$name   = $member['name'] ?? $member['fullName'] ?? $member['firstName'] ?? '';
				if ( is_array( $name ) ) {
					$name = trim( ( $name['first'] ?? '' ) . ' ' . ( $name['last'] ?? '' ) );
				}
				if ( is_string( $name ) && trim( $name ) !== '' ) {
					$participants[] = trim( $name );
				}
			}
		}
		return array_values( array_unique( $participants ) );
	}

	private function participants_from_attendance( array $attendance ): array {
		$participants = array();
		foreach ( $attendance as $item ) {
			$member = $item['member'] ?? $item;
			$name   = $member['name'] ?? $member['fullName'] ?? '';
			if ( is_array( $name ) ) {
				$name = trim( ( $name['first'] ?? '' ) . ' ' . ( $name['last'] ?? '' ) );
			}
			if ( is_string( $name ) && trim( $name ) !== '' ) {
				$participants[] = trim( $name );
			}
		}
		return array_values( array_unique( $participants ) );
	}

	public function ajax_export_excel(): void {
		check_ajax_referer( 'd4h_incidents_admin', 'nonce' );
		if ( ! current_user_can( $this->config['admin_capability'] ?? 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'd4h-incidents' ) ), 403 );
		}

		$last = get_option( $this->config['option_last_fetch'] ?? 'd4h_incidents_last_fetch', array() );
		if ( ! is_array( $last ) || empty( $last['transient_key'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No data to export. Fetch incidents first.', 'd4h-incidents' ) ), 400 );
		}

		$cached = get_transient( $last['transient_key'] );
		if ( ! is_array( $cached ) || empty( $cached['processed'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Cached data expired. Fetch incidents again.', 'd4h-incidents' ) ), 400 );
		}

		$processed = $cached['processed'];
		$stats     = $processed['stats'] ?? array();
		$incidents = $cached['incidents'] ?? array();

		$csv_lines   = array();
		$csv_lines[] = array( 'ID', 'Reference', 'Type', 'Started', 'Participant count' );
		foreach ( $incidents as $incident ) {
			$id       = $incident['id'] ?? '';
			$ref      = $incident['reference'] ?? $incident['referenceDescription'] ?? '';
			$type     = $this->get_incident_type( $incident );
			$started  = $incident['startsAt'] ?? $incident['starts_at'] ?? '';
			$participants = $this->extract_participants( $incident );
			$count    = count( $participants );
			$csv_lines[] = array( $id, $ref, $type, $started, $count );
		}

		$output = "\xEF\xBB\xBF" . $this->array_to_csv( $csv_lines );
		$filename = 'd4h-incidents-' . ( $last['from'] ?? 'export' ) . '-to-' . ( $last['to'] ?? 'export' ) . '.csv';

		wp_send_json_success( array(
			'csv'      => $output,
			'filename' => $filename,
		) );
	}

	/**
	 * Return CSV string from array of rows.
	 *
	 * @param array<int, array<int, string>> $rows
	 * @return string
	 */
	private function array_to_csv( array $rows ): string {
		$out = fopen( 'php://temp', 'r+' );
		if ( $out === false ) {
			return '';
		}
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out );
		return $csv !== false ? $csv : '';
	}

	public function ajax_export_png(): void {
		check_ajax_referer( 'd4h_incidents_admin', 'nonce' );
		if ( ! current_user_can( $this->config['admin_capability'] ?? 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'd4h-incidents' ) ), 403 );
		}

		$chart_index = isset( $_POST['chart'] ) ? (int) $_POST['chart'] : 0;
		$last = get_option( $this->config['option_last_fetch'] ?? 'd4h_incidents_last_fetch', array() );
		if ( ! is_array( $last ) || empty( $last['transient_key'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No data to export. Fetch incidents first.', 'd4h-incidents' ) ), 400 );
		}

		$cached = get_transient( $last['transient_key'] );
		if ( ! is_array( $cached ) || empty( $cached['processed'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Cached data expired. Fetch again.', 'd4h-incidents' ) ), 400 );
		}

		wp_send_json_success( array(
			'message' => __( 'Use the Export PNG button below each chart to download that chart as an image.', 'd4h-incidents' ),
			'chart_index' => $chart_index,
		) );
	}

	public function render_page(): void {
		list( $context, $context_id ) = $this->get_api_context();
		$default_days = (int) ( $this->config['default_range_days'] ?? 365 );
		$saved = isset( $_GET['saved'] ) && $_GET['saved'] === '1';
		?>
		<div class="wrap d4h-incidents-wrap">
			<h1><?php echo esc_html( $this->config['admin_page_title'] ?? 'D4H Incidents' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'd4h-incidents' ); ?></p></div>
			<?php endif; ?>

			<div class="d4h-incidents-section">
				<h2><?php esc_html_e( 'API credentials', 'd4h-incidents' ); ?></h2>
				<?php if ( defined( 'D4H_CORE_ACTIVE' ) ) : ?>
					<p><?php esc_html_e( 'API credentials are managed in D4H → Settings.', 'd4h-incidents' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=d4h-core' ) ); ?>"><?php esc_html_e( 'Go to D4H Settings', 'd4h-incidents' ); ?></a></p>
				<?php else : ?>
					<?php $token = $this->get_token(); ?>
					<form method="post" action="">
						<?php wp_nonce_field( 'd4h_incidents_save_credentials', 'd4h_incidents_nonce' ); ?>
						<input type="hidden" name="d4h_incidents_action" value="save_credentials" />
						<table class="form-table">
							<tr>
								<th scope="row"><label for="d4h_incidents_api_token"><?php esc_html_e( 'API Token', 'd4h-incidents' ); ?></label></th>
								<td><input type="password" id="d4h_incidents_api_token" name="d4h_incidents_api_token" value="<?php echo esc_attr( $token ); ?>" class="regular-text" autocomplete="off" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="d4h_incidents_api_context"><?php esc_html_e( 'Context (team or organisation)', 'd4h-incidents' ); ?></label></th>
								<td><input type="text" id="d4h_incidents_api_context" name="d4h_incidents_api_context" value="<?php echo esc_attr( $context ); ?>" placeholder="team" class="regular-text" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="d4h_incidents_api_context_id"><?php esc_html_e( 'Context ID', 'd4h-incidents' ); ?></label></th>
								<td><input type="text" id="d4h_incidents_api_context_id" name="d4h_incidents_api_context_id" value="<?php echo esc_attr( $context_id ); ?>" class="regular-text" /></td>
							</tr>
						</table>
						<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save credentials', 'd4h-incidents' ); ?>" /></p>
					</form>
				<?php endif; ?>
			</div>

			<hr />

			<div class="d4h-incidents-section">
				<h2><?php esc_html_e( 'Fetch incidents', 'd4h-incidents' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Select a time period and fetch incident data from D4H.', 'd4h-incidents' ); ?></p>
				<div class="d4h-incidents-fetch-form">
					<label for="d4h_incidents_from"><?php esc_html_e( 'From:', 'd4h-incidents' ); ?></label>
					<input type="date" id="d4h_incidents_from" value="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( "-{$default_days} days" ) ) ); ?>" />
					<label for="d4h_incidents_to"><?php esc_html_e( 'To:', 'd4h-incidents' ); ?></label>
					<input type="date" id="d4h_incidents_to" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
					<button type="button" id="d4h-incidents-fetch" class="button button-primary"><?php esc_html_e( 'Fetch data', 'd4h-incidents' ); ?></button>
					<span id="d4h-incidents-presets">
						<button type="button" class="button button-small d4h-preset" data-days="7"><?php esc_html_e( '7 days', 'd4h-incidents' ); ?></button>
						<button type="button" class="button button-small d4h-preset" data-days="30"><?php esc_html_e( '30 days', 'd4h-incidents' ); ?></button>
						<button type="button" class="button button-small d4h-preset" data-days="90"><?php esc_html_e( '90 days', 'd4h-incidents' ); ?></button>
						<button type="button" class="button button-small d4h-preset" data-days="365"><?php esc_html_e( '1 year', 'd4h-incidents' ); ?></button>
					</span>
				</div>
				<div id="d4h-incidents-message" class="notice" style="display:none;"></div>
				<div id="d4h-incidents-loading" style="display:none;"><?php esc_html_e( 'Fetching...', 'd4h-incidents' ); ?></div>
			</div>

			<div id="d4h-incidents-results" class="d4h-incidents-results" style="display:none;">
				<h2><?php esc_html_e( 'Statistics', 'd4h-incidents' ); ?></h2>
				<div class="d4h-incidents-stats-cards">
					<div class="d4h-stat-card">
						<span class="d4h-stat-value" id="d4h-stat-incidents">0</span>
						<span class="d4h-stat-label"><?php esc_html_e( 'Total incidents', 'd4h-incidents' ); ?></span>
					</div>
					<div class="d4h-stat-card">
						<span class="d4h-stat-value" id="d4h-stat-participants">0</span>
						<span class="d4h-stat-label"><?php esc_html_e( 'Total participants', 'd4h-incidents' ); ?></span>
					</div>
				</div>

				<h3><?php esc_html_e( 'Incident types', 'd4h-incidents' ); ?></h3>
				<div class="d4h-chart-container">
					<canvas id="d4h-chart-types"></canvas>
					<button type="button" class="button d4h-export-png" data-chart="types"><?php esc_html_e( 'Export PNG', 'd4h-incidents' ); ?></button>
				</div>

				<h3><?php esc_html_e( 'Participants by incident count', 'd4h-incidents' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Total incidents each participant took part in (top 30).', 'd4h-incidents' ); ?></p>
				<div class="d4h-chart-container">
					<canvas id="d4h-chart-participants"></canvas>
					<button type="button" class="button d4h-export-png" data-chart="participants"><?php esc_html_e( 'Export PNG', 'd4h-incidents' ); ?></button>
				</div>

				<h3><?php esc_html_e( 'Incidents by month and hour', 'd4h-incidents' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Heatmap of incidents and participants over time.', 'd4h-incidents' ); ?></p>
				<div class="d4h-chart-container">
					<canvas id="d4h-chart-month-hour"></canvas>
					<button type="button" class="button d4h-export-png" data-chart="month-hour"><?php esc_html_e( 'Export PNG', 'd4h-incidents' ); ?></button>
				</div>

				<div class="d4h-incidents-export">
					<button type="button" id="d4h-incidents-export-excel" class="button button-secondary"><?php esc_html_e( 'Export to Excel (CSV)', 'd4h-incidents' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}
}
