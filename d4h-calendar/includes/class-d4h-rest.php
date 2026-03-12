<?php
/**
 * REST API: activities endpoint for FullCalendar event source.
 *
 * @package D4H_Calendar
 */

namespace D4H_Calendar;

defined( 'ABSPATH' ) || exit;

final class REST {

	/** @var array<string, mixed> */
	private $config;

	/** @var Repository */
	private $repository;

	/**
	 * @param array<string, mixed> $config
	 * @param Repository           $repository
	 */
	public function __construct( array $config, Repository $repository ) {
		$this->config    = $config;
		$this->repository = $repository;
	}

	public function register_routes(): void {
		$namespace        = $this->config['rest_namespace'] ?? 'd4h-calendar/v1';
		$activities_route = $this->config['rest_activities_route'] ?? 'activities';

		register_rest_route( $namespace, '/' . $activities_route, array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_activities' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'from'  => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => array( $this, 'validate_date_param' ),
				),
				'to'    => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => array( $this, 'validate_date_param' ),
				),
				'start' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => array( $this, 'validate_date_param' ),
				),
				'end'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => array( $this, 'validate_date_param' ),
				),
			),
		) );
	}

	/**
	 * REST callback: return activities as FullCalendar event objects.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_activities( \WP_REST_Request $request ): \WP_REST_Response {
		$from  = $request->get_param( 'from' ) ?: $request->get_param( 'start' );
		$to    = $request->get_param( 'to' ) ?: $request->get_param( 'end' );

		$days  = (int) ( $this->config['calendar_date_range_days'] ?? 90 ) / 2;
		$now   = current_time( 'timestamp' );

		if ( empty( $from ) ) {
			$from = gmdate( 'Y-m-d', strtotime( "-{$days} days", $now ) );
		}
		if ( empty( $to ) ) {
			$to = gmdate( 'Y-m-d', strtotime( "+{$days} days", $now ) );
		}

		$from = $this->parse_date( $from );
		$to   = $this->parse_date( $to );
		if ( $from === '' || $to === '' ) {
			return new \WP_REST_Response( array(), 200 );
		}

		$max_days = (int) ( $this->config['calendar_date_range_days'] ?? 90 );
		$diff     = abs( strtotime( $to ) - strtotime( $from ) );
		if ( $diff > ( $max_days * DAY_IN_SECONDS ) ) {
			return new \WP_REST_Response( array(), 200 );
		}

		$activities = $this->repository->get_activities( $from, $to );
		$events     = $this->to_fullcalendar_events( $activities );

		return new \WP_REST_Response( $events, 200 );
	}

	/**
	 * Validate date param for REST. Empty is allowed; non-empty must be parseable.
	 *
	 * @param mixed $param
	 * @param \WP_REST_Request $request
	 * @param string $key
	 * @return true|\WP_Error
	 */
	public function validate_date_param( $param, $request, $key ) {
		if ( $param === null || $param === '' ) {
			return true;
		}
		$timestamp = strtotime( (string) $param );
		if ( ! $timestamp ) {
			return new \WP_Error( 'invalid_date', __( 'Invalid date format.', 'd4h-calendar' ), array( 'status' => 400 ) );
		}
		return true;
	}

	private function parse_date( string $val ): string {
		$val = trim( $val );
		if ( $val === '' ) {
			return '';
		}
		$timestamp = strtotime( $val );
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : '';
	}

	/**
	 * Map repository activities to FullCalendar event format.
	 *
	 * @param array<int, array<string, mixed>> $activities
	 * @return array<int, array{id: string, title: string, start: string, end?: string, color?: string, extendedProps: array{resourceType: string}}>
	 */
	private function to_fullcalendar_events( array $activities ): array {
		$option_event    = $this->config['option_event_color'] ?? 'd4h_calendar_event_color';
		$option_exercise = $this->config['option_exercise_color'] ?? 'd4h_calendar_exercise_color';
		$option_tag_colors = $this->config['option_tag_colors'] ?? 'd4h_calendar_tag_colors';

		$event_color    = get_option( $option_event, '' );
		$exercise_color = get_option( $option_exercise, '' );
		if ( $event_color === '' ) {
			$event_color = $this->config['calendar_event_color'] ?? '#3788d8';
		}
		if ( $exercise_color === '' ) {
			$exercise_color = $this->config['calendar_exercise_color'] ?? '#6c757d';
		}

		$option_tag_priority = $this->config['option_tag_priority'] ?? 'd4h_calendar_tag_priority';
		$tag_colors_raw      = get_option( $option_tag_colors, array() );
		$tag_colors          = is_array( $tag_colors_raw ) ? $tag_colors_raw : array();
		$tag_priority_raw    = get_option( $option_tag_priority, array() );
		$tag_priority        = is_array( $tag_priority_raw ) ? $tag_priority_raw : array();

		$events = array();

		$option_show_description = $this->config['option_show_description'] ?? 'd4h_calendar_show_description';
		$show_description_raw    = get_option( $option_show_description, 1 );
		$include_description     = (int) $show_description_raw === 1;
		foreach ( $activities as $activity ) {
			$type  = $activity['resource_type'] ?? 'event';
			$title = $this->get_title( $activity );
			$start = $activity['starts_at'] ?? '';
			$end   = $activity['ends_at'] ?? null;

			if ( $start === '' ) {
				continue;
			}

			$payload   = $activity['payload'] ?? array();
			$tags_map  = get_option( $this->config['option_tags_map'] ?? 'd4h_calendar_tags_map', array() );
			$tags      = $this->extract_tags( $payload, is_array( $tags_map ) ? $tags_map : array() );

			// Sort tags by admin-defined priority so first matching tag wins.
			if ( ! empty( $tag_priority ) ) {
				$priority_lookup = array_flip( array_values( $tag_priority ) );
				usort( $tags, function ( $tag_a, $tag_b ) use ( $priority_lookup ) {
					$index_a = isset( $priority_lookup[ $tag_a ] ) ? $priority_lookup[ $tag_a ] : 9999;
					$index_b = isset( $priority_lookup[ $tag_b ] ) ? $priority_lookup[ $tag_b ] : 9999;
					return $index_a - $index_b;
				} );
			}

			// Tag color has priority; first matching tag wins.
			$color = null;
			foreach ( $tags as $tag_name ) {
				$tag_color = isset( $tag_colors[ $tag_name ] ) && is_string( $tag_colors[ $tag_name ] ) ? trim( $tag_colors[ $tag_name ] ) : '';
				if ( $tag_color !== '' && preg_match( '/^#[0-9a-fA-F]{6}$/', $tag_color ) ) {
					$color = $tag_color;
					break;
				}
			}
			if ( $color === null ) {
				$color = ( $type === 'exercise' ) ? $exercise_color : $event_color;
			}

			$desc      = isset( $payload['description'] ) ? (string) $payload['description'] : '';
			$ref       = isset( $payload['reference'] ) ? (string) $payload['reference'] : '';
			$ref_desc  = isset( $payload['referenceDescription'] ) ? (string) $payload['referenceDescription'] : '';

			if ( ! $include_description ) {
				$desc     = '';
				$ref_desc = '';
			}

			$event = array(
				'id'            => sanitize_key( (string) ( $activity['id'] ?? '' ) ) . '-' . $type,
				'title'         => $title,
				'start'         => $start,
				'color'         => $color,
				'extendedProps' => array(
					'resourceType'        => $type,
					'description'         => $desc,
					'reference'           => $ref,
					'referenceDescription'=> $ref_desc,
					'tags'                => $tags,
				),
			);
			if ( $end !== null && $end !== '' ) {
				$event['end'] = $end;
			}
			$events[] = $event;
		}
		return $events;
	}

	/**
	 * Extract tag names from API payload. Supports:
	 * - tags: [{id, resourceType}] – resolve id via $tags_map (D4H default)
	 * - tags: [{name}, ...] or [string, ...]
	 * - activityTags: [{tag: {name}}, ...] or [{name}, ...]
	 *
	 * @param array<string, mixed> $payload
	 * @param array<int, string>   $tags_map id => name
	 * @return array<int, string>
	 */
	private function extract_tags( array $payload, array $tags_map = array() ): array {
		$raw = $payload['tags'] ?? $payload['activityTags'] ?? array();
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$names = array();
		foreach ( $raw as $item ) {
			if ( is_string( $item ) && trim( $item ) !== '' ) {
				$names[] = trim( $item );
			} elseif ( is_array( $item ) ) {
				$tag_obj = $item['tag'] ?? $item;
				$name   = $tag_obj['name'] ?? $tag_obj['label'] ?? $tag_obj['title'] ?? '';
				if ( is_string( $name ) && trim( $name ) !== '' ) {
					$names[] = trim( $name );
				} elseif ( isset( $tag_obj['id'] ) && ! empty( $tags_map ) ) {
					$resolved = $tags_map[ (int) $tag_obj['id'] ] ?? null;
					if ( is_string( $resolved ) && trim( $resolved ) !== '' ) {
						$names[] = trim( $resolved );
					}
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	private function get_title( array $activity ): string {
		$payload  = $activity['payload'] ?? array();
		$ref      = isset( $payload['reference'] ) && (string) $payload['reference'] !== ''
			? (string) $payload['reference']
			: '';
		$ref_desc = isset( $payload['referenceDescription'] ) && (string) $payload['referenceDescription'] !== ''
			? (string) $payload['referenceDescription']
			: '';

		$base = '';
		if ( $ref !== '' ) {
			$base = $ref;
		} elseif ( $ref_desc !== '' ) {
			$base = $ref_desc;
		} elseif ( isset( $payload['description'] ) && (string) $payload['description'] !== '' ) {
			$base = wp_trim_words( (string) $payload['description'], 8 );
		} else {
			return $activity['resource_type'] === 'exercise'
				? __( 'Exercise', 'd4h-calendar' )
				: __( 'Event', 'd4h-calendar' );
		}

		// Append referenceDescription when different from base (e.g. reference).
		if ( $ref_desc !== '' && $ref_desc !== $base ) {
			$base = $base . ' – ' . $ref_desc;
		}
		return esc_html( $base );
	}
}
