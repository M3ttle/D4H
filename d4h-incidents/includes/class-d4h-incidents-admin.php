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

		$action_fetch       = $this->config['ajax_action_fetch'] ?? 'd4h_incidents_ajax_fetch';
		$action_members     = $this->config['ajax_action_member_names'] ?? 'd4h_incidents_ajax_fetch_member_names';
		$action_excel       = $this->config['ajax_action_export_excel'] ?? 'd4h_incidents_ajax_export_excel';
		$action_png         = $this->config['ajax_action_export_png'] ?? 'd4h_incidents_ajax_export_png';
		$action_report_tags = $this->config['ajax_action_export_report_by_tags'] ?? 'd4h_incidents_ajax_export_report_by_tags';

		add_action( 'wp_ajax_' . $action_fetch, array( $this, 'ajax_fetch' ) );
		add_action( 'wp_ajax_' . $action_members, array( $this, 'ajax_fetch_member_names' ) );
		add_action( 'wp_ajax_' . $action_excel, array( $this, 'ajax_export_excel' ) );
		add_action( 'wp_ajax_' . $action_png, array( $this, 'ajax_export_png' ) );
		add_action( 'wp_ajax_' . $action_report_tags, array( $this, 'ajax_export_report_by_tags' ) );
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

		$tags_map = array();
		if ( function_exists( 'd4h_core_get_tags_map' ) ) {
			$tags_map = d4h_core_get_tags_map();
		}
		if ( empty( $tags_map ) ) {
			$tags_map = get_option( 'd4h_core_tags_map', array() );
		}
		$initial_tags = array();
		if ( is_array( $tags_map ) ) {
			foreach ( $tags_map as $id => $name ) {
				$initial_tags[] = array( 'id' => $id, 'name' => $name );
			}
		}

		wp_localize_script( 'd4h-incidents-admin', 'd4hIncidentsAdmin', array(
			'ajaxUrl'                  => admin_url( 'admin-ajax.php' ),
			'nonce'                    => wp_create_nonce( 'd4h_incidents_admin' ),
			'actionFetch'              => $this->config['ajax_action_fetch'] ?? 'd4h_incidents_ajax_fetch',
			'actionMemberNames'        => $this->config['ajax_action_member_names'] ?? 'd4h_incidents_ajax_fetch_member_names',
			'actionExportExcel'        => $this->config['ajax_action_export_excel'] ?? 'd4h_incidents_ajax_export_excel',
			'actionExportPng'          => $this->config['ajax_action_export_png'] ?? 'd4h_incidents_ajax_export_png',
			'actionExportReportByTags' => $this->config['ajax_action_export_report_by_tags'] ?? 'd4h_incidents_ajax_export_report_by_tags',
			'initialTags'              => $initial_tags,
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
			'after'  => gmdate( 'Y-m-d', $from_ts ) . 'T00:00:00Z',
			'before' => gmdate( 'Y-m-d', $to_ts ) . 'T23:59:59Z',
		) );

		if ( is_wp_error( $incidents ) ) {
			wp_send_json_error( array( 'message' => $incidents->get_error_message() ), 500 );
		}

		$tag_ids_raw = isset( $_POST['tag_ids'] ) && is_array( $_POST['tag_ids'] ) ? wp_unslash( $_POST['tag_ids'] ) : array();
		$selected_tag_ids = array();
		$no_tag_value = '__no_tag__';
		foreach ( $tag_ids_raw as $id ) {
			$id = is_string( $id ) ? trim( $id ) : (string) $id;
			if ( $id !== '' ) {
				$selected_tag_ids[] = $id;
			}
		}
		if ( ! empty( $selected_tag_ids ) ) {
			$incidents = $this->filter_incidents_by_tags( $incidents, $selected_tag_ids, $no_tag_value );
		}

		$tags_map = $this->get_tags_map( $api, $context, $context_id );

		$ends_after  = gmdate( 'Y-m-d\T00:01:00.000\Z', $from_ts );
		$ends_before = gmdate( 'Y-m-d\T00:01:00.000\Z', strtotime( $to . ' +1 day' ) );
		$attendance  = $api->get_team_attendance( $context, $context_id, $ends_after, $ends_before );
		if ( is_wp_error( $attendance ) ) {
			$attendance = array();
		}

		$processed = $this->process_incidents( $incidents, $api, $context, $context_id, $attendance, $tags_map );

		$transient_key = 'd4h_incidents_data_' . md5( $from . $to );
		set_transient( $transient_key, array(
			'from'        => $from,
			'to'          => $to,
			'incidents'   => $incidents,
			'processed'   => $processed,
			'context'     => $context,
			'context_id'  => $context_id,
		), $this->config['transient_ttl'] ?? HOUR_IN_SECONDS );

		update_option( $this->config['option_last_fetch'] ?? 'd4h_incidents_last_fetch', array(
			'transient_key' => $transient_key,
			'from'          => $from,
			'to'            => $to,
			'context'       => $context,
			'context_id'    => $context_id,
		), false );

		wp_send_json_success( $processed );
	}

	/**
	 * AJAX handler: Fetch names for member IDs.
	 * Uses last fetch context. Returns map of member_id => first two names (split on space).
	 */
	public function ajax_fetch_member_names(): void {
		check_ajax_referer( 'd4h_incidents_admin', 'nonce' );
		if ( ! current_user_can( $this->config['admin_capability'] ?? 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'd4h-incidents' ) ), 403 );
		}

		$token = $this->get_token();
		if ( $token === '' ) {
			wp_send_json_error( array( 'message' => __( 'API token not set.', 'd4h-incidents' ) ), 400 );
		}

		$last = get_option( $this->config['option_last_fetch'] ?? 'd4h_incidents_last_fetch', array() );
		if ( ! is_array( $last ) || empty( $last['transient_key'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Fetch incidents first.', 'd4h-incidents' ) ), 400 );
		}

		$cached = get_transient( $last['transient_key'] );
		if ( ! is_array( $cached ) || empty( $cached['context'] ) || empty( $cached['context_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Cached data expired. Fetch incidents again.', 'd4h-incidents' ) ), 400 );
		}

		$member_ids_raw = isset( $_POST['member_ids'] ) && is_array( $_POST['member_ids'] )
			? wp_unslash( $_POST['member_ids'] )
			: array();
		$member_ids = array();
		foreach ( $member_ids_raw as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$member_ids[] = $id;
			}
		}
		$member_ids = array_values( array_unique( $member_ids ) );

		if ( empty( $member_ids ) ) {
			wp_send_json_success( array( 'names' => array() ) );
			return;
		}

		$api        = new API_Client( $this->config, $token );
		$context    = $cached['context'];
		$context_id = $cached['context_id'];
		$names      = array();

		foreach ( $member_ids as $member_id ) {
			$member = $api->get_member( $context, $context_id, $member_id );
			if ( is_wp_error( $member ) ) {
				$names[ (string) $member_id ] = (string) $member_id;
				continue;
			}
			$full_name = isset( $member['name'] ) ? trim( (string) $member['name'] ) : '';
			if ( $full_name === 'Jón Pétur Jónsson' ) {
				$names[ (string) $member_id ] = 'Nonni';
				continue;
			}
			$parts     = $full_name !== '' ? preg_split( '/\s+/', $full_name, 3 ) : array();
			$first_two = isset( $parts[0] ) ? implode( ' ', array_slice( array_map( 'trim', $parts ), 0, 2 ) ) : '';
			$names[ (string) $member_id ] = $first_two !== '' ? $first_two : (string) $member_id;
		}

		wp_send_json_success( array( 'names' => $names ) );
	}

	/**
	 * Filter incidents to those that have at least one of the selected tags (or no tag if __no_tag__).
	 *
	 * @param array<int, array<string, mixed>> $incidents
	 * @param array<string>                    $selected_tag_ids
	 * @param string                           $no_tag_value
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_incidents_by_tags( array $incidents, array $selected_tag_ids, string $no_tag_value = '__no_tag__' ): array {
		return array_values( array_filter( $incidents, function ( $incident ) use ( $selected_tag_ids, $no_tag_value ) {
			$tag_ids  = array();
			$raw_tags = $incident['tags'] ?? $incident['activityTags'] ?? $incident['activity_tags'] ?? array();
			if ( is_array( $raw_tags ) ) {
				foreach ( $raw_tags as $item ) {
					$tag_obj = is_array( $item ) ? ( $item['tag'] ?? $item ) : null;
					if ( is_array( $tag_obj ) && isset( $tag_obj['id'] ) ) {
						$tag_ids[] = (string) (int) $tag_obj['id'];
					} elseif ( is_numeric( $item ) && (int) $item > 0 ) {
						$tag_ids[] = (string) (int) $item;
					}
				}
			}
			$tag_ids_only = $incident['tagIds'] ?? $incident['tag_ids'] ?? array();
			if ( is_array( $tag_ids_only ) ) {
				foreach ( $tag_ids_only as $tid ) {
					if ( is_numeric( $tid ) && (int) $tid > 0 ) {
						$tag_ids[] = (string) (int) $tid;
					}
				}
			}
			$tag_ids   = array_unique( $tag_ids );
			$has_tags  = ! empty( $tag_ids );
			if ( $has_tags ) {
				foreach ( $tag_ids as $tid ) {
					if ( in_array( $tid, $selected_tag_ids, true ) ) {
						return true;
					}
				}
				return false;
			}
			return in_array( $no_tag_value, $selected_tag_ids, true );
		} ) );
	}

	/**
	 * Compute unique participants per period from incident-to-members mapping.
	 *
	 * @param array<int, int> $incident_id_to_date Incident ID => Unix timestamp
	 * @param array<int, array<int, bool>> $incident_to_members Incident ID => [ Member ID => true ]
	 * @return array{weekly: array{labels: array, incidents: array, participants: array}, monthly: array{labels: array, incidents: array, participants: array}, yearly: array{labels: array, incidents: array, participants: array}}
	 */
	private function compute_unique_participants_over_time( array $incident_id_to_date, array $incident_to_members ): array {
		$periods = array(
			'weekly'  => array( 'labels' => array(), 'incidents' => array(), 'participants' => array(), 'keys' => array(), 'incident_ids' => array() ),
			'monthly' => array( 'labels' => array(), 'incidents' => array(), 'participants' => array(), 'keys' => array(), 'incident_ids' => array() ),
			'yearly'  => array( 'labels' => array(), 'incidents' => array(), 'participants' => array(), 'keys' => array(), 'incident_ids' => array() ),
		);

		foreach ( $incident_id_to_date as $incident_id => $timestamp ) {
			$week_key   = gmdate( 'Y-\WW', $timestamp );
			$week_label = gmdate( 'Y-m-d', strtotime( 'monday this week', $timestamp ) );
			$month_key  = gmdate( 'Y-m', $timestamp );
			$month_label = $month_key;
			$year_key   = gmdate( 'Y', $timestamp );
			$year_label = $year_key;

			foreach ( array( 'weekly' => array( $week_key, $week_label ), 'monthly' => array( $month_key, $month_label ), 'yearly' => array( $year_key, $year_label ) ) as $period => $key_label ) {
				list( $key, $label ) = $key_label;
				if ( ! isset( $periods[ $period ]['keys'][ $key ] ) ) {
					$periods[ $period ]['keys'][ $key ]       = count( $periods[ $period ]['labels'] );
					$periods[ $period ]['labels'][]          = $label;
					$periods[ $period ]['incidents'][]       = 0;
					$periods[ $period ]['participants'][]    = 0;
					$periods[ $period ]['incident_ids'][ $key ] = array();
				}
				$idx = $periods[ $period ]['keys'][ $key ];
				$periods[ $period ]['incidents'][ $idx ]++;
				$periods[ $period ]['incident_ids'][ $key ][] = $incident_id;
			}
		}

		foreach ( array_keys( $periods ) as $period ) {
			foreach ( $periods[ $period ]['incident_ids'] ?? array() as $key => $incident_ids ) {
				$idx = $periods[ $period ]['keys'][ $key ] ?? 0;
				$unique_members = array();
				foreach ( $incident_ids as $incident_id ) {
					$members = $incident_to_members[ $incident_id ] ?? array();
					foreach ( array_keys( $members ) as $member_id ) {
						$unique_members[ $member_id ] = true;
					}
				}
				$periods[ $period ]['participants'][ $idx ] = count( $unique_members );
			}
			unset( $periods[ $period ]['keys'], $periods[ $period ]['incident_ids'] );
		}

		return $periods;
	}

	/**
	 * Get tags map (id => name). Reuses D4H Calendar tags if available, else fetches from API.
	 *
	 * @param API_Client $api
	 * @param string     $context
	 * @param string     $context_id
	 * @return array<int, string>
	 */
	private function get_tags_map( API_Client $api, string $context, string $context_id ): array {
		if ( function_exists( 'd4h_core_get_tags_map' ) ) {
			$core_tags = d4h_core_get_tags_map();
			if ( is_array( $core_tags ) && ! empty( $core_tags ) ) {
				return $core_tags;
			}
		}
		$tags_map = get_option( 'd4h_calendar_tags_map', array() );
		if ( is_array( $tags_map ) && ! empty( $tags_map ) ) {
			return $tags_map;
		}
		$tags = $api->get_tags( $context, $context_id );
		if ( is_wp_error( $tags ) || ! is_array( $tags ) ) {
			return array();
		}
		return $this->build_tags_map( $tags );
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
			$tags_map[ $id ] = $name !== '' ? $name : sprintf( __( 'Tag %d', 'd4h-incidents' ), $id );
		}
		return $tags_map;
	}

	/**
	 * Collect tag id => name from incident tag objects. Used when API/Core tags are empty.
	 *
	 * @param array<int, array<string, mixed>> $incidents
	 * @return array<int, string>
	 */
	private function collect_tags_from_incidents( array $incidents ): array {
		$tags_map = array();
		foreach ( $incidents as $incident ) {
			$raw_tags = $incident['tags'] ?? $incident['activityTags'] ?? $incident['activity_tags'] ?? array();
			if ( is_array( $raw_tags ) ) {
				foreach ( $raw_tags as $item ) {
					$tag_obj = is_array( $item ) ? ( $item['tag'] ?? $item ) : null;
					if ( is_array( $tag_obj ) ) {
						$id = isset( $tag_obj['id'] ) ? (int) $tag_obj['id'] : ( isset( $tag_obj['_id'] ) ? (int) $tag_obj['_id'] : null );
						if ( $id !== null && $id > 0 ) {
							$name = $tag_obj['name'] ?? $tag_obj['label'] ?? $tag_obj['title'] ?? '';
							if ( is_string( $name ) && trim( $name ) !== '' ) {
								$tags_map[ $id ] = trim( $name );
							} elseif ( ! isset( $tags_map[ $id ] ) ) {
								$tags_map[ $id ] = sprintf( __( 'Tag %d', 'd4h-incidents' ), $id );
							}
						}
					} elseif ( is_numeric( $item ) && (int) $item > 0 && ! isset( $tags_map[ (int) $item ] ) ) {
						$tags_map[ (int) $item ] = sprintf( __( 'Tag %d', 'd4h-incidents' ), (int) $item );
					}
				}
			}
			$tag_ids = $incident['tagIds'] ?? $incident['tag_ids'] ?? array();
			if ( is_array( $tag_ids ) ) {
				foreach ( $tag_ids as $tid ) {
					if ( is_numeric( $tid ) && (int) $tid > 0 && ! isset( $tags_map[ (int) $tid ] ) ) {
						$tags_map[ (int) $tid ] = sprintf( __( 'Tag %d', 'd4h-incidents' ), (int) $tid );
					}
				}
			}
		}
		return $tags_map;
	}

	/**
	 * Process incidents into stats and chart data.
	 *
	 * @param array<int, array<string, mixed>> $incidents
	 * @param API_Client                       $api
	 * @param string                           $context
	 * @param string                           $context_id
	 * @param array<int, array<string, mixed>> $attendance Optional attendance records for unique participants.
	 * @param array<int, string>               $tags_map   Tag id => name for display.
	 * @return array<string, mixed>
	 */
	private function process_incidents( array $incidents, API_Client $api, string $context, string $context_id, array $attendance = array(), array $tags_map = array() ): array {
		$tags_from_incidents = $this->collect_tags_from_incidents( $incidents );
		if ( ! empty( $tags_from_incidents ) ) {
			$tags_map = array_merge( $tags_map, $tags_from_incidents );
		}

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

			$details = $this->extract_incident_details( $incident, $participant_count, $tags_map );
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

		$participants_over_time = $this->compute_participants_over_time( $incidents );
		$stats['participants_over_time'] = $participants_over_time;

		$incident_id_to_date = array();
		foreach ( $incidents as $incident ) {
			$id = isset( $incident['id'] ) ? (int) $incident['id'] : null;
			if ( $id === null ) {
				continue;
			}
			$starts_at = $incident['startsAt'] ?? $incident['starts_at'] ?? '';
			$ts        = is_numeric( $starts_at ) ? (int) $starts_at : strtotime( $starts_at );
			if ( $ts ) {
				$incident_id_to_date[ $id ] = $ts;
			}
		}

		$incident_to_members = array();
		$member_to_incidents = array();
		foreach ( $attendance as $record ) {
			$activity     = $record['activity'] ?? array();
			$member       = $record['member'] ?? array();
			$incident_id  = is_array( $activity ) ? (int) ( $activity['id'] ?? 0 ) : 0;
			$member_id    = is_array( $member ) ? (int) ( $member['id'] ?? 0 ) : 0;
			if ( $incident_id > 0 && $member_id > 0 ) {
				if ( ! isset( $incident_to_members[ $incident_id ] ) ) {
					$incident_to_members[ $incident_id ] = array();
				}
				$incident_to_members[ $incident_id ][ $member_id ] = true;
				if ( ! isset( $member_to_incidents[ $member_id ] ) ) {
					$member_to_incidents[ $member_id ] = array();
				}
				$member_to_incidents[ $member_id ][ $incident_id ] = true;
			}
		}

		$unique_over_time = $this->compute_unique_participants_over_time( $incident_id_to_date, $incident_to_members );
		foreach ( array_keys( $participants_over_time ) as $period ) {
			if ( isset( $unique_over_time[ $period ]['participants'] ) ) {
				$participants_over_time[ $period ]['unique_participants'] = $unique_over_time[ $period ]['participants'];
			}
		}

		$member_incident_count = array();
		foreach ( $member_to_incidents as $member_id => $incidents_attended ) {
			$member_incident_count[ $member_id ] = count( $incidents_attended );
		}
		arsort( $member_incident_count );
		$stats['total_unique_participants'] = count( $member_incident_count );
		$incidents_per_member_labels = array_keys( $member_incident_count );
		$incidents_per_member_data   = array_values( $member_incident_count );

		$all_tags = array();
		foreach ( $tags_map as $tag_id => $tag_name ) {
			$all_tags[] = array( 'id' => $tag_id, 'name' => $tag_name );
		}

		$incidents_per_tag       = $this->compute_incidents_per_tag( $stats['incidents_list'] );
		$incidents_per_tag_over_time = $this->compute_incidents_per_tag_over_time( $stats['incidents_list'] );

		return array(
			'tags_map'                     => $tags_map,
			'all_tags'                     => $all_tags,
			'stats'                        => $stats,
			'incidents_per_tag'            => $incidents_per_tag,
			'incidents_per_tag_over_time'  => $incidents_per_tag_over_time,
			'chart_month_hour'             => $stats['month_hour'],
			'participants_over_time'       => $participants_over_time,
			'incidents_per_member_labels'  => $incidents_per_member_labels,
			'incidents_per_member_data'    => $incidents_per_member_data,
			'raw_incidents'                => $incidents,
		);
	}

	/**
	 * Compute incident count per tag from incidents_list.
	 *
	 * @param array<int, array<string, mixed>> $incidents_list Each item has tag_ids array.
	 * @return array<int, array{id: int, name: string, count: int}>
	 */
	private function compute_incidents_per_tag( array $incidents_list ): array {
		$tag_counts = array();
		$no_tag_name = __( 'No tag', 'd4h-incidents' );
		$no_tag_id   = -1;

		foreach ( $incidents_list as $item ) {
			$tag_ids = $item['tag_ids'] ?? array();
			if ( empty( $tag_ids ) ) {
				$tag_counts[ $no_tag_id ] = ( $tag_counts[ $no_tag_id ] ?? 0 ) + 1;
				continue;
			}
			foreach ( $tag_ids as $tag_id ) {
				$id = is_numeric( $tag_id ) ? (int) $tag_id : 0;
				if ( $id > 0 ) {
					$tag_counts[ $id ] = ( $tag_counts[ $id ] ?? 0 ) + 1;
				}
			}
		}

		if ( isset( $tag_counts[ $no_tag_id ] ) ) {
			$tag_counts[ $no_tag_id ] = $tag_counts[ $no_tag_id ];
		}

		$result = array();
		$tags_map = array();
		foreach ( $incidents_list as $item ) {
			$tag_names = $item['tag_names'] ?? array();
			$tag_ids   = $item['tag_ids'] ?? array();
			foreach ( $tag_ids as $idx => $tid ) {
				$id = is_numeric( $tid ) ? (int) $tid : 0;
				if ( $id > 0 && ! isset( $tags_map[ $id ] ) ) {
					$tags_map[ $id ] = isset( $tag_names[ $idx ] ) ? $tag_names[ $idx ] : (string) $id;
				}
			}
		}
		foreach ( $tag_counts as $tag_id => $count ) {
			$name = $tag_id === $no_tag_id ? $no_tag_name : ( $tags_map[ $tag_id ] ?? (string) $tag_id );
			$result[] = array( 'id' => $tag_id, 'name' => $name, 'count' => (int) $count );
		}
		usort( $result, function ( $a, $b ) {
			return $b['count'] - $a['count'];
		} );
		return $result;
	}

	/**
	 * Compute incidents per tag aggregated by weekly, monthly, yearly periods.
	 *
	 * @param array<int, array<string, mixed>> $incidents_list Each item has tag_ids, tag_names, date (Y-m-d H:i).
	 * @return array{weekly: array{labels: array, tags: array}, monthly: array{labels: array, tags: array}, yearly: array{labels: array, tags: array}}
	 */
	private function compute_incidents_per_tag_over_time( array $incidents_list ): array {
		$periods = array(
			'weekly'  => array( 'labels' => array(), 'keys' => array(), 'tags' => array() ),
			'monthly' => array( 'labels' => array(), 'keys' => array(), 'tags' => array() ),
			'yearly'  => array( 'labels' => array(), 'keys' => array(), 'tags' => array() ),
		);

		foreach ( $incidents_list as $item ) {
			$date_str = $item['date'] ?? '';
			$timestamp = $date_str ? strtotime( $date_str ) : 0;
			if ( ! $timestamp ) {
				continue;
			}
			$tag_ids   = $item['tag_ids'] ?? array();
			$tag_names = $item['tag_names'] ?? array();
			$tag_labels = array();
			foreach ( $tag_ids as $idx => $tid ) {
				$id = is_numeric( $tid ) ? (int) $tid : 0;
				$name = isset( $tag_names[ $idx ] ) ? $tag_names[ $idx ] : (string) $id;
				if ( $id > 0 ) {
					$tag_labels[ $id ] = $name;
				}
			}
			if ( empty( $tag_labels ) ) {
				$tag_labels[ -1 ] = __( 'No tag', 'd4h-incidents' );
			}

			$week_key   = gmdate( 'Y-\WW', $timestamp );
			$week_label = gmdate( 'Y-m-d', strtotime( 'monday this week', $timestamp ) );
			$month_key  = gmdate( 'Y-m', $timestamp );
			$month_label = $month_key;
			$year_key   = gmdate( 'Y', $timestamp );
			$year_label = $year_key;

			foreach ( array( 'weekly' => array( $week_key, $week_label ), 'monthly' => array( $month_key, $month_label ), 'yearly' => array( $year_key, $year_label ) ) as $period => $key_label ) {
				list( $key, $label ) = $key_label;
				if ( ! isset( $periods[ $period ]['keys'][ $key ] ) ) {
					$periods[ $period ]['keys'][ $key ] = count( $periods[ $period ]['labels'] );
					$periods[ $period ]['labels'][]    = $label;
				}
				$idx = $periods[ $period ]['keys'][ $key ];
				foreach ( $tag_labels as $tag_id => $tag_name ) {
					$tag_key = $tag_id . ':' . $tag_name;
					if ( ! isset( $periods[ $period ]['tags'][ $tag_key ] ) ) {
						$periods[ $period ]['tags'][ $tag_key ] = array( 'id' => $tag_id, 'name' => $tag_name, 'data' => array() );
					}
					$tag_data = &$periods[ $period ]['tags'][ $tag_key ]['data'];
					while ( count( $tag_data ) <= $idx ) {
						$tag_data[] = 0;
					}
					$tag_data[ $idx ] = ( $tag_data[ $idx ] ?? 0 ) + 1;
					unset( $tag_data );
				}
			}
		}

		foreach ( array_keys( $periods ) as $period ) {
			$label_count = count( $periods[ $period ]['labels'] );
			foreach ( $periods[ $period ]['tags'] as $tag_key => $tag_data ) {
				$data = $tag_data['data'];
				while ( count( $data ) < $label_count ) {
					$data[] = 0;
				}
				$periods[ $period ]['tags'][ $tag_key ]['data'] = array_slice( $data, 0, $label_count );
			}
			unset( $periods[ $period ]['keys'] );
		}

		return $periods;
	}

	/**
	 * Compute incidents and participants aggregated by weekly, monthly, yearly periods.
	 *
	 * @param array<int, array<string, mixed>> $incidents
	 * @return array{weekly: array{labels: array<int, string>, incidents: array<int, int>, participants: array<int, int>}, monthly: array{labels: array<int, string>, incidents: array<int, int>, participants: array<int, int>}, yearly: array{labels: array<int, string>, incidents: array<int, int>, participants: array<int, int>}}
	 */
	private function compute_participants_over_time( array $incidents ): array {
		$periods = array(
			'weekly'  => array( 'labels' => array(), 'incidents' => array(), 'participants' => array(), 'keys' => array() ),
			'monthly' => array( 'labels' => array(), 'incidents' => array(), 'participants' => array(), 'keys' => array() ),
			'yearly'  => array( 'labels' => array(), 'incidents' => array(), 'participants' => array(), 'keys' => array() ),
		);

		foreach ( $incidents as $incident ) {
			$starts_at       = $incident['startsAt'] ?? $incident['starts_at'] ?? '';
			$timestamp       = is_numeric( $starts_at ) ? (int) $starts_at : strtotime( $starts_at );
			$participant_cnt = (int) ( $incident['countAttendance'] ?? $incident['count_attendance'] ?? 0 );
			if ( ! $timestamp ) {
				continue;
			}

			$week_key   = gmdate( 'Y-\WW', $timestamp );
			$week_label = gmdate( 'Y-m-d', strtotime( 'monday this week', $timestamp ) );
			$month_key  = gmdate( 'Y-m', $timestamp );
			$month_label = gmdate( 'Y-m', $timestamp );
			$year_key   = gmdate( 'Y', $timestamp );
			$year_label = $year_key;

			foreach ( array( 'weekly' => array( $week_key, $week_label ), 'monthly' => array( $month_key, $month_label ), 'yearly' => array( $year_key, $year_label ) ) as $period => $key_label ) {
				list( $key, $label ) = $key_label;
				if ( ! isset( $periods[ $period ]['keys'][ $key ] ) ) {
					$periods[ $period ]['keys'][ $key ]     = count( $periods[ $period ]['labels'] );
					$periods[ $period ]['labels'][]        = $label;
					$periods[ $period ]['incidents'][]     = 0;
					$periods[ $period ]['participants'][]  = 0;
				}
				$idx = $periods[ $period ]['keys'][ $key ];
				$periods[ $period ]['incidents'][ $idx ]++;
				$periods[ $period ]['participants'][ $idx ] += $participant_cnt;
			}
		}

		foreach ( array_keys( $periods ) as $period ) {
			unset( $periods[ $period ]['keys'] );
		}

		return $periods;
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
	 * Extract incident details: title, location, description (HTML stripped), date, duration, participants (count), tags.
	 *
	 * @param array<string, mixed> $incident
	 * @param int                  $participant_count From countAttendance
	 * @param array<int, string>   $tags_map          Tag id => name
	 * @return array{title: string, name: string, location_coords: string, location_url: string, description: string, date: string, duration: string, duration_seconds: int, participants: int, tag_ids: array<int>, tag_names: array<string>}
	 */
	private function extract_incident_details( array $incident, int $participant_count, array $tags_map = array() ): array {
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

		$tag_ids   = array();
		$tag_names = array();
		$raw_tags  = $incident['tags'] ?? $incident['activityTags'] ?? $incident['activity_tags'] ?? array();
		if ( is_array( $raw_tags ) ) {
			foreach ( $raw_tags as $item ) {
				$tag_obj = is_array( $item ) ? ( $item['tag'] ?? $item ) : null;
				if ( ! is_array( $tag_obj ) ) {
					$id = is_numeric( $item ) ? (int) $item : null;
					if ( $id !== null && $id > 0 ) {
						$tag_ids[] = $id;
						$tag_names[] = $tags_map[ $id ] ?? (string) $id;
					}
					continue;
				}
				$id = isset( $tag_obj['id'] ) ? (int) $tag_obj['id'] : ( isset( $tag_obj['_id'] ) ? (int) $tag_obj['_id'] : null );
				if ( $id !== null && $id > 0 ) {
					$tag_ids[] = $id;
					$name     = $tags_map[ $id ] ?? $tag_obj['name'] ?? $tag_obj['label'] ?? $tag_obj['title'] ?? (string) $id;
					$tag_names[] = is_string( $name ) ? trim( $name ) : (string) $id;
				}
			}
		}
		$tag_ids_only = $incident['tagIds'] ?? $incident['tag_ids'] ?? array();
		if ( is_array( $tag_ids_only ) && ! empty( $tag_ids_only ) ) {
			foreach ( $tag_ids_only as $tid ) {
				$id = is_numeric( $tid ) ? (int) $tid : 0;
				if ( $id > 0 && ! in_array( $id, $tag_ids, true ) ) {
					$tag_ids[] = $id;
					$tag_names[] = $tags_map[ $id ] ?? (string) $id;
				}
			}
		}
		$tag_names = array_values( array_unique( $tag_names ) );

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
			'tag_ids'          => $tag_ids,
			'tag_names'        => $tag_names,
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
		$csv_lines[] = array( 'Date', 'Location', 'Title', 'Description', 'Tags', 'Duration', 'Participants' );
		foreach ( $incidents_list as $item ) {
			$participants_count = (int) ( $item['participants'] ?? 0 );
			$tags_str          = implode( ', ', (array) ( $item['tag_names'] ?? array() ) );
			$csv_lines[]       = array(
				$item['date'] ?? '',
				$item['location_coords'] ?? '',
				$item['title'] ?? $item['name'] ?? '',
				$item['description'] ?? '',
				$tags_str,
				$item['duration'] ?? '',
				$participants_count,
			);
		}

		$output   = "\xEF\xBB\xBF" . $this->array_to_csv( $csv_lines );
		$filename = 'd4h-incidents-' . ( $last['from'] ?? 'export' ) . '-to-' . ( $last['to'] ?? 'export' ) . '.csv';

		wp_send_json_success( array(
			'csv'      => $output,
			'filename' => $filename,
		) );
	}

	/**
	 * AJAX handler: Export CSV report filtered by selected tags.
	 */
	public function ajax_export_report_by_tags(): void {
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

		$processed     = $cached['processed'];
		$stats         = $processed['stats'] ?? array();
		$incidents_list = $stats['incidents_list'] ?? array();

		$tag_ids_raw = isset( $_POST['tag_ids'] ) && is_array( $_POST['tag_ids'] ) ? wp_unslash( $_POST['tag_ids'] ) : array();
		$selected_tag_ids = array();
		foreach ( $tag_ids_raw as $id ) {
			$id = is_string( $id ) ? trim( $id ) : (string) $id;
			if ( $id !== '' ) {
				$selected_tag_ids[] = $id;
			}
		}

		if ( ! empty( $selected_tag_ids ) ) {
			$no_tag_value = '__no_tag__';
			$incidents_list = array_filter( $incidents_list, function ( $item ) use ( $selected_tag_ids, $no_tag_value ) {
				$item_tag_ids = $item['tag_ids'] ?? array();
				$has_tags     = ! empty( $item_tag_ids );
				if ( $has_tags ) {
					foreach ( $item_tag_ids as $tid ) {
						if ( in_array( (string) $tid, $selected_tag_ids, true ) ) {
							return true;
						}
					}
					return false;
				}
				return in_array( $no_tag_value, $selected_tag_ids, true );
			} );
		}

		$csv_lines   = array();
		$csv_lines[] = array( 'Date', 'Location', 'Title', 'Description', 'Tags', 'Duration', 'Participants' );
		foreach ( $incidents_list as $item ) {
			$participants_count = (int) ( $item['participants'] ?? 0 );
			$tags_str          = implode( ', ', (array) ( $item['tag_names'] ?? array() ) );
			$csv_lines[]       = array(
				$item['date'] ?? '',
				$item['location_coords'] ?? '',
				$item['title'] ?? $item['name'] ?? '',
				$item['description'] ?? '',
				$tags_str,
				$item['duration'] ?? '',
				$participants_count,
			);
		}

		$output   = "\xEF\xBB\xBF" . $this->array_to_csv( $csv_lines );
		$filename = 'd4h-incidents-report-' . ( $last['from'] ?? 'export' ) . '-to-' . ( $last['to'] ?? 'export' ) . '.csv';

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

			<div id="d4h-incidents-tag-filter" class="d4h-incidents-tag-filter">
				<button type="button" id="d4h-incidents-tag-filter-toggle" class="button-link" aria-expanded="false" aria-controls="d4h-incidents-tag-filter-content">
					<span class="d4h-tag-filter-show"><?php esc_html_e( 'Show filter', 'd4h-incidents' ); ?></span>
					<span class="d4h-tag-filter-hide" style="display:none;"><?php esc_html_e( 'Hide filter', 'd4h-incidents' ); ?></span>
				</button>
				<div id="d4h-incidents-tag-filter-content" style="display:none;" aria-hidden="true">
					<span class="d4h-tag-filter-label"><?php esc_html_e( 'Filter by tags:', 'd4h-incidents' ); ?></span>
					<span class="d4h-tag-filter-hint"><?php esc_html_e( 'Select tags and press Fetch data to filter. Unchecking tags does not change the current data.', 'd4h-incidents' ); ?></span>
					<span class="d4h-tag-filter-actions">
						<button type="button" class="button button-small d4h-tag-select-all"><?php esc_html_e( 'All', 'd4h-incidents' ); ?></button>
						<button type="button" class="button button-small d4h-tag-select-none"><?php esc_html_e( 'None', 'd4h-incidents' ); ?></button>
					</span>
					<div id="d4h-incidents-tag-checkboxes" class="d4h-tag-checkboxes">
					<?php
					$core_tags = array();
					if ( function_exists( 'd4h_core_get_tags_map' ) ) {
						$core_tags = d4h_core_get_tags_map();
					}
					if ( empty( $core_tags ) ) {
						$core_tags = get_option( 'd4h_core_tags_map', array() );
					}
					$core_tags = is_array( $core_tags ) ? $core_tags : array();
					if ( ! empty( $core_tags ) ) {
						$sorted = $core_tags;
						asort( $sorted );
						?>
						<label class="d4h-tag-checkbox-item"><input type="checkbox" class="d4h-tag-filter-cb" data-tag-id="__no_tag__" checked /> <?php esc_html_e( 'No tag', 'd4h-incidents' ); ?></label>
						<?php
						foreach ( $sorted as $tag_id => $tag_name ) {
							?>
							<label class="d4h-tag-checkbox-item"><input type="checkbox" class="d4h-tag-filter-cb" data-tag-id="<?php echo esc_attr( (string) $tag_id ); ?>" checked /> <?php echo esc_html( $tag_name ); ?></label>
							<?php
						}
					} else {
						?>
						<span class="description"><?php esc_html_e( 'No tags yet. Run Update tags in D4H → Settings first.', 'd4h-incidents' ); ?></span>
						<?php
					}
					?>
					</div>
				</div>
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
					<div class="d4h-stat-card">
						<span class="d4h-stat-value" id="d4h-stat-unique-participants">0</span>
						<span class="d4h-stat-label"><?php esc_html_e( 'Unique participants', 'd4h-incidents' ); ?></span>
					</div>
					<div class="d4h-stat-card">
						<span class="d4h-stat-value" id="d4h-stat-duration">0m</span>
						<span class="d4h-stat-label"><?php esc_html_e( 'Total duration', 'd4h-incidents' ); ?></span>
					</div>
					<div class="d4h-stat-card">
						<span class="d4h-stat-value" id="d4h-stat-avg-participants">0</span>
						<span class="d4h-stat-label"><?php esc_html_e( 'Avg participants per incident', 'd4h-incidents' ); ?></span>
					</div>
					<div class="d4h-stat-card">
						<span class="d4h-stat-value" id="d4h-stat-avg-duration">0m</span>
						<span class="d4h-stat-label"><?php esc_html_e( 'Avg duration per incident', 'd4h-incidents' ); ?></span>
					</div>
				</div>

				<div id="d4h-incidents-per-tag-cards" class="d4h-incidents-per-tag-cards" style="display:none;">
					<h3><?php esc_html_e( 'Incidents per tag', 'd4h-incidents' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Number of incidents for each tag.', 'd4h-incidents' ); ?></p>
					<div id="d4h-incidents-per-tag-boxes" class="d4h-incidents-stats-cards"></div>
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
									<th class="d4h-sortable" data-sort="tag_names"><?php esc_html_e( 'Tags', 'd4h-incidents' ); ?></th>
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
						<button type="button" id="d4h-incidents-export-report-tags" class="button button-secondary"><?php esc_html_e( 'Export report (filtered by tags)', 'd4h-incidents' ); ?></button>
					</div>
				</div>

				<div class="d4h-chart-section">
					<h3><?php esc_html_e( 'Incidents and participants by period', 'd4h-incidents' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Incidents (left) and participants (right) per time period.', 'd4h-incidents' ); ?></p>
					<div class="d4h-chart-controls">
						<label for="d4h-participants-period"><?php esc_html_e( 'Period:', 'd4h-incidents' ); ?></label>
						<select id="d4h-participants-period">
							<option value="weekly"><?php esc_html_e( 'Weekly', 'd4h-incidents' ); ?></option>
							<option value="monthly"><?php esc_html_e( 'Monthly', 'd4h-incidents' ); ?></option>
							<option value="yearly"><?php esc_html_e( 'Yearly', 'd4h-incidents' ); ?></option>
						</select>
					</div>
					<div class="d4h-charts-row">
						<div class="d4h-chart-cell">
							<h4 class="d4h-chart-subtitle"><?php esc_html_e( 'Incidents', 'd4h-incidents' ); ?></h4>
							<div class="d4h-chart-container">
								<canvas id="d4h-chart-incidents-by-period"></canvas>
							</div>
							<div class="d4h-chart-export-buttons">
								<button type="button" class="button d4h-export-png" data-chart="incidents-by-period"><?php esc_html_e( 'Export PNG', 'd4h-incidents' ); ?></button>
								<button type="button" class="button d4h-export-csv" data-chart="incidents-by-period"><?php esc_html_e( 'Export CSV', 'd4h-incidents' ); ?></button>
							</div>
						</div>
						<div class="d4h-chart-cell">
							<h4 class="d4h-chart-subtitle"><?php esc_html_e( 'Participants', 'd4h-incidents' ); ?></h4>
							<div class="d4h-chart-container">
								<canvas id="d4h-chart-participants"></canvas>
							</div>
							<div class="d4h-chart-export-buttons">
								<button type="button" class="button d4h-export-png" data-chart="participants"><?php esc_html_e( 'Export PNG', 'd4h-incidents' ); ?></button>
								<button type="button" class="button d4h-export-csv" data-chart="participants"><?php esc_html_e( 'Export CSV', 'd4h-incidents' ); ?></button>
							</div>
						</div>
					</div>
				</div>

				<div class="d4h-chart-section">
					<h3><?php esc_html_e( 'Incidents per tag by period', 'd4h-incidents' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Incidents per tag over time. Uses the same period selector above (weekly/monthly/yearly).', 'd4h-incidents' ); ?></p>
					<div class="d4h-chart-container d4h-chart-container--wide">
						<canvas id="d4h-chart-incidents-per-tag-by-period"></canvas>
						<div class="d4h-chart-export-buttons">
							<button type="button" class="button d4h-export-png" data-chart="incidents-per-tag-by-period"><?php esc_html_e( 'Export PNG', 'd4h-incidents' ); ?></button>
							<button type="button" class="button d4h-export-csv" data-chart="incidents-per-tag-by-period"><?php esc_html_e( 'Export CSV', 'd4h-incidents' ); ?></button>
						</div>
					</div>
				</div>

				<div class="d4h-chart-section">
					<h3><?php esc_html_e( 'Incidents per member', 'd4h-incidents' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Number of incidents each member attended.', 'd4h-incidents' ); ?></p>
					<div class="d4h-chart-controls">
						<label for="d4h-incidents-per-member-limit"><?php esc_html_e( 'Show top:', 'd4h-incidents' ); ?></label>
						<select id="d4h-incidents-per-member-limit">
							<option value="30" selected>30</option>
							<option value="50">50</option>
							<option value="100">100</option>
							<option value="200">200</option>
							<option value="500">500</option>
							<option value="0"><?php esc_html_e( 'All', 'd4h-incidents' ); ?></option>
						</select>
						<span class="d4h-chart-controls-divider">|</span>
						<button type="button" id="d4h-incidents-per-member-show-names" class="button button-secondary"><?php esc_html_e( 'Show names', 'd4h-incidents' ); ?></button>
						<span id="d4h-member-names-loading" class="d4h-attendance-loading" style="display:none;"><?php esc_html_e( 'Loading names...', 'd4h-incidents' ); ?></span>
					</div>
					<div class="d4h-chart-container">
						<canvas id="d4h-chart-incidents-per-member"></canvas>
						<div class="d4h-chart-export-buttons">
							<button type="button" class="button d4h-export-png" data-chart="incidents-per-member"><?php esc_html_e( 'Export PNG', 'd4h-incidents' ); ?></button>
							<button type="button" class="button d4h-export-csv" data-chart="incidents-per-member"><?php esc_html_e( 'Export CSV', 'd4h-incidents' ); ?></button>
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
