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
		$event_color    = $this->config['calendar_event_color'] ?? '#3788d8';
		$exercise_color = $this->config['calendar_exercise_color'] ?? '#6c757d';

		$events = array();
		foreach ( $activities as $activity ) {
			$type  = $activity['resource_type'] ?? 'event';
			$title = $this->get_title( $activity );
			$start = $activity['starts_at'] ?? '';
			$end   = $activity['ends_at'] ?? null;

			if ( $start === '' ) {
				continue;
			}

			$color = ( $type === 'exercise' ) ? $exercise_color : $event_color;

			$payload   = $activity['payload'] ?? array();
			$desc      = isset( $payload['description'] ) ? (string) $payload['description'] : '';
			$ref       = isset( $payload['reference'] ) ? (string) $payload['reference'] : '';
			$ref_desc  = isset( $payload['referenceDescription'] ) ? (string) $payload['referenceDescription'] : '';

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
				),
			);
			if ( $end !== null && $end !== '' ) {
				$event['end'] = $end;
			}
			$events[] = $event;
		}
		return $events;
	}

	private function get_title( array $activity ): string {
		$payload = $activity['payload'] ?? array();
		$raw     = '';
		if ( isset( $payload['reference'] ) && (string) $payload['reference'] !== '' ) {
			$raw = (string) $payload['reference'];
		} elseif ( isset( $payload['referenceDescription'] ) && (string) $payload['referenceDescription'] !== '' ) {
			$raw = (string) $payload['referenceDescription'];
		} elseif ( isset( $payload['description'] ) && (string) $payload['description'] !== '' ) {
			$raw = wp_trim_words( (string) $payload['description'], 8 );
		} else {
			return $activity['resource_type'] === 'exercise'
				? __( 'Exercise', 'd4h-calendar' )
				: __( 'Event', 'd4h-calendar' );
		}
		return esc_html( $raw );
	}
}
