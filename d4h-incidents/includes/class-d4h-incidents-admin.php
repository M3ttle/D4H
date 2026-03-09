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

	public function enqueue_scripts( string $hook ): void {
		$slug     = $this->config['admin_menu_slug'] ?? 'd4h-incidents';
		$expected = defined( 'D4H_CORE_ACTIVE' ) ? 'd4h-core_page_' . $slug : 'settings_page_' . $slug;
		$page     = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( $hook !== $expected && $page !== $slug ) {
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

		$from          = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to            = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$resource_type = isset( $_POST['resource_type'] ) ? sanitize_text_field( wp_unslash( $_POST['resource_type'] ) ) : 'Incident';

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

		if ( ! in_array( $resource_type, array( 'Event', 'Exercise', 'Incident' ), true ) ) {
			$resource_type = 'Incident';
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
			'after'         => gmdate( 'Y-m-d', $from_ts ) . 'T00:00:00Z',
			'before'        => gmdate( 'Y-m-d', $to_ts ) . 'T23:59:59Z',
			'resource_type' => $resource_type,
		) );

		if ( is_wp_error( $incidents ) ) {
			wp_send_json_error( array( 'message' => $incidents->get_error_message() ), 500 );
		}

		$processed = $this->process_incidents( $incidents, $api, $context, $context_id );

		$transient_key = 'd4h_incidents_data_' . md5( $from . $to . $resource_type );
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

		$processed['resource_type'] = $resource_type;
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
			'total_incidents'                   => count( $incidents ),
			'total_participants'                => 0,
			'total_duration_seconds'            => 0,
			'total_duration_formatted'          => '',
			'average_duration_seconds'          => 0,
			'average_duration_formatted'        => '',
			'average_participants_per_incident' => 0,
			'types'             => array(),
			'participant_counts' => array(),
			'month_hour'        => array(),
			'participants_by_incident' => array(),
		);

		$participant_totals = array();
		$month_hour_map     = array();

		$stats['incidents_list'] = array();

		foreach ( $incidents as $incident ) {
			$type = $this->get_incident_type( $incident );
			$stats['types'][ $type ] = ( $stats['types'][ $type ] ?? 0 ) + 1;

			$starts_at = $incident['startsAt'] ?? $incident['starts_at'] ?? '';
			$ends_at   = $incident['endsAt'] ?? $incident['ends_at'] ?? '';
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

			$participant_count = (int) ( $incident['countAttendance'] ?? $incident['count_attendance'] ?? 0 );
			$stats['participants_by_incident'][] = $participant_count;
			$stats['total_participants'] += $participant_count;

			if ( $timestamp && isset( $month_hour_map[ $key ] ) ) {
				$month_hour_map[ $key ]['participants'] += $participant_count;
			}

			$details = $this->extract_incident_details( $incident, $participant_count );
			$stats['total_duration_seconds'] += $details['duration_seconds'];
			$stats['incidents_list'][] = $details;
		}

		$stats['total_duration_formatted'] = $this->format_duration_seconds( $stats['total_duration_seconds'] );

		$total_count = count( $incidents );
		$stats['average_duration_seconds']   = $total_count > 0 ? (int) round( $stats['total_duration_seconds'] / $total_count ) : 0;
		$stats['average_duration_formatted'] = $this->format_duration_seconds( $stats['average_duration_seconds'] );

		arsort( $participant_totals );
		$stats['participant_counts'] = $participant_totals;
		$stats['average_participants_per_incident'] = $total_count > 0
			? round( $stats['total_participants'] / $total_count, 1 )
			: 0;

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

	/**
	 * Format seconds as e.g. "5h 30m" or "45m".
	 *
	 * @param int $seconds
	 * @return string
	 */
	private function format_duration_seconds( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return '0m';
		}
		$hours   = (int) floor( $seconds / 3600 );
		$minutes = (int) floor( ( $seconds % 3600 ) / 60 );
		$parts   = array();
		if ( $hours > 0 ) {
			$parts[] = $hours . 'h';
		}
		$parts[] = $minutes . 'm';
		return implode( ' ', $parts );
	}

	/**
	 * Extract incident details: title, location, description (HTML stripped), date, duration, participants (count).
	 *
	 * @param array<string, mixed> $incident
	 * @param int                  $participant_count From countAttendance
	 * @return array{title: string, name: string, location_coords: string, location_url: string, description: string, date: string, duration: string, duration_seconds: int, participants: int}
	 */
	private function extract_incident_details( array $incident, int $participant_count ): array {
		$title = $incident['reference'] ?? $incident['referenceDescription'] ?? $incident['name'] ?? $incident['title'] ?? '';
		$title = is_string( $title ) ? trim( $title ) : '';

		$location_coords = '';
		$location_url    = '';
		$location        = $incident['location'] ?? null;
		if ( is_array( $location ) && ! empty( $location['coordinates'] ) && is_array( $location['coordinates'] ) ) {
			$coords = $location['coordinates'];
			$lon    = isset( $coords[0] ) ? (float) $coords[0] : null;
			$lat    = isset( $coords[1] ) ? (float) $coords[1] : null;
			if ( $lat !== null && $lon !== null ) {
				$location_coords = $lat . ', ' . $lon;
				$location_url    = 'https://www.google.com/maps?q=' . $lat . ',' . $lon;
			}
		}

		$description = $incident['description'] ?? '';
		$description = is_string( $description ) ? trim( $description ) : '';
		$description = wp_strip_all_tags( $description );

		$starts_at = $incident['startsAt'] ?? $incident['starts_at'] ?? '';
		$ends_at   = $incident['endsAt'] ?? $incident['ends_at'] ?? '';
		$start_ts  = is_numeric( $starts_at ) ? (int) $starts_at : strtotime( $starts_at );
		$end_ts    = is_numeric( $ends_at ) ? (int) $ends_at : strtotime( $ends_at );

		$date = $start_ts ? gmdate( 'Y-m-d H:i', $start_ts ) : '';

		$duration_seconds = 0;
		$duration         = '';
		if ( $start_ts && $end_ts && $end_ts >= $start_ts ) {
			$duration_seconds = $end_ts - $start_ts;
			$duration         = $this->format_duration_seconds( $duration_seconds );
		}

		return array(
			'title'            => $title,
			'name'             => $title,
			'location_coords'  => $location_coords,
			'location_url'     => $location_url,
			'description'      => $description,
			'date'             => $date,
			'duration'         => $duration,
			'duration_seconds' => $duration_seconds,
			'participants'     => $participant_count,
		);
	}

	private function get_incident_type( array $incident ): string {
		$type = $incident['type'] ?? $incident['referenceType'] ?? $incident['activityType'] ?? 'incident';
		if ( is_string( $type ) && trim( $type ) !== '' ) {
			return ucfirst( trim( $type ) );
		}
		return __( 'Incident', 'd4h-incidents' );
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

		$processed    = $cached['processed'];
		$stats        = $processed['stats'] ?? array();
		$incidents_list = $stats['incidents_list'] ?? array();

		$csv_lines   = array();
		$csv_lines[] = array( 'Date', 'Location', 'Title', 'Description', 'Duration', 'Participants' );
		foreach ( $incidents_list as $item ) {
			$participants_count = (int) ( $item['participants'] ?? 0 );
			$csv_lines[] = array(
				$item['date'] ?? '',
				$item['location_coords'] ?? '',
				$item['title'] ?? $item['name'] ?? '',
				$item['description'] ?? '',
				$item['duration'] ?? '',
				$participants_count,
			);
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
		$default_days = (int) ( $this->config['default_range_days'] ?? 365 );
		?>
		<div class="wrap d4h-incidents-wrap">
			<h1><?php echo esc_html( $this->config['admin_page_title'] ?? 'D4H Incidents' ); ?></h1>

			<div class="d4h-incidents-section">
				<h2><?php esc_html_e( 'Fetch incidents', 'd4h-incidents' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Select a time period and fetch incident data from D4H.', 'd4h-incidents' ); ?></p>
				<div class="d4h-incidents-fetch-form">
					<label for="d4h_incidents_from"><?php esc_html_e( 'From:', 'd4h-incidents' ); ?></label>
					<input type="date" id="d4h_incidents_from" value="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( "-{$default_days} days" ) ) ); ?>" />
					<label for="d4h_incidents_to"><?php esc_html_e( 'To:', 'd4h-incidents' ); ?></label>
					<input type="date" id="d4h_incidents_to" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
					<label for="d4h_incidents_resource_type"><?php esc_html_e( 'Type:', 'd4h-incidents' ); ?></label>
					<select id="d4h_incidents_resource_type">
						<option value="Incident" selected><?php esc_html_e( 'Incident', 'd4h-incidents' ); ?></option>
						<option value="Event"><?php esc_html_e( 'Event', 'd4h-incidents' ); ?></option>
						<option value="Exercise"><?php esc_html_e( 'Exercise', 'd4h-incidents' ); ?></option>
					</select>
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
						<span class="d4h-stat-label" id="d4h-stat-incidents-label"><?php esc_html_e( 'Total incidents', 'd4h-incidents' ); ?></span>
					</div>
					<div class="d4h-stat-card">
						<span class="d4h-stat-value" id="d4h-stat-participants">0</span>
						<span class="d4h-stat-label"><?php esc_html_e( 'Total participants', 'd4h-incidents' ); ?></span>
					</div>
					<div class="d4h-stat-card">
						<span class="d4h-stat-value" id="d4h-stat-duration">0m</span>
						<span class="d4h-stat-label"><?php esc_html_e( 'Total duration', 'd4h-incidents' ); ?></span>
					</div>
					<div class="d4h-stat-card">
						<span class="d4h-stat-value" id="d4h-stat-avg-participants">0</span>
						<span class="d4h-stat-label" id="d4h-stat-avg-label"><?php esc_html_e( 'Avg participants per incident', 'd4h-incidents' ); ?></span>
					</div>
					<div class="d4h-stat-card">
						<span class="d4h-stat-value" id="d4h-stat-avg-duration">0m</span>
						<span class="d4h-stat-label" id="d4h-stat-avg-duration-label"><?php esc_html_e( 'Avg duration per incident', 'd4h-incidents' ); ?></span>
					</div>
				</div>

				<div class="d4h-incidents-table-section">
					<h3><?php esc_html_e( 'Incidents', 'd4h-incidents' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Date, location, title, description, duration, and participants.', 'd4h-incidents' ); ?></p>
					<div class="d4h-incidents-table-paging">
						<label for="d4h-incidents-per-page"><?php esc_html_e( 'Show', 'd4h-incidents' ); ?></label>
						<select id="d4h-incidents-per-page">
							<option value="10" selected>10</option>
							<option value="25">25</option>
							<option value="50">50</option>
							<option value="100">100</option>
							<option value="200">200</option>
						</select>
						<span><?php esc_html_e( 'per page', 'd4h-incidents' ); ?></span>
						<span id="d4h-incidents-paging-info" class="d4h-paging-info"></span>
						<span id="d4h-incidents-paging-buttons" class="d4h-paging-buttons"></span>
					</div>
					<div class="d4h-incidents-table-wrapper">
						<table class="wp-list-table widefat fixed striped" id="d4h-incidents-table">
							<thead>
								<tr>
									<th class="d4h-sortable" data-sort="date"><?php esc_html_e( 'Date', 'd4h-incidents' ); ?></th>
									<th class="d4h-sortable" data-sort="location_coords"><?php esc_html_e( 'Location', 'd4h-incidents' ); ?></th>
									<th class="d4h-sortable" data-sort="title"><?php esc_html_e( 'Title', 'd4h-incidents' ); ?></th>
									<th><?php esc_html_e( 'Description', 'd4h-incidents' ); ?></th>
									<th class="d4h-sortable" data-sort="duration"><?php esc_html_e( 'Duration', 'd4h-incidents' ); ?></th>
									<th class="d4h-sortable" data-sort="participants"><?php esc_html_e( 'Participants', 'd4h-incidents' ); ?></th>
								</tr>
							</thead>
							<tbody id="d4h-incidents-table-body">
							</tbody>
						</table>
					</div>
					<div class="d4h-incidents-table-export">
						<button type="button" id="d4h-incidents-export-csv" class="button button-secondary"><?php esc_html_e( 'Export CSV (all incidents)', 'd4h-incidents' ); ?></button>
					</div>
				</div>

				<div class="d4h-chart-section">
					<h3><?php esc_html_e( 'Incident types', 'd4h-incidents' ); ?></h3>
					<div class="d4h-chart-container">
						<canvas id="d4h-chart-types"></canvas>
						<div class="d4h-chart-export-buttons">
							<button type="button" class="button d4h-export-png" data-chart="types"><?php esc_html_e( 'Export PNG', 'd4h-incidents' ); ?></button>
							<button type="button" class="button d4h-export-csv" data-chart="types"><?php esc_html_e( 'Export CSV', 'd4h-incidents' ); ?></button>
						</div>
					</div>
				</div>

				<div class="d4h-chart-section">
					<h3><?php esc_html_e( 'Participants by incident count', 'd4h-incidents' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Total incidents each participant took part in (top 30).', 'd4h-incidents' ); ?></p>
					<div class="d4h-chart-container">
						<canvas id="d4h-chart-participants"></canvas>
						<div class="d4h-chart-export-buttons">
							<button type="button" class="button d4h-export-png" data-chart="participants"><?php esc_html_e( 'Export PNG', 'd4h-incidents' ); ?></button>
							<button type="button" class="button d4h-export-csv" data-chart="participants"><?php esc_html_e( 'Export CSV', 'd4h-incidents' ); ?></button>
						</div>
					</div>
				</div>

				<div class="d4h-chart-section">
					<h3><?php esc_html_e( 'Incidents by month and hour', 'd4h-incidents' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Heatmap of incidents and participants over time.', 'd4h-incidents' ); ?></p>
					<div class="d4h-chart-container">
						<canvas id="d4h-chart-month-hour"></canvas>
						<div class="d4h-chart-export-buttons">
							<button type="button" class="button d4h-export-png" data-chart="month-hour"><?php esc_html_e( 'Export PNG', 'd4h-incidents' ); ?></button>
							<button type="button" class="button d4h-export-csv" data-chart="month-hour"><?php esc_html_e( 'Export CSV', 'd4h-incidents' ); ?></button>
						</div>
					</div>
				</div>

				<div class="d4h-incidents-export">
					<button type="button" id="d4h-incidents-export-excel" class="button button-secondary"><?php esc_html_e( 'Export to Excel (CSV)', 'd4h-incidents' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}
}
